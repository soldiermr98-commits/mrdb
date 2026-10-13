<?php
/**
 * Plugin Name: Mr. Dastbaaf PWA Bridge (پل ارتباطی مستر دستباف)
 * Plugin URI: https://mrdastbaaf.com
 * Description: افزونه اختصاصی ایجاد اندپوینت‌های REST API ایمن برای ارتباط وب‌اپلیکیشن PWA مستر دستباف با وردپرس، اوتینو، نیاس کورس و درگاه زیبال.
 * Version: 1.0.1
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

define('MRDASTBAAF_BRIDGE_VERSION', '1.0.1');
define('MRDASTBAAF_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('MRDASTBAAF_BRIDGE_URL', plugin_dir_url(__FILE__));
define('MRDASTBAAF_API_NAMESPACE', 'mrdastbaaf/v1');

// بارگذاری کلاس‌های ماژولار
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-database.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-membership-helper.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-auth-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-courses-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-memberships-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-payment-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-admin-controller.php';

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
        // هوک فعال‌سازی افزونه جهت ساخت جداول دیتابیس
        register_activation_hook(__FILE__, array('MrDastbaaf_Database', 'create_tables'));

        // ثبت روت‌های REST API
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        // تنظیم هدرهای CORS برای ارتباط امن با PWA
        add_action('rest_api_init', array($this, 'setup_cors_headers'), 15);
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
