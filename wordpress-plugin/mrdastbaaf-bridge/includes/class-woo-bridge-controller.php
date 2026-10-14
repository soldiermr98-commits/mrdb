<?php
/**
 * پل «اتصال خرید سایت» (v1.3.4 — فایل NEW)
 *
 * خرید محصولات ووکامرس سایت، عضویت وب‌اپ فعال می‌کند: ادمین در صفحهٔ NEW
 * «اتصال خرید سایت» هر پلنِ جدول wp_mrdastbaaf_plans را به یک محصول ووکامرس
 * (publish) نگاشت می‌کند؛ آرایهٔ نگاشت در آپشن «mrdastbaaf_woo_map» ذخیره می‌شود
 * (plan_id رشته‌ای → product_id عددی). وقتی وضعیت یک سفارش ووکامرس به
 * processing یا completed می‌رسد، اگر آیتم‌هایش محصولِ نگاشت‌شده داشته باشند،
 * خریدار از هستهٔ مشترک MrDastbaaf_Membership_Helper::grant_or_extend() اشتراک
 * می‌گیرد — یعنی همان منطق tier-aware (تمدید/ارتقا/رد سطح پایین‌تر) و همان
 * دفتر ایدمپوتنسی wp_mrdastbaaf_membership_grants با UNIQUE(order_id).
 *
 * ایدمپوتنسی: کلید دفتر grant برای سفارش ووکامرس «woo-{order_id}» است؛
 * فراخوانی‌های تکراری webhook (processing سپس completed، یا اجرای دوبارهٔ
 * افزونه‌ها) هرگز تمدید دوبرابر نمی‌سازند — فراخوانی دوم replay بی‌نوشتن است.
 *
 * شناسایی کاربر: اول customer_id سفارش؛ اگر مهمان (صفر) بود، شمارهٔ صورتحساب
 * با همان روش کنترلر احراز هویت جست‌وجو می‌شود (user_login = شماره، سپس
 * فال‌بک meta billing_phone). بدون کاربرِ قابل‌شناسایی، هیچ grant ای انجام
 * نمی‌شود و فقط در error_log ثبت می‌شود (fail-closed).
 *
 * دفاعی بودن: کل مسیر در try/catch (Throwable) است و در نبود ووکامرس/تابع‌های
 * آن هرگز fatal نمی‌شود؛ سفارش‌های بدون محصولِ نگاشت‌شده بی‌صدا رد می‌شوند.
 * هیچ تغییری در اسکیمای دیتابیس (۱.۳.۰ ثابت).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Woo_Bridge_Controller {

    const OPTION_KEY = 'mrdastbaaf_woo_map';

    public static function add_hooks() {
        add_action('woocommerce_order_status_completed', array(__CLASS__, 'handle_order_status_change'), 10, 1);
        add_action('woocommerce_order_status_processing', array(__CLASS__, 'handle_order_status_change'), 10, 1);
    }

    /**
     * آپشن نگاشت پلن → محصول (پاکسازی‌شده؛ ورودی‌های نامعتبر حذف می‌شوند)
     *
     * @return array<string,int>
     */
    public static function get_map() {
        $map = get_option(self::OPTION_KEY, array());
        if (!is_array($map)) {
            return array();
        }
        $clean = array();
        foreach ($map as $plan_id => $product_id) {
            $plan_id = sanitize_text_field((string) $plan_id);
            $product_id = absint($product_id);
            if ('' !== $plan_id && $product_id > 0) {
                $clean[$plan_id] = $product_id;
            }
        }
        return $clean;
    }

    /**
     * ذخیرهٔ نگاشت (از صفحهٔ پیشخوان؛ ورودی‌ها پیش از این پاکسازی شده‌اند)
     *
     * @param array $map
     * @return bool
     */
    public static function save_map(array $map) {
        return update_option(self::OPTION_KEY, $map, false);
    }

    /**
     * هوک وضعیت سفارش ووکامرس — هرگز نباید به جریان پرداخت صدمه بزند
     *
     * @param int $order_id
     */
    public static function handle_order_status_change($order_id) {
        try {
            self::process_order($order_id);
        } catch (Throwable $e) {
            // Requires PHP 7.0+ (هدر افزونه: Requires PHP 7.4) — Throwable همهٔ خطاها/استثناها
            error_log('MrDastbaaf WooBridge: uncaught exception for order #' . (int) $order_id . ' — ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * منطق اصلی: تطبیق آیتم‌های سفارش با نگاشت → resolve کاربر → grant
     *
     * @param int $order_id
     * @return void
     */
    private static function process_order($order_id) {
        global $wpdb;

        $order_id = (int) $order_id;
        if ($order_id <= 0) {
            return;
        }

        // ۱) نگاشت خالی؟ هیچ کاری نکن (سریع‌ترین مسیر خروج)
        $map = self::get_map();
        if (empty($map)) {
            return;
        }

        // ۲) ووکامرس فعال و سفارش قابل‌خواندن؟ (نبود ووکامرس = fatal نمی‌شود)
        if (!function_exists('wc_get_order')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            error_log('MrDastbaaf WooBridge: order #' . $order_id . ' could not be loaded — skipped');
            return;
        }

        // ۳) جدول معکوس محصول → پلن (اولین نگاشت هر محصول برنده است) + تطبیق آیتم‌ها
        $product_to_plan = array();
        foreach ($map as $plan_id => $product_id) {
            $product_id = (int) $product_id;
            if ($product_id > 0 && !isset($product_to_plan[$product_id])) {
                $product_to_plan[$product_id] = (string) $plan_id;
            }
        }
        if (empty($product_to_plan)) {
            return;
        }

        $matched_plan_ids = array();
        foreach ($order->get_items() as $item) {
            if (!is_object($item)) {
                continue;
            }
            // get_product_id برای variation «والد» را برمی‌گرداند؛ خودِ variation
            // هم جداگانه چک می‌شود تا نگاشت روی والد یا variation هر دو کار کند.
            $candidate_ids = array();
            if (method_exists($item, 'get_variation_id') && (int) $item->get_variation_id() > 0) {
                $candidate_ids[] = (int) $item->get_variation_id();
            }
            if (method_exists($item, 'get_product_id')) {
                $candidate_ids[] = (int) $item->get_product_id();
            }
            foreach ($candidate_ids as $product_id) {
                if ($product_id > 0 && isset($product_to_plan[$product_id])) {
                    $matched_plan_ids[] = $product_to_plan[$product_id];
                    break;
                }
            }
        }
        $matched_plan_ids = array_values(array_unique($matched_plan_ids));
        if (empty($matched_plan_ids)) {
            return; // سفارش عادی سایت (بدون محصول نگاشت‌شده) — بی‌صدا رد می‌شود
        }

        if (count($matched_plan_ids) > 1) {
            error_log('MrDastbaaf WooBridge: order #' . $order_id . ' contains multiple mapped plans (' . implode(', ', $matched_plan_ids) . ') — ledger key is per-order (woo-' . $order_id . '); only the first plan can be granted');
        }

        // ۴) resolve کاربر: customer_id سپس فال‌بک شمارهٔ صورتحساب
        $user_id = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        if ($user_id <= 0) {
            $phone = method_exists($order, 'get_billing_phone') ? (string) $order->get_billing_phone() : '';
            $user = self::find_user_by_phone($phone);
            if ($user && isset($user->ID)) {
                $user_id = (int) $user->ID;
            }
        }
        if ($user_id <= 0) {
            error_log('MrDastbaaf WooBridge: order #' . $order_id . ' matched plan(s) ' . implode(', ', $matched_plan_ids) . ' but no user could be resolved (guest without known phone) — no grant, order left untouched');
            return;
        }

        // ۵) فعال‌سازی/تمدید از هستهٔ مشترک — idempotent با دفتر grant (UNIQUE order_id)
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $ledger_order_id = 'woo-' . $order_id;
        $granted = array();
        foreach ($matched_plan_ids as $plan_id) {
            $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $plan_id));
            if (!$plan) {
                error_log('MrDastbaaf WooBridge: mapped plan "' . $plan_id . '" (order #' . $order_id . ') not found in plans table — skipped');
                continue;
            }

            $result = MrDastbaaf_Membership_Helper::grant_or_extend($user_id, $plan, $ledger_order_id);
            if (is_array($result)) {
                $granted[] = $plan_id . ' (' . (string) $result['mode'] . ')';
                error_log(sprintf(
                    'MrDastbaaf WooBridge: order #%d → user #%d plan "%s" mode=%s expires=%s%s',
                    $order_id,
                    $user_id,
                    $plan_id,
                    (string) $result['mode'],
                    (string) $result['expires_at'],
                    !empty($result['idempotent']) ? ' [idempotent replay]' : ''
                ));
            } else {
                error_log('MrDastbaaf WooBridge: grant failed for order #' . $order_id . ' user #' . $user_id . ' plan "' . $plan_id . '" — see membership helper log');
            }
        }

        if (empty($granted)) {
            error_log('MrDastbaaf WooBridge: order #' . $order_id . ' — no membership granted (plan rows missing or grant blocked)');
        }
    }

    /**
     * یافتن کاربر با شماره موبایل — عیناً همان روش class-auth-controller.php:
     * ابتدا user_login (شمارهٔ نرمال‌شدهٔ مسیر OTP)، سپس فال‌بک meta billing_phone.
     * (متد اصلی private است؛ تکرار الگو در اینجا عمدی و مطابق قرارداد تسک است.)
     *
     * @param string $phone شمارهٔ صورتحساب سفارش (هر فرمتی)
     * @return WP_User|false
     */
    private static function find_user_by_phone($phone) {
        $phone = trim((string) $phone);
        if ('' === $phone) {
            return false;
        }

        // نرمال‌سازی پیشوندهای ایران (+98/0098 → 0) — همان الگوی کنترلر احراز هویت
        $normalized = preg_replace('/^(\+98|0098)/', '0', $phone);
        $candidates = array();
        if (is_string($normalized) && '' !== $normalized && !in_array($normalized, $candidates, true)) {
            $candidates[] = $normalized;
        }
        if (!in_array($phone, $candidates, true)) {
            $candidates[] = $phone; // فرم خام (گاهی ادمین شماره را با فرمت دیگری ثبت کرده)
        }

        foreach ($candidates as $candidate) {
            $user = get_user_by('login', $candidate);
            if ($user) {
                return $user;
            }
        }

        foreach ($candidates as $candidate) {
            $found = get_users(array(
                'meta_key'   => 'billing_phone',
                'meta_value' => $candidate,
                'number'     => 1,
                'fields'     => 'all',
            ));
            if (!empty($found)) {
                return $found[0];
            }
        }

        return false;
    }
}
