<?php
/**
 * Plugin Name: Mr. Dastbaaf PWA Bridge (پل ارتباطی مستر دستباف)
 * Plugin URI: https://mrdastbaaf.com
 * Description: افزونه اختصاصی ایجاد اندپوینت‌های REST API ایمن برای ارتباط وب‌اپلیکیشن PWA مستر دستباف با وردپرس، اوتینو، نیاس کورس و درگاه زیبال.
 * Version: 1.3.5
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

define('MRDASTBAAF_BRIDGE_VERSION', '1.3.5');

/**
 * v1.2.0: نسخهٔ افزونه و ثابت بالا باید همیشه همگام باشند (باگ قبلی همگام‌نبودن تکرار نشود).
 * v1.2.1: دو زیرصفحهٔ NEW پیشخوان («پلن‌های عضویت» / «بررسی فیش‌ها») + هستهٔ مشترک
 * تایید/رد فیش (REST و پیشخوان) — بدون هیچ تغییری در اسکیمای دیتابیس (۱.۳.۰ ثابت می‌ماند).
 * v1.3.0: فهرست دوره‌ها در صفحهٔ «درس‌ها و ویدیوها» به‌صورت خودکار از دستهٔ ووکامرسی
 * تنظیم‌شده خوانده می‌شود (نام/لینک دوره از محصول — فقط فیلدهای جلسه دستی است) +
 * پوستهٔ سبک مدرن پیشخوان (assets/admin-ui.css فقط روی صفحات همین افزونه) —
 * اسکیما همچنان ۱.۳.۰ ثابت است (هیچ تغییر ساختاری در جداول).
 * v1.3.1: گارد ضدکش REST — LiteSpeed Cache هدر Vary: Origin را نادیده می‌گیرد؛
 * پاسخ‌های اندپوینت‌های افزونه با Cache-Control: no-store و X-LiteSpeed-Cache-Control:
 * no-cache از کش مستثنی می‌شوند (هیچ تغییری در منطق/شکل پاسخ‌ها).
 * v1.3.2: فیکس ۴۰۴ جزئیات دوره با اسلاگ فارسی — الگوی id اندپوینت /courses/{id}
 * به [^/]+ گسترش یافت + جستجوی تحمل‌پذیر اسلاگ (خام/یک‌بار/دوبار encoded) چون
 * وردپرس اسلاگ فارسی را به شکل «%d8%af...» ذخیره می‌کند و الگوی قبلی هرگز جور نمی‌شد.
 * v1.3.4: دو قابلیت NEW بدون هیچ تغییری در اسکیما (۱.۳.۰ ثابت) —
 *  ۱) «محتوای وب‌اپ»: متن‌های بنر اصلی، سکشن‌های صفحهٔ اصلی و منوی اپ از پیشخوان
 *     (صفحهٔ NEW «محتوای وب‌اپ») و سرو REST با GET /app-ui — ذخیره در آپشن
 *     mrdastbaaf_app_ui (بدون جدول جدید)؛ پاسخ namespace همیشه no-store است.
 *  ۲) «اتصال خرید سایت»: پل ووکامرس — نگاشت پلن→محصول در آپشن mrdastbaaf_woo_map
 *     (صفحهٔ NEW «اتصال خرید سایت»)؛ پرداخت موفق (processing/completed) محصولِ
 *     نگاشت‌شده، با هستهٔ مشترک grant_or_extend و دفتر ایدمپوتنسی woo-{order_id}
 *     اشتراک کاربر را فعال/تمدید می‌کند.
 * v1.3.5: سه قابلیت NEW بدون هیچ تغییری در اسکیما (۱.۳.۰ ثابت) —
 *  ۱) بازنشستگی سکشن «categories» + «دوره‌های برگزیده» انتخابی از پیشخوان
 *     (featuredCourseIds در /app-ui) — کارت چک‌باکسی NEW در «محتوای وب‌اپ».
 *  ۲) خرید تک‌دوره‌ای: سفارش پرداخت‌شدهٔ ووکامرس (customer_id یا billing_phone
 *     مهمان با نرمال‌سازی شماره؛ HPOS + postmeta قدیمی) → پرچم owned در جزئیات
 *     دوره و بازشدن تمام جلسات همان دوره در گیت بلیط پخش — بدون نگاشت دستی.
 *  ۳) رندر صفحات وب‌اپ با پارامتر mrdapp=1: فیلتر template_include صفحهٔ
 *     برگزیدهٔ وردپرس را با قالب Canvas (قالب خود المنتور در صورت وجود؛ در
 *     نبودش قالب مینیمال افزونه در templates/mrdapp-canvas.php) رندر می‌کند تا
 *     استایل صفحات المنتور در iframe وب‌اپ به‌هم نریزد؛ همان سطح دسترسی
 *     صفحات وب‌اپ (fail-closed) + سربرگ‌های no-store روی این مسیر اعمال می‌شود.
 */

/**
 * BUG-25: نسخه اسکیمای دیتابیس — با هر تغییر ساختار جداول باید افزایش یابد تا
 * چک نسخه در plugins_loaded، dbDelta را مجدداً اجرا کند.
 *
 * v1.1.0 (اسکیما ۱.۲.۰): جدول جدید wp_mrdastbaaf_lessons (درس‌ها و ویدیوهای دوره‌ها).
 * نکته مهم: افزونهٔ ۱.۰.۳ مقدار '1.1.0' را در آپشن mrdastbaaf_db_version ذخیره کرده
 * بود؛ بنابراین برای این‌که مسیر آپگرید خودکار (چک عدم تساوی نسخه) روی سایت‌های
 * موجود نیز فعال شود، اسکیمای جدید با '1.2.0' شروع شد نه '1.1.0'.
 *
 * v1.2.0 (اسکیما ۱.۳.۰): دو جدول NEW —
 *   ۱) wp_mrdastbaaf_membership_grants (دفتر ایدمپوتنسی grant ها با UNIQUE(order_id) —
 *      جلوگیری از تمدید دوبرابر در چرخهٔ verifying→pending→claim مجدد)؛
 *   ۲) wp_mrdastbaaf_audit_log (ثبت ردپای اقدامات ادمین/سیستم)؛
 * به‌علاوه ستون NEW: orders.updated_at (پنجرهٔ بازبینی‌پذیری سفارش‌های شکست‌خورده
 * و بازیابی سفارش‌های verifying گیرکرده توسط ریکنسیلیشن).
 */
define('MRDASTBAAF_DB_VERSION', '1.3.0');
define('MRDASTBAAF_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('MRDASTBAAF_BRIDGE_URL', plugin_dir_url(__FILE__));
define('MRDASTBAAF_API_NAMESPACE', 'mrdastbaaf/v1');

// بارگذاری کلاس‌های ماژولار (v1.1.0: سرویس پیامک پیش از کنترلر احراز هویت)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-database.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-sms-service.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-membership-helper.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-auth-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-courses-controller.php';
// v1.3.3: صفحات وب‌اپ (منوی اپ + سطح دسترسی از پیشخوان)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-app-pages-controller.php';
// v1.3.4: محتوای وب‌اپ (REST /app-ui) + پل ووکامرس (پیش از کنترلر صفحات ادمین —
// زیرصفحه‌های «محتوای وب‌اپ» و «اتصال خرید سایت» از آن‌ها استفاده می‌کنند)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-app-ui-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-woo-bridge-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-memberships-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-payment-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-admin-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-settings-controller.php';
// v1.2.1: زیرصفحه‌های پیشخوان «پلن‌های عضویت» و «بررسی فیش‌ها» (بعد از کنترلر تنظیمات
// تا اسلاگ والد منو و هستهٔ مشترک فیش‌ها در دسترس باشند)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-admin-pages-controller.php';

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

        // v1.3.4: پل ووکامرس — پرداخت موفق محصولِ نگاشت‌شده → عضویت اپ؛ هوک‌ها
        // بی‌قید‌و‌شرط ثبت می‌شوند (هندلر در نبود ووکامرس به‌صورت دفاعی خالی می‌گذرد)
        MrDastbaaf_Woo_Bridge_Controller::add_hooks();

        // v1.2.1: زیرصفحه‌های NEW «پلن‌های عضویت» و «بررسی فیش‌ها» (CRUD پلن‌ها +
        // تایید/رد فیش با هستهٔ مشترک REST)
        $admin_pages_controller = new MrDastbaaf_Admin_Pages_Controller();
        $admin_pages_controller->register();

        // v1.3.0: پوستهٔ سبک پیشخوان — فقط روی چهار صفحهٔ همین افزونه بارگذاری می‌شود
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // v1.3.5: رندر مخصوص وب‌اپ برای صفحات سایت (؟mrdapp=1) — قالب Canvas بدون
        // هدر/فوتر سایت؛ استایل صفحات المنتور در iframe اپ سالم می‌ماند.
        add_filter('template_include', array($this, 'maybe_render_app_page'), 20);
    }

    /**
     * v1.3.5: رندر مخصوص وب‌اپ — وقتی صفحه‌ای از سایت با پارامتر mrdapp=1 باز
     * می‌شود (iframe وب‌اپ)، به‌جای قالب کامل سایت (هدر/فوتر/سایدبار که استایل
     * صفحات المنتور را در اپ به‌هم می‌ریزد)، قالب Canvas رندر می‌شود:
     *  - اگر المنتور فعال باشد و قالب canvas.php موجود باشد → قالب رسمی المنتور
     *    (CSS/JS کامل المنتور شامل post-{id}.css و کیت سراسری اجرا می‌شود)
     *  - در نبود المنتور → قالب مینیمال افزونه (templates/mrdapp-canvas.php)
     *
     * امنیت (fail-closed): فقط صفحه‌ای که در «صفحات وب‌اپ» پیشخوان فعال ثبت شده
     * باشد رندر می‌شود و دقیقاً همان سطح دسترسی REST (all/members/admins) با
     * هستهٔ مشترک App_Pages_Controller اعمال می‌شود — دانستن پارامتر mrdapp=1
     * هیچ راهِ دورزدنی نمی‌سازد. پاسخ هم با سربرگ‌های no-store سرو می‌شود تا
     * LiteSpeed/CDN نسخهٔ سطح‌دار را برای کاربر دیگر کش نکند.
     *
     * @param string $template مسیر قالب جاری
     * @return string مسیر قالب نهایی
     */
    public function maybe_render_app_page($template) {
        // فقط برای درخواست‌های front-end صفحهٔ تکی با پارامتر mrdapp
        if (empty($_GET['mrdapp']) || !is_singular('page')) {
            return $template;
        }

        $page_id = (int) get_queried_object_id();
        if ($page_id <= 0) {
            return $template;
        }

        // صفحه باید در «صفحات وب‌اپ» فعال ثبت شده باشد
        $entry = null;
        foreach (MrDastbaaf_App_Pages_Controller::get_pages_option() as $candidate) {
            if ((int) $candidate['page_id'] === $page_id) {
                $entry = $candidate;
                break;
            }
        }

        if (null === $entry || empty($entry['is_active']) || 'publish' !== get_post_status($page_id)) {
            wp_die(
                esc_html__('این صفحه برای نمایش در وب‌اپلیکیشن فعال نشده است.', 'mrdastbaaf-bridge'),
                esc_html__('صفحهٔ یافت‌نشده', 'mrdastbaaf-bridge'),
                array('response' => 404)
            );
        }

        // همان هستهٔ سطح دسترسی REST (Bearer اینجا بی‌اثر است؛ کوکی/نشست کاربر ملاک است)
        $level = MrDastbaaf_App_Pages_Controller::current_access_level();
        if (!MrDastbaaf_App_Pages_Controller::access_satisfied($entry['access'], $level)) {
            wp_die(
                esc_html__('دسترسی به این صفحه نیازمند سطح عضویت بالاتری است.', 'mrdastbaaf-bridge'),
                esc_html__('دسترسی محدود', 'mrdastbaaf-bridge'),
                array('response' => 403)
            );
        }

        // ضدکش — نسخهٔ سطح‌دار هرگز نباید در LiteSpeed/CDN کش شود
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            header('Pragma: no-cache');
        }

        // قالب Canvas المنتور ( fidelity کامل المنتور ) یا قالب مینیمال افزونه
        if (defined('ELEMENTOR_PATH') && is_string(ELEMENTOR_PATH)
            && file_exists(ELEMENTOR_PATH . 'modules/page-templates/canvas.php')) {
            return ELEMENTOR_PATH . 'modules/page-templates/canvas.php';
        }

        return MRDASTBAAF_BRIDGE_PATH . 'templates/mrdapp-canvas.php';
    }

    /**
     * v1.3.0: استایل سبک صفحات پیشخوان افزونه — یک فایل CSS خالص (~۶KB) بدون هیچ
     * وابستگی؛ فقط وقتی page= از خانوادهٔ mrdastbaaf-* است بارگذاری می‌شود تا
     * بقیهٔ پیشخوان وردپرس هیچ باری اضافه‌ای نپذیرد. نسخهٔ افزونه به‌عنوان
     * cache-buster کوئری استایل.
     */
    public function enqueue_admin_assets() {
        if (!isset($_GET['page']) || !is_string($_GET['page'])) {
            return;
        }
        $page = sanitize_key(wp_unslash($_GET['page']));
        $allowed = array(
            MrDastbaaf_Settings_Controller::MENU_SLUG,      // mrdastbaaf-bridge (تنظیمات)
            MrDastbaaf_Settings_Controller::LESSONS_SLUG,   // mrdastbaaf-lessons
            MrDastbaaf_Admin_Pages_Controller::PLANS_SLUG,  // mrdastbaaf-plans
            MrDastbaaf_Admin_Pages_Controller::RECEIPTS_SLUG, // mrdastbaaf-receipts
            MrDastbaaf_Admin_Pages_Controller::APP_PAGES_SLUG, // mrdastbaaf-app-pages (v1.3.3)
            MrDastbaaf_Admin_Pages_Controller::APP_UI_SLUG,    // mrdastbaaf-app-ui (v1.3.4)
            MrDastbaaf_Admin_Pages_Controller::WOO_MAP_SLUG,   // mrdastbaaf-woo-map (v1.3.4)
        );
        if (in_array($page, $allowed, true)) {
            wp_enqueue_style(
                'mrdastbaaf-admin-ui',
                MRDASTBAAF_BRIDGE_URL . 'assets/admin-ui.css',
                array(),
                MRDASTBAAF_BRIDGE_VERSION
            );
        }
    }

    /**
     * ثبت کنترلرهای REST API
     */
    public function register_rest_routes() {
        $auth_controller = new MrDastbaaf_Auth_Controller();
        $auth_controller->register_routes();

        $courses_controller = new MrDastbaaf_Courses_Controller();
        $courses_controller->register_routes();

        // v1.3.3: صفحات وب‌اپ
        $app_pages_controller = new MrDastbaaf_App_Pages_Controller();
        $app_pages_controller->register_routes();

        // v1.3.4: محتوای وب‌اپ (متن‌های بنر/سکشن‌ها/منو — GET /app-ui)
        $app_ui_controller = new MrDastbaaf_App_UI_Controller();
        $app_ui_controller->register_routes();

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
     *
     * SECURITY FIX (v1.2.0 — یافتهٔ ۲-۱۹): محدودسازی CORS به namespace افزونه —
     * remove_filter سراسری قبلی، هدرهای CORS هسته را از «تمام» مسیرهای REST سایت
     * حذف می‌کرد (webhook ها/افزونه‌های ثالث را بی‌دفاع می‌گذاشت). اکنون جایگزینی
     * هدرها فقط وقتی انجام می‌شود که درخواست متعلق به mrdastbaaf/v1 باشد؛ سایر
     * مسیرهای REST هدرهای پیش‌فرض وردپرس را نگه می‌دارند.
     */
    public function setup_cors_headers() {
        // فقط برای درخواست‌های خود افزونه — بقیهٔ REST سایت دست‌نخورده می‌ماند
        if (!self::is_mrdastbaaf_rest_request()) {
            return;
        }

        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function ($value) {
            /**
             * v1.3.1: گارد ضدکش — LiteSpeed Cache (رایج روی هاست‌های اشتراکی) هدر
             * Vary: Origin را نادیده می‌گیرد و پاسخ REST را «با سربرگ‌هایش» برای همهٔ
             * Origin ها کش می‌کند؛ اولین درخواستِ بدون Origin (ربات/مانیتور/پیش‌نمایش)
             * باعث می‌شود نسخهٔ کش‌شدهٔ بدون هدر CORS به مرورگر PWA برسد و همهٔ
             * درخواست‌ها با خطای شبکه شکست بخورند. اندپوینت‌های این افزونه هرگز
             * نباید کش شوند — هم Cache-Control استاندارد و هم سربرگ مخصوص LiteSpeed.
             */
            if (!headers_sent()) {
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('X-LiteSpeed-Cache-Control: no-cache');
                header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
                header('Pragma: no-cache');
            }

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
                /**
                 * v1.2.0: «Access-Control-Allow-Credentials: true» حذف شد — جریان
                 * احراز این API صرفاً Bearer است (هدر Authorization) و کلاینت با
                 * credentials:'omit' کار می‌کند؛ ارسال کوکی/اعتبار بین‌دامنه‌ای نه
                 * لازم است نه امن (Allow-Credentials + Allow-Origin صریح = سطح
                 * حملهٔ CSRF/CORS بزرگ‌تر بدون هیچ کاربردی اینجا).
                 */
                header('Access-Control-Allow-Headers: Authorization, X-WP-Nonce, Content-Type, Cache-Control, X-Requested-With');
                // v1.2.0: کش پاسخ preflight مرورگر (۱۰ دقیقه) — حذف OPTIONSهای تکراری
                header('Access-Control-Max-Age: 600');
                header('Vary: Origin');
            }

            if ('OPTIONS' === $_SERVER['REQUEST_METHOD']) {
                status_header(200);
                exit();
            }

            return $value;
        });
    }

    /**
     * v1.2.0: تشخیص درخواست REST متعلق به افزونه — همان الگوی محدودسازی namespace
     * Bearer در کنترلر احراز هویت (یافتهٔ ۰-۲): یا مسیر پیش‌فرض
     * «/{rest_prefix}/mrdastbaaf/v1» در REQUEST_URI یا پارامتر rest_route با
     * پیشوند «/mrdastbaaf/v1» (نصب‌های بدون pretty permalink).
     *
     * @return bool
     */
    private static function is_mrdastbaaf_rest_request() {
        if (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/' . rest_get_url_prefix() . '/mrdastbaaf/v1') !== false) {
            return true;
        }
        if (isset($_GET['rest_route']) && strpos((string) $_GET['rest_route'], '/mrdastbaaf/v1') === 0) {
            return true;
        }
        return false;
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

    // v1.2.0: زمان‌بندی کرون ۱۰دقیقه‌ای ریکنسیلیشن سفارش‌های زیبال (جایگزین روزانه)
    mrdastbaaf_bridge_schedule_reconciliation_cron();
}
register_activation_hook(__FILE__, 'mrdastbaaf_bridge_activate');

/**
 * v1.2.0: بازهٔ زمانی NEW «هر ۱۰ دقیقه» برای WP-Cron — پنجرهٔ بازیابی سفارش‌های
 * verifying گیرکرده (۱۰ دقیقه) بدون این بازه بی‌معناست؛ کرون روزانه قدیمی حذف شد.
 */
add_filter('cron_schedules', 'mrdastbaaf_bridge_add_cron_interval');
function mrdastbaaf_bridge_add_cron_interval($schedules) {
    if (!isset($schedules['mrdastbaaf_10min'])) {
        $schedules['mrdastbaaf_10min'] = array(
            'interval' => 600,
            'display'  => 'Every 10 Minutes (MrDastbaaf)',
        );
    }
    return $schedules;
}

/**
 * v1.2.0: ثبت رویداد ۱۰دقیقه‌ای WP-Cron — مهاجرت از رویداد روزانهٔ قدیمی
 * mrdastbaaf_daily_reconciliation به mrdastbaaf_reconciliation_tick:
 * زمان‌بندی قدیمی صریحاً پاک می‌شود تا روی سایت‌های ارتقایافته دوباره اجرا نشود.
 */
function mrdastbaaf_bridge_schedule_reconciliation_cron() {
    if (wp_next_scheduled('mrdastbaaf_daily_reconciliation')) {
        wp_clear_scheduled_hook('mrdastbaaf_daily_reconciliation');
    }
    if (!wp_next_scheduled('mrdastbaaf_reconciliation_tick')) {
        wp_schedule_event(time() + 10 * MINUTE_IN_SECONDS, 'mrdastbaaf_10min', 'mrdastbaaf_reconciliation_tick');
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

    // v1.2.0: اطمینان از موجود بودن زمان‌بندی کرون جدید پس از ارتقا یا حذف اتفاقی
    mrdastbaaf_bridge_schedule_reconciliation_cron();

    // v1.2.0: توکن اختصاصی اندپوینت GET /cron/reconcile — یک‌بار در نخستین ارتقا
    // تولید می‌شود؛ هرگز بازتاب داده نمی‌شود و فقط مقدار ثابت hash_equals مقایسه می‌کند.
    if ('' === (string) get_option('mrdastbaaf_cron_token', '')) {
        update_option('mrdastbaaf_cron_token', wp_generate_password(32, false, false), false);
    }
}
add_action('plugins_loaded', 'mrdastbaaf_bridge_maybe_upgrade_db', 20);

/**
 * v1.2.0: اجرای دوره‌ای هر ۱۰ دقیقه — بازیابی سفارش‌های verifying گیرکرده،
 * ریکنسیلیشن پرداخت‌های پرداخت‌شده‌ای که کال‌بک‌شان گم شده، و بازبررسی شکست‌های
 * ۷۲ ساعت اخیر (پیاده‌سازی در کنترلر پرداخت؛ run_daily_reconciliation فقط
 * نام مستعار منسوخ‌شده برای سازگاری هوک‌های ثالث باقی مانده است).
 */
add_action('mrdastbaaf_reconciliation_tick', array('MrDastbaaf_Payment_Controller', 'run_reconciliation'));

/**
 * v1.2.0: پاک‌سازی زمان‌بندی کرون هنگام غیرفعال‌سازی افزونه (رویداد جدید +
 * رویداد روزانهٔ قدیمی، برای پاک‌سازی کامل مهاجرت‌های ناتمام)
 */
function mrdastbaaf_bridge_deactivate() {
    wp_clear_scheduled_hook('mrdastbaaf_reconciliation_tick');
    wp_clear_scheduled_hook('mrdastbaaf_daily_reconciliation');
}
register_deactivation_hook(__FILE__, 'mrdastbaaf_bridge_deactivate');
