<?php
/**
 * Plugin Name: Mr. Dastbaaf PWA Bridge (پل ارتباطی مستر دستباف)
 * Plugin URI: https://mrdastbaaf.com
 * Description: افزونه اختصاصی ایجاد اندپوینت‌های REST API ایمن برای ارتباط وب‌اپلیکیشن PWA مستر دستباف با وردپرس، اوتینو، نیاس کورس و درگاه زیبال.
 * Version: 1.7.4
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

define('MRDASTBAAF_BRIDGE_VERSION', '1.7.4');

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
 * v1.4.0: هفت بهبود NEW —
 *  ۱) ورود: پاسخ request-otp پرچم isRegistered می‌گیرد و verify-otp نام/نام‌خانوادگی
 *     اختیاری (فقط حروف فارسی/فاصله/نیم‌فاصله، حداکثر ۶۰ کاراکتر) می‌پذیرد — نام
 *     واقعی جای «هنرجو NNNN» می‌نشیند و متیاهای صورتحساب ووکامرس هم به‌روز می‌شوند.
 *  ۲) دوره‌ها: فیلد duration عددی (جمع دقیقه‌های درس‌ها) + پرچم owned در لیست و
 *     اندپوینت NEW GET /my/courses (دوره‌های خریداری‌شدهٔ کاربر جاری).
 *  ۳) درس‌ها: دو ستون NEW در جدول lessons (duration_minutes / qualities_json —
 *     اسکیما ۱.۴.۰ با dbDelta روی نصب‌های موجود آپگرید می‌شود) + فیلدهای مدت و
 *     کیفیت ۴۸۰/۷۲۰/۱۰۸۰ در صفحهٔ «درس‌ها و ویدیوها» + پاسخ playback کلید
 *     qualities می‌گیرد (فیلتر مستند mrdastbaaf_stream_video_qualities اکنون واقعاً
 *     موجود است).
 *  ۴) فروشگاه: زیرصفحهٔ NEW «محصولات فیزیکی» (آپشن mrdastbaaf_physical_products)
 *     و اندپوینت عمومی NEW GET /shop/products برای بخش «محصولات دست‌ساز» وب‌اپ.
 *  ۵) «محتوای وب‌اپ»: سکشن پنجم NEW با کلید physical_products.
 * v1.5.0: سه قابلیت NEW بدون هیچ تغییری در اسکیما (۱.۴.۰ ثابت) —
 *  ۱) خرید تک‌دوره‌ای از درگاه زیبال: initiate با product_id اختیاری؛ پس از تایید
 *     پرداخت، سفارشِ واقعی ووکامرس در وضعیت processing ساخته می‌شود (بازشدن آنی دوره
 *     از طریق تشخیص مالکیت + ایمیل استاندارد ووکامرس برای ادمین) و plan_id سفارش
 *     افزونه «course-{id}» ذخیره می‌شود.
 *  ۲) GET /shop/products آرایهٔ top-level «featured» (محصولات ویژهٔ اسلایدر وب‌اپ)
 *     از آپشن mrdastbaaf_featured_products برمی‌گرداند.
 *  ۳) فیلدست «محصولات ویژه» روی صفحهٔ پیشخوان «محصولات فیزیکی» + گرید واکنش‌گرای
 *     چک‌باکس‌ها؛ عنوان پیش‌فرض سکشن فروشگاه «محصولات» شد و متن‌های seed پاکسازی شد.
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
 *
 * v1.4.0 (اسکیما ۱.۴.۰): دو ستون NEW در جدول wp_mrdastbaaf_lessons —
 *   duration_minutes (smallint unsigned؛ مدت هر درس به دقیقه — جمع آن فیلد
 *   duration را در /courses و /courses/{slug} عددی می‌کند) و qualities_json
 *   (text NULL؛ کیفیت‌های ۴۸۰p/۷۲۰p/۱۰۸۰p هر درس برای پاسخ playback). چک نسخهٔ
 *   پایین (mrdastbaaf_bridge_maybe_upgrade_db) dbDelta را دوباره اجرا می‌کند و
 *   ستون‌ها روی نصب‌های موجود بدون دستور دستی اضافه می‌شوند.
 */
define('MRDASTBAAF_DB_VERSION', '1.4.0');
define('MRDASTBAAF_BRIDGE_PATH', plugin_dir_path(__FILE__));
define('MRDASTBAAF_BRIDGE_URL', plugin_dir_url(__FILE__));
define('MRDASTBAAF_API_NAMESPACE', 'mrdastbaaf/v1');

/**
 * v1.7.4: نسخهٔ پیش‌فرض PWA — فقط برای «نخستین seed» گزینهٔ mrdastbaaf_pwa_version
 * استفاده می‌شود (نصب تازه‌ای که هنوز باندلی مستقر نکرده). پس از seed، منبع حقیقت
 * همیشه «پیشخوان ← تنظیمات ← نسخهٔ مستقر PWA» است و این ثابت دیگر نقشی ندارد.
 *
 * ریشهٔ باگ: نسخه‌های ≤1.7.3 به‌جای ثابت متمرکز، مقدار hard-code '1.5.3' را
 * به‌عنوان fallback get_option می‌دادند؛ نتیجه: بعد از استقرار باندل 1.5.4،
 * سرور هنوز «1.5.3» اعلام می‌کرد و کلاینت‌های 1.5.4 (با چکر !== آن زمان)
 * وارد حلقهٔ reload می‌شدند — قطع verify-otp با پیام «ارتباط با سرور برقرار نشد».
 */
define('MRDASTBAAF_PWA_VERSION_DEFAULT', '1.5.5');

/**
 * v1.7.4: خواندن متمرکز نسخهٔ مستقر PWA — تنها نقطهٔ خواندن گزینه.
 * مقدار گزینه توسط فیلد «نسخهٔ مستقر PWA» در پیشخوان مدیریت می‌شود و پس از
 * هر استقرار باندل جدید باید به همان عدد تغییر کند تا کلاینت‌های 1.5.4+
 * (با چکر فیکس‌شده) حداکثر تا ۲ دقیقه بعد خودکار reload شوند.
 */
function mrdastbaaf_get_deployed_pwa_version() {
    $version = trim((string) get_option('mrdastbaaf_pwa_version', ''));
    if ('' === $version || !preg_match('/^\d+(\.\d+)*$/', $version)) {
        return MRDASTBAAF_PWA_VERSION_DEFAULT;
    }
    return $version;
}

// بارگذاری کلاس‌های ماژولار (v1.1.0: سرویس پیامک پیش از کنترلر احراز هویت)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-database.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-sms-service.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-membership-helper.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-auth-controller.php';
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-courses-controller.php';
// v1.4.0: فروشگاه — محصولات فیزیکی دست‌ساز (پیش از کنترلر صفحات ادمین تا
// زیرصفحهٔ «محصولات فیزیکی» به آپشن مشترک آن دسترسی داشته باشد)
require_once MRDASTBAAF_BRIDGE_PATH . 'includes/class-shop-controller.php';
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

        // v1.7.2: بستن کش LiteSpeed روی پاسخ‌های REST افزونه — اگه پاسخ قبلاً کش
        // شده باشد، هدرهای no-cache ما نادیده گرفته می‌شوند. این فیلتر در ابتدای
        // هر درخواست REST افزونه، یک هدر منحصربه‌فرد (nonce) به Vary اضافه می‌کند
        // تا LiteSpeed نتواند پاسخ را کش کند.
        add_action('rest_api_init', array($this, 'disable_litespeed_cache_for_namespace'), 1);

        // v1.7.3: روش رسمی LiteSpeed Cache — مستقیم به پلاگین می‌گوید پاسخ را کش
        // نکند. این قوی‌تر از header() است چون:
        //   ۱) rest_post_dispatch در زمان درست اجرا می‌شود (بعد از پردازش، قبل از ارسال)
        //   ۲) litespeed_control_set_nocode API رسمی پلاگین LiteSpeed Cache است
        //   ۳) $result->header() متد رسمی WP_REST_Response است (نه header() خام)
        // تشکر از همکار (مشورت فنی) — این راه‌حل دقیق‌تر از روش قبلی است.
        add_filter('rest_post_dispatch', array($this, 'force_litespeed_nocache_for_namespace'), 99, 3);

        // v1.7.2: به‌روزرسانی content_hash هر بار که کارفرما چیزی در پیشخوان ذخیره می‌کند
        // (پلن‌ها، درس‌ها، محصولات، محتوا). کلاینت هر ۲ دقیقه چک می‌کند و اگه هش عوض
        // شده باشد، داده‌ها را دوباره بارگذاری می‌کند (بدون reload کامل صفحه).
        add_action('save_post', array($this, 'bump_content_hash'), 10, 3);
        add_action('updated_option', array($this, 'bump_content_hash_on_option_update'), 10, 3);
        add_action('added_option', array($this, 'bump_content_hash_on_option_add'), 10, 2);
    }

    /**
     * v1.7.2: غیرفعال‌کردن کش LiteSpeed برای namespace افزونه.
     * LiteSpeed Cache هدرهای no-cache ما را نادیده می‌گیرد و پاسخ را کش می‌کند.
     * این متد در ابتدای هر درخواست REST افزونه، هدرهای ضدکش قوی‌تر می‌فرستد.
     */
    public function disable_litespeed_cache_for_namespace() {
        if (!self::is_mrdastbaaf_rest_request()) {
            return;
        }
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        }
    }

    /**
     * v1.7.3: روش رسمی LiteSpeed Cache برای جلوگیری از کش پاسخ‌های REST.
     *
     * این متد در filter 'rest_post_dispatch' اجرا می‌شود — بعد از پردازش کامل
     * درخواست REST، ولی قبل از ارسال به کلاینت. این زمان درست برای ست‌کردن
     * هدرهاست (نه در rest_api_init که زود است).
     *
     * دو کار انجام می‌دهد:
     *   ۱) do_action('litespeed_control_set_nocache', ...) — API رسمی پلاگین
     *      LiteSpeed Cache. مستقیم به پلاگین می‌گوید این پاسخ را کش نکند.
     *      این قوی‌ترین روش است چون پلاگین LiteSpeed Cache قبل از هر هدر،
     *      این flag را بررسی می‌کند.
     *   ۲) $result->header() — متد رسمی WP_REST_Response برای اضافه‌کردن هدر.
     *      این هدرها به‌درستی در زمان ارسال فرستاده می‌شوند (نه header() خام).
     *
     * نکته: اگه پلاگین LiteSpeed Cache فعال نباشد، do_action یک no-op است
     * (هیچ خطایی نمی‌دهد) — پس این کد روی همهٔ هاست‌ها امن است.
     *
     * @param WP_HTTP_Response $result پاسخ REST
     * @param WP_REST_Server $server سرور REST
     * @param WP_REST_Request $request درخواست REST
     * @return WP_HTTP_Response پاسخ تغییریافته
     */
    public function force_litespeed_nocache_for_namespace($result, $server, $request) {
        $route = $request->get_route();

        // فقط برای namespace افزونه — بقیهٔ REST سایت دست‌نخورده می‌مانند
        if (strpos($route, '/mrdastbaaf/v1') !== 0) {
            return $result;
        }

        // ۱) API رسمی LiteSpeed Cache — مستقیم به پلاگین بگو کش نکن
        // (اگه پلاگین فعال نباشد، این no-op است — هیچ ضرری ندارد)
        do_action('litespeed_control_set_nocache', 'mrdastbaaf REST API');

        // اگه پلاگین LSCPS فعال نیست ولی هدر X-LiteSpeed-Cache از سرور می‌آید
        // (LiteSpeed Web Server خودش)، این هدر مستقیم به سرور می‌گوید.
        // این برای سرورهایی که LiteSpeed Web Server دارند ولی پلاگین فعال نیست.
        if (!headers_sent()) {
            header('X-LiteSpeed-Cache-Control: no-cache');
        }

        // ۲) هدرهای رسمی از طریق WP_REST_Response (روش درست وردپرس)
        if (is_object($result) && method_exists($result, 'header')) {
            $result->header('X-LiteSpeed-Cache-Control', 'no-cache');
            $result->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $result->header('Pragma', 'no-cache');
            $result->header('Expires', 'Wed, 11 Jan 1984 05:00:00 GMT');
        }

        return $result;
    }

    /**
     * v1.7.2: به‌روزرسانی content_hash وقتی پست (محصول/دوره/صفحه) ذخیره می‌شود.
     * این هش در پاسخ /version سرو می‌شود و کلاینت هر ۲ دقیقه چک می‌کند.
     */
    public function bump_content_hash($post_id, $post, $update) {
        // فقط برای محصولات ووکامرس و صفحات منتشرشده
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $this->regenerate_content_hash();
    }

    public function bump_content_hash_on_option_update($option_name, $old_value, $value) {
        // فقط گزینه‌های مرتبط با افزونه
        if (strpos($option_name, 'mrdastbaaf_') === 0) {
            $this->regenerate_content_hash();
        }
    }

    public function bump_content_hash_on_option_add($option_name, $value) {
        if (strpos($option_name, 'mrdastbaaf_') === 0) {
            $this->regenerate_content_hash();
        }
    }

    /**
     * v1.7.2: تولید هش جدید از محتوای فعلی (timestamp + random).
     * کلاینت این هش را با هش قبلی مقایسه می‌کند — اگه متفاوت بود، داده‌ها را
     * دوباره بارگذاری می‌کند.
     */
    private function regenerate_content_hash() {
        $hash = wp_generate_password(12, false) . '_' . time();
        update_option('mrdastbaaf_content_hash', $hash, false); // false = autoload غیرفعال (سبک)
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
            MrDastbaaf_Admin_Pages_Controller::PHYSICAL_SLUG,  // mrdastbaaf-physical (v1.4.0)
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

        // v1.7.2: اندپوینت نسخه — کلاینت هر ۲ دقیقه چک می‌کند. اگه نسخهٔ PWA
        // روی سرور عوض کرده باشد، کلاینت خودکار reload می‌شود (حتی اگر SW قدیمی
        // چسبیده باشد). این ریشه‌ی «کاربر نسخهٔ قدیمی می‌بیند» است.
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/version', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_version'),
            'permission_callback' => '__return_true',
        ));

        // v1.3.3: صفحات وب‌اپ
        $app_pages_controller = new MrDastbaaf_App_Pages_Controller();
        $app_pages_controller->register_routes();

        // v1.3.4: محتوای وب‌اپ (متن‌های بنر/سکشن‌ها/منو — GET /app-ui)
        $app_ui_controller = new MrDastbaaf_App_UI_Controller();
        $app_ui_controller->register_routes();

        // v1.4.0: فروشگاه (محصولات فیزیکی دست‌ساز — GET /shop/products)
        $shop_controller = new MrDastbaaf_Shop_Controller();
        $shop_controller->register_routes();

        $memberships_controller = new MrDastbaaf_Memberships_Controller();
        $memberships_controller->register_routes();

        $payment_controller = new MrDastbaaf_Payment_Controller();
        $payment_controller->register_routes();

        $admin_controller = new MrDastbaaf_Admin_Controller();
        $admin_controller->register_routes();
    }

    /**
     * v1.7.2: اندپوینت نسخه — سبک، عمومی، بدون احراز هویت.
     *
     * کلاینت هر ۲ دقیقه این اندپوینت را صدا می‌زند و نسخهٔ PWA را با نسخهٔ
     * فعلی خودش مقایسه می‌کند. اگه متفاوت بود، یعنی نسخهٔ جدید روی سرور
     * مستقر شده — کلاینت خودکار reload می‌شود (حتی اگر SW قدیمی چسبیده باشد).
     *
     * پاسخ: { pwa_version, plugin_version, db_version, server_time, build_hash }
     *
     * نکتهٔ کش: این اندپوینت با query string cache-busting فراخوانی می‌شود
     * (?_=timestamp) تا LiteSpeed Cache نتواند آن را کش کند.
     */
    public function handle_get_version(WP_REST_Request $request) {
        // هدرهای ضدکش قوی — هرچند LiteSpeed ممکن است نادیده بگیرد، اما کلاینت
        // با cache-busting query param هم آن را دور می‌زند.
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
            header('X-LiteSpeed-Cache-Control: no-cache');
        }

        return rest_ensure_response(array(
            // pwa_version: نسخهٔ PWA که کلاینت باید داشته باشد — v1.7.4: از helper
            // متمرکز خوانده می‌شود (گزینهٔ قابل‌ویرایش در پیشخوان + پیش‌فرض ثابت
            // متمرکز) تا fallback کهنهٔ '1.5.3' دیگر هیچ‌وقت برگردانده نشود.
            'pwa_version'    => (string) mrdastbaaf_get_deployed_pwa_version(),
            'plugin_version' => MRDASTBAAF_BRIDGE_VERSION,
            'db_version'     => MRDASTBAAF_DB_VERSION,
            'server_time'    => current_time('mysql'),
            'timestamp'      => time(),
            // build_hash: هش تغییرات کارفرما در پیشخوان (محتوا/قیمت‌ها/...) — هر بار
            // که چیزی ذخیره می‌شود، این هش عوض می‌شود و کلاینت reload می‌شود.
            'content_hash'   => (string) get_option('mrdastbaaf_content_hash', ''),
        ));
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

    // ─── v1.7.4: نرمال‌سازی گزینهٔ «نسخهٔ مستقر PWA» ──────────────────────────
    // ۱) seed نصب تازه: اگر گزینه وجود ندارد، با پیش‌فرض ثابت متمرکز ساخته شود
    //    (get_option با fallback دیگر در مسیر خواندن استفاده نمی‌شود تا هیچ‌وقت
    //    مقدار کهنه مثل 1.5.3 برگردانده نشود).
    // ۲) پاک‌سازی ردیف تکراری: اگر با SQL دستی (INSERT به‌جای UPDATE) ردیف
    //    دومی برای همین option_name ساخته شده باشد، get_option بسته به ترتیب
    //    خواندن ممکن است مقدار قدیمی را برگرداند و updated_option هم رفتار
    //    غیرقطعی داشته باشد — فقط قدیمی‌ترین ردیف نگه داشته می‌شود.
    //
    // این روتین فقط «یک بار» پس از آپلود افزونهٔ 1.7.4 اجرا می‌شود (فلگ
    // mrdastbaaf_options_norm_174 با autoload=yes → خواندنش از alloptions
    // کش است و هیچ کوئری اضافه‌ای در درخواست‌های عادی نمی‌سازد).
    if ('yes' !== get_option('mrdastbaaf_options_norm_174', 'no')) {
        mrdastbaaf_bridge_normalize_pwa_version_option();
        update_option('mrdastbaaf_options_norm_174', 'yes');
    }
}

/**
 * v1.7.4 — seed + dedupe گزینهٔ mrdastbaaf_pwa_version (و هش محتوا).
 * فقط یک بار پس از نصب/ارتقای 1.7.4 اجرا می‌شود (فلگ در maybe_upgrade_db).
 */
function mrdastbaaf_bridge_normalize_pwa_version_option() {
    global $wpdb;

    if (false === get_option('mrdastbaaf_pwa_version', false)) {
        add_option('mrdastbaaf_pwa_version', MRDASTBAAF_PWA_VERSION_DEFAULT, '', 'yes');
    }

    $table = $wpdb->options;
    // شناسه‌های همهٔ ردیف‌های هم‌نام — قدیمی‌ترین (کوچک‌ترین option_id) مرجع است
    $pwa_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_id FROM {$table} WHERE option_name = %s ORDER BY option_id ASC",
            'mrdastbaaf_pwa_version'
        )
    );
    if (count($pwa_ids) > 1) {
        array_shift($pwa_ids);
        foreach ($pwa_ids as $extra_id) {
            $wpdb->delete($table, array('option_id' => (int) $extra_id), array('%d'));
        }
        // مقدار معتبر ردیف باقی‌مانده را تضمین کن (اگر خالی/نامعتبر بود، پیش‌فرض)
        $current = trim((string) get_option('mrdastbaaf_pwa_version', ''));
        if ('' === $current || !preg_match('/^\d+(\.\d+)*$/', $current)) {
            update_option('mrdastbaaf_pwa_version', MRDASTBAAF_PWA_VERSION_DEFAULT);
        }
    }

    // هش محتوا هم ممکن است با SQL دستی تکرار شده باشد — همان پاک‌سازی
    $hash_ids = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT option_id FROM {$table} WHERE option_name = %s ORDER BY option_id ASC",
            'mrdastbaaf_content_hash'
        )
    );
    if (count($hash_ids) > 1) {
        array_shift($hash_ids);
        foreach ($hash_ids as $extra_id) {
            $wpdb->delete($table, array('option_id' => (int) $extra_id), array('%d'));
        }
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
