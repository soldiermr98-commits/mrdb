<?php
/**
 * کمکی فعال‌سازی و تمدید اشتراک هنرجو (Membership Grant/Extend Helper)
 *
 * LOGIC FIX (BUG-04): قبلاً در هر پرداخت موفق همیشه یک رکورد عضویت «جدید» درج می‌شد.
 * نتیجه: هنرجویی که اشتراک فعال داشت و تمدید می‌کرد، به‌جای تمدید، دو رکورد موازی
 * می‌گرفت و مابقی روزهای اشتراک قبلی هدر می‌رفت. اکنون اگر اشتراک فعال و هم‌سطح/هم‌پلن
 * وجود داشته باشد، `expires_at` از همان انقضای قبلی به بعد تمدید می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Membership_Helper {

    /**
     * فعال‌سازی یا تمدید اشتراک کاربر پس از پرداخت تاییدشده.
     *
     * LOGIC FIX (BUG-14): قبلاً اگر $plan نال بود (مثلاً پلن حذف شده بود)،
     * تابع بی‌صدا یک اشتراک «برنزی ۳۰ روزه» پیش‌فرض می‌ساخت — یعنی کاربری که
     * اشتراک طلایی خریده بود، اشتراک برنزی می‌گرفت و پول تفاوت از بین می‌رفت.
     * اکنون با پلن نامعتبر هیچ فعال‌سازی‌ای انجام نمی‌شود و false برگردانده می‌شود
     * تا فراخواننده سفارش را در وضعیت بررسی دستی نگه دارد.
     *
     * @param int    $user_id  شناسه کاربر
     * @param object $plan     رکورد پلن (شامل tier و duration_days)
     * @param string $order_id شناسه سفارش مبدأ
     * @return array|false اطلاعات رکورد نهایی عضویت یا false در صورت پلن نامعتبر
     */
    public static function grant_or_extend($user_id, $plan, $order_id) {
        global $wpdb;

        if (!$plan || empty($plan->tier) || empty($plan->duration_days) || (int) $plan->duration_days < 1) {
            error_log(sprintf(
                'MrDastbaaf: grant_or_extend() aborted — plan missing/invalid for user #%d (order: %s)',
                (int) $user_id,
                (string) $order_id
            ));
            return false;
        }

        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';

        /**
         * RACE FIX (BUG-32): الگوی check-then-write (خواندن آخرین اشتراک → تصمیم →
         * نوشتن) در درخواست‌های هم‌زمان (دو پرداخت موفق هم‌زمان) می‌توانست روزهای
         * پرداخت‌شده را گم کند یا رکوردهای فعال موازی بسازد. کل محدوده
         * خواندن-تصمیم-نوشتن با قفل مشورتی MySQL به ازای هر کاربر ایزوله می‌شود.
         */
        $lock_name = 'mrd_member_' . (int) $user_id;
        $lock_acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock_name, 5));
        if ('1' !== (string) $lock_acquired) {
            error_log(sprintf(
                'MrDastbaaf: grant_or_extend() could not acquire lock "%s" within 5s — aborted for user #%d (order: %s)',
                $lock_name,
                (int) $user_id,
                (string) $order_id
            ));
            return false;
        }

        $now_ts = current_time('timestamp');
        $now = date('Y-m-d H:i:s', $now_ts);
        $duration_days = (int) $plan->duration_days;
        $tier = $plan->tier;
        $result = false;

        // آخرین اشتراک فعال کاربر
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_memberships
             WHERE user_id = %d AND status = 'active' AND expires_at > %s
             ORDER BY expires_at DESC LIMIT 1",
            $user_id, $now
        ));

        if ($existing) {
            // تمدید: پایه محاسبه، انقضای فعلی است (روزهای باقی‌مانده حفظ می‌شود)
            $base_ts = strtotime($existing->expires_at);
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_days} days", $base_ts));

            $updated = $wpdb->update(
                $table_memberships,
                array(
                    'plan_id'      => $plan->id,
                    'tier'         => $tier,
                    'expires_at'   => $expires_at,
                    'last_order_id'=> $order_id,
                ),
                array('id' => $existing->id)
            );

            if (false === $updated) {
                error_log('MrDastbaaf: membership extend failed (DB error) for user #' . (int) $user_id);
                $result = false;
            } else {
                $result = array(
                    'id'         => $existing->id,
                    'mode'       => 'extended', // تمدید شد (بدون ساخت رکورد تکراری)
                    'expires_at' => $expires_at,
                );
            }
        } else {
            // فعال‌سازی جدید
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_days} days", $now_ts));
            $membership_id = 'mem_' . wp_generate_password(12, false);

            $inserted = $wpdb->insert(
                $table_memberships,
                array(
                    'id'           => $membership_id,
                    'user_id'      => $user_id,
                    'plan_id'      => $plan->id,
                    'tier'         => $tier,
                    'status'       => 'active',
                    'started_at'   => $now,
                    'expires_at'   => $expires_at,
                    'last_order_id'=> $order_id,
                    'auto_renew'   => 0,
                )
            );

            if (false === $inserted) {
                error_log('MrDastbaaf: membership insert failed (DB error) for user #' . (int) $user_id);
                $result = false;
            } else {
                $result = array(
                    'id'         => $membership_id,
                    'mode'       => 'created', // اشتراک جدید ساخته شد
                    'expires_at' => $expires_at,
                );
            }
        }

        // BUG-32: آزادسازی قفل در تمام مسیرها (موفق/ناموفق)
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));

        return $result;
    }
}
