<?php
/**
 * کمکی فعال‌سازی و تمدید اشتراک هنرجو (Membership Grant/Extend Helper)
 *
 * LOGIC FIX (BUG-04): قبلاً در هر پرداخت موفق همیشه یک رکورد عضویت «جدید» درج می‌شد.
 * نتیجه: هنرجویی که اشتراک فعال داشت و تمدید می‌کرد، به‌جای تمدید، دو رکورد موازی
 * می‌گرفت و مابقی روزهای اشتراک قبلی هدر می‌رفت. اکنون اگر اشتراک فعال و هم‌سطح/هم‌پلن
 * وجود داشته باشد، `expires_at` از همان انقضای قبلی به بعد تمدید می‌شود.
 *
 * v1.2.0 (یافتهٔ ۱-۳): ایدمپوتنسی دفتر grant — هر سفارش حداکثر یک‌بار حق فعال‌سازی
 * دارد (جدول mrdastbaaf_membership_grants با UNIQUE(order_id)).
 *
 * v1.2.0 (یافتهٔ ۱-۴): منطق tier-aware —
 *   هم‌سطح  → تمدید از انقضا (رفتار قبلی) با mode 'extended'
 *   بالاتر → اشتراک «جدید» از «الان» با تبدیل ارزش روزهای باقی‌مانده پلن قدیم به
 *            روزِ پلن جدید (بر اساس قیمت/روز هر پلن) + روزهای بونوس، mode 'upgraded'
 *   پایین‌تر → رد بدون نوشتن در DB با mode 'blocked_lower_tier' (پیام 409 در فراخوانی)
 *
 * تصمیم کسب‌وکار (ثبت‌شده برای PR): در ارتقا، تمدید از انقضا با tier جدید به این
 * معنا بود که کاربر طلاییِ دارای ۶ ماه باقی‌مانده، با خرید برنز ۶ ماهِ روزِ برنزِ
 * «اضافه» روی انقضای طلایی می‌گرفت (دوبرابر شدن ناعادلانه ارزش). راه‌حل: اشتراک
 * قدیمی به status='upgraded' بسته می‌شود و ارزش روزهای باقی‌مانده (قیمت روزانهٔ
 * پلن قدیم × روز باقی‌مانده) به‌صورت روزِ پلن جدید (قیمت روزانهٔ پلن جدید) به
 * انتهای اشتراک جدید اضافه می‌شود — ارزش پول کاربر حفظ ولی تورم روزهای مجازی حذف
 * می‌شود. خرید سطح پایین‌تر هم مسدود است (رفع خطر بازنویسی بی‌صدای tier فعال).
 *
 * نکتهٔ سمت کلاینت: grantOrExtend سرویس عضویت در دموی PWA عمداً بدون تغییر مانده
 * (فقط دمو است و از سرور پیروی نمی‌کند).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Membership_Helper {

    /**
     * رتبهٔ عددی سطوح اشتراک — فقط برای مقایسهٔ سلسله‌مراتب (طلایی > نقره‌ای > برنزی > رایگان)
     *
     * @param string $tier شناسهٔ سطح
     * @return int رتبهٔ عددی (سطح ناشناخته = ۰)
     */
    public static function tier_rank($tier) {
        $ranks = array(
            'free'   => 0,
            'bronze' => 1,
            'silver' => 2,
            'gold'   => 3,
        );
        $tier = (string) $tier;
        return isset($ranks[$tier]) ? $ranks[$tier] : 0;
    }

    /**
     * نرخ روزانهٔ فال‌بک سطوح (تومان/روز) — تنها وقتی رکورد پلن قدیم در جدول
     * plans یافت نشود استفاده می‌شود (تقریب بر اساس پلن‌های seed اولیه).
     *
     * @param string $tier شناسهٔ سطح
     * @return int نرخ روزانه
     */
    private static function tier_daily_fallback($tier) {
        $rates = array(
            'free'   => 0,
            'bronze' => 3300,   // 99000 / 30
            'silver' => 2766,   // 249000 / 90
            'gold'   => 1890,   // 690000 / 365
        );
        $tier = (string) $tier;
        return isset($rates[$tier]) ? $rates[$tier] : 0;
    }

    /**
     * v1.2.0: تبدیل ارزش روزهای باقی‌ماندهٔ پلن قدیم به روزِ پلن جدید — تابع خالص
     * (بدون فراخوانی وردپرس/DB) تا در تست مستقل قابل اجرا باشد (tests/php/test-tier-math.php).
     *
     * ارزش = روزهای باقی‌مانده × (قیمت پلن قدیم / مدت پلن قدیم)
     * بونوس = floor(ارزش / (قیمت پلن جدید / مدت پلن جدید))
     *
     * @param int $remaining_days روزهای باقی‌ماندهٔ اشتراک فعال
     * @param int $old_price      قیمت پلن قدیم (تومان)
     * @param int $old_duration   مدت پلن قدیم (روز)
     * @param int $new_price      قیمت پلن جدید (تومان)
     * @param int $new_duration   مدت پلن جدید (روز)
     * @return int روزهای بونوس (>= ۰)
     */
    public static function convert_remaining_days($remaining_days, $old_price, $old_duration, $new_price, $new_duration) {
        $remaining_days = max(0, (int) $remaining_days);
        if ($remaining_days === 0) {
            return 0;
        }

        $old_daily = (int) $old_price / max(1, (int) $old_duration);
        $new_daily = (int) $new_price / max(1, (int) $new_duration);

        if ($new_daily <= 0) {
            return 0;
        }

        $value_toman = $remaining_days * $old_daily;
        return (int) floor($value_toman / $new_daily);
    }

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
     * @return array|false اطلاعات رکورد نهایی عضویت (mode: created|extended|upgraded|
     *                     blocked_lower_tier|idempotent-replay) یا false در خطا
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
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $table_grants = $wpdb->prefix . 'mrdastbaaf_membership_grants';

        // ─── ایدمپوتنسی دفتر grant (پیش از قفل) ───
        // اگر این سفارش قبلاً grant شده، هیچ نوشتنی تکرار نمی‌شود؛ فراخوانی فقط
        // mode/expires_at ثبت‌شده را می‌خواند (idempotent replay).
        $existing_grant = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_grants WHERE order_id = %s",
            $order_id
        ));
        if ($existing_grant) {
            error_log('MrDastbaaf: grant ledger hit for order ' . (string) $order_id . ' — idempotent replay, no DB write');
            return array(
                'id'         => null,
                'mode'       => (string) $existing_grant->mode,
                'expires_at' => (string) $existing_grant->expires_at,
                'idempotent' => true,
            );
        }

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

        if ($existing && self::tier_rank($tier) < self::tier_rank($existing->tier)) {
            // ─── سطح پایین‌تر: رد بدون هیچ نوشتنی در DB ───
            // (یافتهٔ ۱-۴: قبلاً tier رکورد فعال بی‌صدا به سطح پایین‌تر بازنویسی می‌شد)
            $result = array(
                'id'         => null,
                'mode'       => 'blocked_lower_tier',
                'message'    => 'اشتراک فعال شما سطح بالاتری دارد؛ برای خرید سطح پایین‌تر ابتدا منتظر پایان اشتراک فعلی بمانید یا با پشتیبانی تماس بگیرید.',
                'expires_at' => (string) $existing->expires_at,
            );
        } elseif ($existing && self::tier_rank($tier) > self::tier_rank($existing->tier)) {
            // ─── سطح بالاتر: اشتراک جدید از «الان» + تبدیل ارزش روزهای باقی‌مانده ───
            $remaining_days = max(0, (int) ceil((strtotime($existing->expires_at) - $now_ts) / DAY_IN_SECONDS));

            $old_plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $existing->plan_id));
            if ($old_plan && (int) $old_plan->price_toman > 0 && (int) $old_plan->duration_days > 0) {
                $bonus_days = self::convert_remaining_days(
                    $remaining_days,
                    (int) $old_plan->price_toman,
                    (int) $old_plan->duration_days,
                    (int) $plan->price_toman,
                    $duration_days
                );
            } else {
                // پلن قدیم در جدول plans یافت نشد — نرخ روزانهٔ فال‌بک بر اساس tier قدیم
                // (old_duration=1 و old_price=نرخ روزانه ⇒ همان نرخ در تبدیل خالص اعمال می‌شود)
                $fallback_daily = self::tier_daily_fallback($existing->tier);
                $bonus_days = self::convert_remaining_days(
                    $remaining_days,
                    $fallback_daily,
                    1,
                    (int) $plan->price_toman,
                    $duration_days
                );
            }

            // بستن اشتراک فعال قدیمی با status='upgraded' — UPDATE مشروط به active
            $upgraded = $wpdb->update(
                $table_memberships,
                array(
                    'status'       => 'upgraded',
                    'last_order_id'=> $order_id,
                ),
                array('id' => $existing->id, 'status' => 'active')
            );
            if (false === $upgraded || 0 === $upgraded) {
                // false = خطای DB؛ 0 = رکورد هم‌زمان تغییر کرده — در هر دو حالت
                // ادامه دادن یعنی ساخت اشتراک فعال موازی؛ عملیات abort می‌شود.
                error_log('MrDastbaaf: membership upgrade aborted — old row update failed for user #' . (int) $user_id . ' (order: ' . (string) $order_id . ')');
                $result = false;
            } else {
                $expires_at = date('Y-m-d H:i:s', strtotime('+' . ($duration_days + $bonus_days) . ' days', $now_ts));
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
                    error_log('MrDastbaaf: membership upgrade insert failed (DB error) for user #' . (int) $user_id . ' — ' . $wpdb->last_error);
                    $result = false;
                } else {
                    $result = array(
                        'id'         => $membership_id,
                        'mode'       => 'upgraded', // ارتقا: اشتراک جدید از الان + روز بونوس
                        'expires_at' => $expires_at,
                        'bonus_days' => $bonus_days,
                    );
                }
            }
        } elseif ($existing) {
            // ─── هم‌سطح: تمدید — پایه محاسبه، انقضای فعلی است (روزهای باقی‌مانده حفظ می‌شود)
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
            // ─── فعال‌سازی جدید ───
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

        // ─── ثبت در دفتر grant (درون قفل، فقط برای grant موفق) ───
        // UNIQUE(order_id) تضمین ایدمپوتنسی است؛ در برخورد (رقابت دو فراخوانی هم‌زمان
        // از کال‌بک + کرون) نتیجهٔ رکورد موجود برگردانده می‌شود. شکست غیرتکراری DB:
        // عضویت واقعاً نوشته شده و بازگشت false یعنی تلاش مجدد ⇒ تمدید دوبرابر؛
        // پس نتیجهٔ موفق نگه داشته می‌شود و گپ دفتر با error_log برای ادمین ثبت می‌شود.
        if (is_array($result) && 'blocked_lower_tier' !== $result['mode']) {
            $grant_inserted = $wpdb->insert(
                $table_grants,
                array(
                    'order_id'   => $order_id,
                    'user_id'    => (int) $user_id,
                    'plan_id'    => $plan->id,
                    'tier'       => $tier,
                    'mode'       => $result['mode'],
                    'expires_at' => $result['expires_at'],
                )
            );
            if (false === $grant_inserted) {
                $last_error = (string) $wpdb->last_error;
                if (strpos($last_error, 'Duplicate entry') !== false) {
                    // رقابت: فراخوانی دیگر رکورد را ثبت کرده — نتیجهٔ همان رکورد برگردانده می‌شود
                    error_log('MrDastbaaf: grant ledger duplicate for order ' . (string) $order_id . ' — returning recorded grant');
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_grants WHERE order_id = %s", $order_id));
                    if ($row) {
                        $result = array(
                            'id'         => null,
                            'mode'       => (string) $row->mode,
                            'expires_at' => (string) $row->expires_at,
                            'idempotent' => true,
                        );
                    } else {
                        $result = false;
                    }
                } else {
                    error_log('MrDastbaaf: grant ledger insert failed for order ' . (string) $order_id . ' — ' . $last_error . ' (membership grant kept; ledger gap logged)');
                }
            }
        }

        // ردپای ممیزی مسیرهای grant (تایید ادمین/پرداخت درگاه) — بدون قفل‌گیری اضافه
        if (is_array($result) && 'blocked_lower_tier' !== $result['mode']) {
            MrDastbaaf_Admin_Controller::write_audit(
                'membership_granted',
                'order',
                (string) $order_id,
                sprintf('فعال‌سازی اشتراک tier=%s plan=%s mode=%s expires=%s', $tier, $plan->id, $result['mode'], $result['expires_at'])
            );
        }

        // BUG-32: آزادسازی قفل در تمام مسیرها (موفق/ناموفق)
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name));

        return $result;
    }
}
