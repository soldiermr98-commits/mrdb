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
     * @param int    $user_id  شناسه کاربر
     * @param object $plan     رکورد پلن (شامل tier و duration_days)
     * @param string $order_id شناسه سفارش مبدأ
     * @return array اطلاعات رکورد نهایی عضویت
     */
    public static function grant_or_extend($user_id, $plan, $order_id) {
        global $wpdb;

        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';
        $now_ts = current_time('timestamp');
        $now = date('Y-m-d H:i:s', $now_ts);
        $duration_days = ($plan && isset($plan->duration_days)) ? (int) $plan->duration_days : 30;
        $tier = ($plan && isset($plan->tier)) ? $plan->tier : 'bronze';

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

            $wpdb->update(
                $table_memberships,
                array(
                    'plan_id'      => $plan ? $plan->id : $existing->plan_id,
                    'tier'         => $tier,
                    'expires_at'   => $expires_at,
                    'last_order_id'=> $order_id,
                ),
                array('id' => $existing->id)
            );

            return array(
                'id'         => $existing->id,
                'mode'       => 'extended', // تمدید شد (بدون ساخت رکورد تکراری)
                'expires_at' => $expires_at,
            );
        }

        // فعال‌سازی جدید
        $expires_at = date('Y-m-d H:i:s', strtotime("+{$duration_days} days", $now_ts));
        $membership_id = 'mem_' . wp_generate_password(12, false);

        $wpdb->insert(
            $table_memberships,
            array(
                'id'           => $membership_id,
                'user_id'      => $user_id,
                'plan_id'      => $plan ? $plan->id : '',
                'tier'         => $tier,
                'status'       => 'active',
                'started_at'   => $now,
                'expires_at'   => $expires_at,
                'last_order_id'=> $order_id,
                'auto_renew'   => 0,
            )
        );

        return array(
            'id'         => $membership_id,
            'mode'       => 'created', // اشتراک جدید ساخته شد
            'expires_at' => $expires_at,
        );
    }
}
