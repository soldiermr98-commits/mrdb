<?php
/**
 * Plugin Name: Mr. Dastbaaf PWA Bridge (پل ارتباطی مستر دستباف)
 * Plugin URI: https://mrdastbaaf.com
 * Description: افزونه اختصاصی ایجاد اندپوینت‌های REST API ایمن برای ارتباط وب‌اپلیکیشن PWA مستر دستباف با وردپرس، اوتینو، نیاس کورس و درگاه زیبال.
 * Version: 1.1.0
 * Author: تیم فنی مستر دستباف
 * Author URI: https://mrdastbaaf.com
 * Text Domain: mrdastbaaf-bridge
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

define('MRDASTBAAF_BRIDGE_VERSION', '1.1.0');

/**
 * v1.1.0: نسخهٔ افزونه و ثابت بالا باید همیشه همگام باشند (باگ قبلی همگام‌نبودن تکرار نشود).
 */

/**
 * BUG-25: نسخه اسکیمای دیتابیس — با هر تغییر ساختار جداول باید افزایش یابد تا
 * چک نسخه در plugins_loaded، dbDelta را مجدداً اجرا کند.
 *
 * v1.1.0 (اسکیما ۱.۲.۰): جدول جدید wp_mrdastbaaf_lessons (درس‌ها و ویدیوهای دوره‌ها).
 * نکته مهم: افزونهٔ ۱.۰.۳ مقدار '1.1.0' را در آپشن mrdastbaaf_db_version ذخیره کرده
 * بود؛ بنابراین برای این‌که مسیر آپگرید خودکار (چک عدم تساوی نسخه) روی سایت‌های
 * موجود نیز فعال شود، اسکیمای جدید باید با '1.2.0' شروع شود نه '1.1.0'.
 */
define('MRDASTBAAF_DB_VERSION', '1.2.0');
define('MRDASTBAAF_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('MRDASTBAAF_BRIDGE_URL', plugin_dir_url(__FILE__));
define('MRDASTBAAF_API_NAMESPACE', 'mrdastbaaf/v1');

// بارگذاری کلاس‌های ماژولار (v1.1.0: سرویس پیامک پیش از کنترلر احراز هویت)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-database.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-sms-service.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-membership-helper.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-auth-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-courses-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-memberships-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-payment-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-admin-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-settings-controller.php';

/**
 * کلاس اصلی راه‌اندازی افزونه
 */
class MrDastbaaf_Bridge_Plugin {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // BUG-25: هوک فعال‌سازی به سطح فایل افزونه منتقل شد (پایین همین فایل).
        // در درخواست فعال‌سازی، plugins_loaded «قبل» از include فایل افزونه اجرا
        // می‌شود و ثبت هوک داخل این سازنده (که فقط روی plugins_loaded ساخته می‌شود)
        // هرگز فراخوانی نمی‌شد → جداول/seed در نصب تازه ساخته نمی‌شدند.

        // ثبت روت‌های REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // تنظیم هدرهای CORS برای ارتباط امن با PWA
        // v1.1.0: هدر Authorization (Bearer) باید در Access-Control-Allow-Headers
        // بماند (در setup_cors_headers موجود است) وگرنه مرورگر درخواست‌های توکن‌دار
        // PWA را در preflight بلاک می‌کند.
        add_action('rest_api_init', array($this, 'setup_cors_headers'), 15);

        // v1.1.0: احراز هویت Bearer برای اندپوینت‌های REST — PWA توکن opaque دریافتی
        // از verify-otp را در هدر «Authorization: Bearer» می‌فرستد. اولویت ۲۰:
        // اگر کوکی/احراز قبلی کاربر را تعیین کرده بود، دست نمی‌زنیم.
        add_filter('determine_current_user', array('MrDastbaaf_Auth_Controller', 'determine_current_user_from_bearer'), 20);

        // v1.1.0: منوی پیشخوان «مستر دستباف» — تنظیمات + مدیریت درس‌ها و ویدیوها
        $settings_controller = new MrDastbaaf_Settings_Controller();
        $settings_controller->register();
    }

    /**
     * ثبت کنترلرهای REST API
     */
    public function register_rest_routes() {
        $auth_controller = new MrDastbaaf_Auth_Controller();
        $auth_controller->register_routes();

        $courses_controller = new MrDastbaaf_Courses_Controller();
        $courses_controller->register_routes();

        $memberships_controller = new MrDastbaaf_Memberships_Controller();
        $memberships_controller->register_routes();

        $payment_controller = new MrDastbaaf_Payment_Controller();
        $payment_controller->register_routes();

        $admin_controller = new MrDastbaaf_Admin_Controller();
        $admin_controller->register_routes();
    }

    /**
     * تنظیم هدرهای CORS مجاز برای دامنه وب‌اپلیکیشن
     *
     * CONFIG FIX (BUG-06): لیست دامنه‌های مجاز دیگر هاردکد نمی‌شود.
     * قبلاً «http://localhost:3000» به‌صورت پیش‌فرض در پروداکشن مجاز بود؛
     * اکنون دامنه‌ها از Option وردپرس خوانده می‌شوند (قابل تنظیم در پنل مدیریت)،
     * و توسعه‌دهنده می‌تواند فقط در محیط dev دامنه لوکال را با فیلتر اضافه کند.
     */
    public function setup_cors_headers() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            $origin = get_http_origin();

            // پیش‌فرض پروداکشن: فقط دامنه‌های رسمی. بدون localhost.
            $default_origins = array(
                'https://mrdastbaaf.com',
                'https://app.mrdastbaaf.com',
                'https://www.mrdastbaaf.com',
            );

            $stored_origins = get_option('mrdastbaaf_allowed_origins', $default_origins);
            $allowed_origins = apply_filters('mrdastbaaf_allowed_origins', is_array($stored_origins) ? $stored_origins : $default_origins);

            if ($origin && in_array($origin, $allowed_origins, true)) {
                header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
                header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Type, Cache-Control, X-Requested-With');
                header('Vary: Origin');
            }

            if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
                status_header(200);
                exit();
            }

            return $value;
        });
    }
}

// مقداردهی اولیه افزونه
add_action('plugins_loaded', array('MrDastbaaf_Bridge_Plugin', 'get_instance'));

/**
 * BUG-25: هوک فعال‌سازی باید در سطح فایل افزونه و خارج از هر کلاس/هوک ثبت شود؛
 * در درخواست فعال‌سازی، وردپرس فایل افزونه را include می‌کند و بلافاصله هوک
 * فعال‌سازی را اجرا می‌کند (بدون گذر از plugins_loaded). ساخت جداول + seed پلن‌ها
 * این‌بار تضمین می‌شود.
 */
function mrdastbaaf_bridge_activate() {
    MrDastbaaf_Database::create_tables();
    update_option('mrdastbaaf_db_version', MRDASTBAAF_DB_VERSION);

    // BUG-35: زمان‌بندی کرون روزانه ریکنسیلیشن سفارش‌های معلق زیبال
    mrdastbaaf_bridge_schedule_reconciliation_cron();
}
register_activation_hook(__FILE__, 'mrdastbaaf_bridge_activate');

/**
 * BUG-35: ثبت رویداد روزانه WP-Cron در صورت عدم وجود زمان‌بندی قبلی
 */
function mrdastbaaf_bridge_schedule_reconciliation_cron() {
    if (!wp_next_scheduled('mrdastbaaf_daily_reconciliation')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'mrdastbaaf_daily_reconciliation');
    }
}

/**
 * BUG-25: چک نسخه اسکیمای دیتابیس در هر بارگذاری افزونه — پوشش آپگرید:
 * اگر نسخه ذخیره‌شده با نسخه جاری فرق داشته باشد (نصب قدیمی ۱.۰.۲)،
 * dbDelta مجدداً اجرا و نسخه به‌روز می‌شود (مثلاً افزودن ایندکس‌های BUG-26).
 */
function mrdastbaaf_bridge_maybe_upgrade_db() {
    if (get_option('mrdastbaaf_db_version') !== MRDASTBAAF_DB_VERSION) {
        MrDastbaaf_Database::create_tables();
        update_option('mrdastbaaf_db_version', MRDASTBAAF_DB_VERSION);
    }

    // BUG-35: اطمینان از موجود بودن زمان‌بندی کرون پس از ارتقا یا حذف اتفاقی
    mrdastbaaf_bridge_schedule_reconciliation_cron();
}
add_action('plugins_loaded', 'mrdastbaaf_bridge_maybe_upgrade_db', 20);

/**
 * BUG-35: اجرای روزانه — انقضای سفارش‌های معلق زیبال و ریکنسیلیشن پرداخت‌های
 * پرداخت‌شده‌ای که کال‌بک‌شان گم شده است (پیاده‌سازی در کنترلر پرداخت).
 */
add_action('mrdastbaaf_daily_reconciliation', array('MrDastbaaf_Payment_Controller', 'run_daily_reconciliation'));

/**
 * BUG-35: پاک‌سازی زمان‌بندی کرون هنگام غیرفعال‌سازی افزونه
 */
function mrdastbaaf_bridge_deactivate() {
    wp_clear_scheduled_hook('mrdastbaaf_daily_reconciliation');
}
register_deactivation_hook(__FILE__, 'mrdastbaaf_bridge_deactivate');
