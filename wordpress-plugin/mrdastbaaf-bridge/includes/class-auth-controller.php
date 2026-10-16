<?php
/**
 * کنترلر احراز هویت پیامکی و سشن‌های کاربری
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Auth_Controller {

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/request-otp', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_request_otp'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/verify-otp', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_verify_otp'),
            'permission_callback' => '__return_true',
        ));

        // v1.7.8: اندپوینت دیسپچ ارسال پیامک — از درخواست loopbackِ غیربلاک‌کنندهٔ
        // trigger_dispatch() فراخوانی می‌شود (فرایند PHP کاملاً جداست) تا ارسال
        // ملی‌پیامک/هوک ثالث هرگز در فرایند پاسخ request-otp اجرا نشود.
        // عمومی اما کم‌اثر: فقط ورودی‌های در انتظارِ باکس خروجی (که خود سرور نوشته)
        // را پردازش می‌کند؛ قفل ۳۰ ثانیه‌ای از اجرای موازی جلوگیری می‌کند.
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/otp-dispatch', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_otp_dispatch'),
            'permission_callback' => '__return_true',
        ));

        // v1.7.10: اندپوینت تشخیصی عمومی — وضعیت زندهٔ زنجیرهٔ OTP را بدون دسترسی
        // پیشخوان گزارش می‌دهد: تایمینگ خواندن/نوشتن DB، وضعیت باکس خروجی/قفل
        // دیسپچ، لاگ آخرین پیامک، مقدار «مؤثر» فیلتر http_request_timeout (+ نام
        // افزونه‌های مداخله‌گر) و تست اتصال خروجی به سرور ملی‌پیامک. عمومی اما
        // کم‌حجم: بدون هیچ رمزی، با سقف نرخ ۱ در ۱۰ ثانیه per-IP.
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_health'),
            'permission_callback' => '__return_true',
        ));

        // v1.2.0: permission_callback مشترک — اول maybe_authenticate_bearer سپس چک لاگین؛
        // فیلتر determine_current_user در برخی نصب‌ها دیر فایر می‌شود (یافتهٔ ۰-۲).
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/me', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_me'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));

        // v1.2.0: خروج PWA — ابطال توکن Bearer جاری (متیاداتا حذف می‌شود)
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/logout', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_logout'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));
    }

    /**
     * v1.2.0: permission مشترک اندپوینت‌های نیازمند ورود — قبل از چک is_user_logged_in
     * احراز Bearer صریحاً اجرا می‌شود تا در نصب‌هایی که فیلتر determine_current_user
     * دیر فایر می‌شود (افزونه‌ای قبل از REST_REQUEST کاربر را منجمد کرده)، توکن
     * PWA نادیده گرفته نشود.
     *
     * @return bool
     */
    public static function permission_logged_in() {
        self::maybe_authenticate_bearer();
        return is_user_logged_in();
    }

    /**
     * v1.2.0: permission مشترک اندپوینت‌های ادمین — معادل check_admin_permission
     * با احراز صریح Bearer (برای فراخوانی از کنترلرهای دیگر).
     *
     * @return bool
     */
    public static function permission_admin() {
        self::maybe_authenticate_bearer();
        return current_user_can('manage_options');
    }

    /**
     * ارسال پیامک کد تایید به شماره موبایل هنرجو
     */
    public function handle_request_otp(WP_REST_Request $request) {
        // v1.7.8: سنجش زمان مسیر پاسخ — اگر گذشت زمان از ۵ ثانیه بیشتر شد، هشدار
        // در لاگ پیشخوان ثبت می‌شود تا ریشهٔ کندی (دیتابیس/آبجکت‌کش هاست) از
        // پیشخوان قابل تشخیص باشد (ارسال پیامک در این مسیر نقشی ندارد).
        $otp_path_t0 = microtime(true);

        $phone = sanitize_text_field($request->get_param('phone'));
        $phone = preg_replace('/^(\+98|0098)/', '0', trim($phone));

        if (!preg_match('/^09[0-9]{9}$/', $phone)) {
            return new WP_Error('invalid_phone', 'شماره موبایل وارد شده معتبر نیست.', array('status' => 400));
        }

        // اعمال محدودیت نرخ درخواست (Rate Limit: 1 پیامک در ۲ دقیقه)
        $rate_key = 'mrdastbaaf_otp_rate_' . md5($phone);
        if (get_transient($rate_key)) {
            return new WP_Error('rate_limited', 'لطفاً ۲ دقیقه صبر کرده و مجدداً تلاش فرمایید.', array('status' => 429));
        }

        // ABUSE FIX (v1.2.0): سقف‌های ساعتی/روزانه مستقل per-phone — قفل ۲ دقیقه‌ای
        // تنها جلوی اسپم فوری را می‌گیرد؛ مهاجم می‌توانست هر ۲ دقیقه یک پیامک بفرستد
        // (۳۰ پیامک در ساعت به یک شماره). اکنون سقف ۵ در ساعت و ۱۵ در روز برای هر شماره.
        // NOTE (نژاد ثبت‌شده): check-then-increment با transient اتمی نیست؛ در رقابت
        // میلی‌ثانیه‌ای ممکن است ۱-۲ پیامک اضافه عبور کند — برای rate-limit قابل قبول
        // است و در نصب‌های با Redis/Memcached می‌توان به wp_cache_incr اتمی مهاجرت کرد.
        $phone_md5 = md5($phone);
        $ph_hourly_key = 'mrdastbaaf_otp_ph_h_' . $phone_md5;
        $ph_daily_key = 'mrdastbaaf_otp_ph_d_' . $phone_md5;
        $ph_hourly_count = (int) get_transient($ph_hourly_key);
        if ($ph_hourly_count >= 5) {
            return new WP_Error('rate_limited', 'تعداد کدهای ارسالی به این شماره در یک ساعت بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', array('status' => 429));
        }
        $ph_daily_count = (int) get_transient($ph_daily_key);
        if ($ph_daily_count >= 15) {
            return new WP_Error('rate_limited', 'تعداد کدهای ارسالی به این شماره در یک روز بیش از حد مجاز است؛ فردا دوباره تلاش کنید.', array('status' => 429));
        }

        // ABUSE FIX (BUG-29): سقف ساعتی بر اساس IP واقعی — مهاجم با شماره‌های مختلف
        // نمی‌تواند یک IP را برای ارسال انبوه پیامک مصرف کند (۱۰ درخواست در ساعت).
        // v1.2.0: IP از هلپر get_client_ip() خوانده می‌شود تا پشت reverse-proxy
        // (با هدر اعتمادشدهٔ تنظیم‌شده در پنل) همه یک IP مشترک نداشته باشند.
        $ip = self::get_client_ip();
        $ip_key = '';
        $ip_count = 0;
        if ($ip !== '') {
            $ip_key = 'mrdastbaaf_otp_ip_' . md5($ip);
            $ip_count = (int) get_transient($ip_key);
            if ($ip_count >= 10) {
                return new WP_Error('rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', array('status' => 429));
            }
        }

        /**
         * ABUSE FIX (v1.2.0 — تصمیم ثبت‌شده): شمارنده سراسری ساعتی دیگر بلاک نمی‌کند
         * و فقط LOG-ONLY است. دلیل: در حملهٔ توزیع‌شدهٔ چند-IP، سقف سراسری ۲۰۰/ساعت
         * کل سرویس ورود را از کار می‌انداخت (DoS از طرف مهاجم). محدودیت‌های per-phone
         * (۵/ساعت، ۱۵/روز) و per-IP (۱۰/ساعت) همچنان بلاک‌کننده می‌مانند؛ تا افزودن
         * کپچا، سقف سراسری نباید سرویس را خاموش کند.
         */
        $global_count = (int) get_option('mrdastbaaf_otp_hourly_count', 0);
        $global_window_started = (int) get_option('mrdastbaaf_otp_hourly_started', 0);
        if ($global_window_started === 0 || (time() - $global_window_started) >= HOUR_IN_SECONDS) {
            $global_count = 0;
            $global_window_started = time();
        }
        if ($global_count >= 200) {
            error_log('MrDastbaaf: global OTP hourly cap reached — possible distributed abuse');
        }

        /**
         * v1.7.9 — ریشهٔ واقعی حادثهٔ تایم‌اوت OTP (پروب‌های زندهٔ ۱۶ اکتبر):
         * فال‌بک get_users روی متای billing_phone (WP_User_Query با JOIN + DISTINCT
         * + ORDER BY روی متای بدون ایندکس) روی این هاست ۴۵-۶۰ ثانیه معلق می‌ماند و
         * LiteSpeed فرایند را پیش از «اولین نوشتن» (حتی قفل rate-limit) می‌کشد —
         * اثبات: درخواست تکراری همان شماره هرگز 429 نگرفت و پاسخ ۲۰۰ با ۰ بایت بود.
         * مسیر پاسخ اکنون فقط جست‌وجوی سریع (user_login + کش transient) را انجام
         * می‌دهد؛ فال‌بک متا فقط در verify-otp (کش سرد، با کوئری سبک get_var) و
         * در پس‌زمینه (دیسپچ، بعد از ارسال) اجرا می‌شود.
         */
        $t_find = microtime(true);
        $existing_user = self::find_user_by_phone_fast($phone);
        $find_elapsed = round(microtime(true) - $t_find, 2);
        $role_guard = self::guard_student_role($existing_user);
        if (is_wp_error($role_guard)) {
            return $role_guard;
        }

        // تولید کد ۶ رقمی (v1.2.0 — یافتهٔ ۲-۱۱: کد ۵ رقمی فضای جست‌وجوی کوچک‌تری داشت)
        $otp_code = wp_rand(100000, 999999);

        // ذخیره هش کد برای ۲ دقیقه
        $storage_key = 'mrdastbaaf_otp_val_' . md5($phone);
        set_transient($storage_key, wp_hash_password((string)$otp_code), 120);
        set_transient($rate_key, true, 120);

        // ABUSE FIX (v1.2.0): ثبت مصرف سهمیه‌های per-phone و per-IP پیش از ارسال پیامک
        set_transient($ph_hourly_key, $ph_hourly_count + 1, HOUR_IN_SECONDS);
        set_transient($ph_daily_key, $ph_daily_count + 1, DAY_IN_SECONDS);
        if ($ip_key !== '') {
            set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);
        }
        update_option('mrdastbaaf_otp_hourly_count', $global_count + 1, false);
        update_option('mrdastbaaf_otp_hourly_started', $global_window_started, false);

        /**
         * v1.7.8: مسیر پاسخ REST فقط primitives است — پروب‌های زندهٔ پروداکشن
         * (۴.۱s/۵۵.۳s با ۰ بایت پس از ۱.۷.۷) ثابت کرد روی این هاست «هر» کاری
         * کندتر از چند ثانیه در فرایند درخواست = قطع سرور + پاسخ خالی + گم‌شدن
         * لاگ است؛ حتی register_shutdown_function هم بی‌اثر بود. اکنون:
         *  - ثبت در «باکس خروجی» (transient + فلگ autoload) — دو نوشتن سریع؛
         *  - تریگر فوری دیسپچ با loopback غیربلاک‌کنندهٔ سقف‌دار ۲ ثانیه (فرایند
         *    PHP جدید ارسال را انجام می‌دهد؛ شکستش هم به پاسخ آسیب نمی‌زند)؛
         *  - خودترمیمی: init هر درخواست بعدی سایت باکسِ غیرخالی را دوباره تریگر می‌کند.
         * نتیجه با زمان‌سنجی مراحل در mrdastbaaf_sms_last_log ثبت می‌شود.
         */
        $response = array(
            'success' => true,
            'message' => 'کد تایید پیامکی ارسال گردید. اگر تا ۲ دقیقه پیامکی دریافت نشد، دوباره تلاش کنید.',
            'expires_in_seconds' => 120,
            // v1.4.0: «قبلاً ثبت‌نام کرده» — اگر برای این شماره حساب هنرجوی موجودی
            // باشد true است تا کلاینت برچسب «ورود» را به‌جای «ثبت‌نام» نشان دهد
            // (camelCase مانند smsWarning؛ گارد نقش‌ها بالاتر خطای ۴۰۳ می‌دهد).
            'isRegistered' => !empty($existing_user),
        );

        $queue_elapsed = '0';
        if (MrDastbaaf_Sms_Service::is_ready() || has_action('mrdastbaaf_send_otp_sms')) {
            // ثبت در باکس خروجی (دو نوشتن سریع) + تریگر فوری loopback سقف‌دار
            $t_queue = microtime(true);
            MrDastbaaf_Sms_Service::queue_outbox_send($phone, $otp_code);
            $queue_elapsed = round(microtime(true) - $t_queue, 2);

            /**
             * v1.7.10: ردیف زمان‌سنجی «همیشه-روشن» اکنون «قبل از» تریگر دیسپچ
             * نوشته می‌شود (در ۱.۷.۹ بعد از آن بود). پروب زندهٔ ۱۶ اکتبر: تریگر
             * loopback ~۴۷ ثانیه معلق ماند و فرایند پیش از نوشتن لاگ کشته شد =
             * لاگ یخ‌زدهٔ پیشخوان که کارفرما دید. با این ترتیب، هر تلاش ورود —
             * در هر شرایطی — ردیف تازه‌ای در لاگ می‌گذارد و اگر تریگر/ارسال
             * بعدش گم شود، خودِ فاصلهٔ زمانی ردیف‌ها مکان تعلیق را نشان می‌دهد.
             */
            $otp_path_elapsed = round(microtime(true) - $otp_path_t0, 2);
            $request_phone_masked = substr($phone, 0, 4) . '***' . substr($phone, -4);
            MrDastbaaf_Sms_Service::write_admin_log(
                'اطلاع — درخواست کد برای ' . $request_phone_masked . ' در ' . $otp_path_elapsed . 's تکمیل شد'
                . ' (جست‌وجوی شماره: ' . $find_elapsed . 's، باکس خروجی: ' . $queue_elapsed . 's)؛ ارسال در فرایند جدا ادامه دارد.'
            );

            MrDastbaaf_Sms_Service::trigger_dispatch(true);
        } else {
            // سرویس تنظیم نشده و افزونهٔ ثالثی هم روی هوک نیست — هشدار فوری بدون هیچ تماس شبکه‌ای
            $response['smsWarning'] = 'سرویس پیامک هنوز تنظیم نشده است؛ کد تایید شما در سامانه معتبر است اما پیامکی ارسال نشد.';

            $otp_path_elapsed = round(microtime(true) - $otp_path_t0, 2);
            $request_phone_masked = substr($phone, 0, 4) . '***' . substr($phone, -4);
            MrDastbaaf_Sms_Service::write_admin_log(
                'اطلاع — درخواست کد برای ' . $request_phone_masked . ' در ' . $otp_path_elapsed . 's تکمیل شد'
                . ' (جست‌وجوی شماره: ' . $find_elapsed . 's)؛ اما سرویس پیامک تنظیم نشده است.'
            );
        }

        return rest_ensure_response($response);
    }

    /**
     * v1.7.8: اجرای دیسپچ ارسال OTP در فرایند PHP جدا (loopback/کرون).
     * پاسخ فقط خلاصهٔ آماری است؛ خودِ ارسال در همین فرایندِ بدونِ منتظر انجام می‌شود.
     */
    public function handle_otp_dispatch() {
        return rest_ensure_response(MrDastbaaf_Sms_Service::handle_dispatch());
    }

    /**
     * v1.7.10: اندپوینت تشخیصی سلامت زنجیرهٔ OTP — GET /auth/health
     *
     * هدف: عیب‌یابی از بیرون، بدون دسترسی پیشخوان و بدون حدس زدن. هر مؤلفهٔ
     * مشکوکِ زنجیره با عدد/وضعیت صریح گزارش می‌شود:
     *  - db_read_ms / db_write_ms: کندی دیتابیس/قفل جدول آپشن‌ها (ریشهٔ احتمالی
     *    تعلیق‌های ~۵۰ ثانیه‌ای — innodb_lock_wait_timeout پیش‌فرض ۵۰s است)
     *  - outbox/dispatch_lock: صف ارسال‌های گم‌شده و دیسپچ‌های گیرکرده
     *  - sms_last_log: متن کامل «آخرین وضعیت ارسال پیامک» (همان که در پیشخوان دیده می‌شود)
     *  - http_request_timeout.effective: اگر افزونه‌ای سقف ۲ ثانیهٔ تریگر را بالا
     *    برده باشد، اینجا عدد واقعی + نام کالبک‌ها دیده می‌شود
     *  - melipayamak_outbound: تست TCP خام (۳ ثانیه سقف) به سرور ملی‌پیامک —
     *    فایروال خروجی هاست را بدون حدس نشان می‌دهد
     *
     * امنیت: هیچ رمز/توکنی در پاسخ نیست؛ شماره‌ها در لاگ ماسک‌شده‌اند؛ سقف نرخ
     * ۱ درخواست در ۱۰ ثانیه per-IP؛ LiteSpeed nocache (namespace-wide) اعمال است.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_health() {
        $ip = self::get_client_ip();
        $rl_key = 'mrdastbaaf_health_rl_' . md5($ip !== '' ? $ip : 'anon');
        if (get_transient($rl_key)) {
            return new WP_Error('rate_limited', 'برای سلامت‌سنجی هر ۱۰ ثانیه یک‌بار مجاز است.', array('status' => 429));
        }
        set_transient($rl_key, 1, 10);

        $t_all = microtime(true);

        // ۱) تایمینگ خواندن DB (transient ناموجود = خالص‌ترین خواندن)
        $t = microtime(true);
        get_transient('mrdastbaaf_health_probe_missing');
        $db_read_ms = round((microtime(true) - $t) * 1000, 1);

        // ۲) تایمینگ نوشتن+حذف DB (قفل جدول آپشن‌ها را آشکار می‌کند)
        $t = microtime(true);
        set_transient('mrdastbaaf_health_probe', 1, 60);
        delete_transient('mrdastbaaf_health_probe');
        $db_write_ms = round((microtime(true) - $t) * 1000, 1);

        // ۳) محیط فیلتر http_request_timeout — مقدار مؤثر + کالبک‌های ثبت‌شده
        $effective_timeout = apply_filters('http_request_timeout', 2, home_url('/'));
        $timeout_callbacks = array();
        if (isset($GLOBALS['wp_filter']['http_request_timeout'])) {
            foreach ($GLOBALS['wp_filter']['http_request_timeout']->callbacks as $prio => $cbs) {
                foreach ($cbs as $cb) {
                    $fn = isset($cb['function']) ? $cb['function'] : null;
                    if (is_string($fn)) {
                        $label = $fn;
                    } elseif (is_array($fn) && count($fn) === 2) {
                        $label = (is_object($fn[0]) ? get_class($fn[0]) : (string) $fn[0]) . '::' . (string) $fn[1];
                    } elseif (is_object($fn) && $fn instanceof Closure) {
                        $label = 'Closure';
                    } else {
                        $label = 'unknown';
                    }
                    $timeout_callbacks[] = $label . '@' . (string) $prio;
                }
            }
        }

        // ۴) تست اتصال خروجی به سرور ملی‌پیامک (TCP خام، بدون ارسال داده، سقف ۳ ثانیه)
        $t = microtime(true);
        $mel_conn = @fsockopen('tls://rest.payamak-panel.com', 443, $mel_errno, $mel_err, 3);
        $mel_ms = round((microtime(true) - $t) * 1000);
        if ($mel_conn) {
            fclose($mel_conn);
            $melipayamak_outbound = array('ok' => true, 'ms' => $mel_ms);
        } else {
            $melipayamak_outbound = array('ok' => false, 'errno' => (int) $mel_errno, 'error' => (string) $mel_err, 'ms' => $mel_ms);
        }

        // ۵) وضعیت باکس خروجی / قفل دیسپچ / لاگ پیامک
        $outbox = get_transient(MrDastbaaf_Sms_Service::OUTBOX_TRANSIENT);

        $payload = array(
            'plugin_version'   => defined('MRDASTBAAF_BRIDGE_VERSION') ? MRDASTBAAF_BRIDGE_VERSION : 'unknown',
            'server_time'      => current_time('mysql'),
            'db_read_ms'       => $db_read_ms,
            'db_write_ms'      => $db_write_ms,
            'object_cache'     => wp_using_ext_object_cache() ? 'external' : 'db-transients',
            'outbox'           => array(
                'pending_flag' => MrDastbaaf_Sms_Service::has_pending_outbox(),
                'entries'      => is_array($outbox) ? count($outbox) : 0,
            ),
            'dispatch_lock'    => (bool) get_transient(MrDastbaaf_Sms_Service::DISPATCH_LOCK_TRANSIENT),
            'sms'              => array(
                'provider' => (string) get_option('mrdastbaaf_sms_provider', 'none'),
                'ready'    => MrDastbaaf_Sms_Service::is_ready(),
            ),
            'sms_last_log'     => (string) get_option('mrdastbaaf_sms_last_log', ''),
            'http_request_timeout' => array(
                'requested' => 2,
                'effective' => $effective_timeout,
                'callbacks' => $timeout_callbacks,
            ),
            'melipayamak_outbound' => $melipayamak_outbound,
            'total_ms'         => round((microtime(true) - $t_all) * 1000, 1),
        );

        return rest_ensure_response($payload);
    }

    /**
     * اعتبارسنجی کد تایید و ورود هنرجو
     *
     * v1.2.0 (یافتهٔ ۱-۵): ورود صرفاً Bearer است — wp_set_current_user/wp_set_auth_cookie
     * حذف شدند (کوکی وردپرس برای اپ PWA لازم نیست و سشن سرور اضافه‌ای می‌ساخت).
     */
    public function handle_verify_otp(WP_REST_Request $request) {
        $phone = sanitize_text_field($request->get_param('phone'));
        $otp_code = sanitize_text_field($request->get_param('otp_code'));

        $phone = preg_replace('/^(\+98|0098)/', '0', trim($phone));
        $storage_key = 'mrdastbaaf_otp_val_' . md5($phone);

        // SECURITY FIX (BUG-02): brute-force protection.
        // The old endpoint had NO attempt limit: a 6-digit code (900,000 combinations)
        // valid for 120 seconds could still be brute-forced by hammering this endpoint.
        // We now allow at most 5 verification attempts per issued code and burn
        // the code (forcing a new SMS request) as soon as the budget is exhausted.
        $attempts_key = 'mrdastbaaf_otp_attempts_' . md5($phone);
        $attempts = $this->get_otp_attempts($attempts_key); // BUG-28: خواندن از منبع مشترک شمارنده
        if ($attempts >= 5) {
            delete_transient($storage_key); // کد سوخته می‌شود؛ ورود فقط با کد جدید ممکن است
            return new WP_Error('otp_locked', 'تعداد تلاش‌های نامعتبر بیش از حد مجاز بود. لطفاً کد تایید جدید درخواست کنید.', array('status' => 429));
        }

        $hashed_code = get_transient($storage_key);

        if (!$hashed_code || !wp_check_password($otp_code, $hashed_code)) {
            // SECURITY FIX (BUG-28): افزایش «اتمی» شمارنده — الگوی قبلی check-then-set
            // با درخواست‌های هم‌زمان دور زده می‌شد و بودجه ۵ تلاشی نقض می‌گشت.
            $attempts = $this->incr_otp_attempts($attempts_key);
            $remaining = 5 - $attempts;
            // v1.2.0 (یافتهٔ ۱-۶): وضعیت 400 به‌جای 401 — فرانت هر 401 را «جلسه منقضی»
            // تفسیر می‌کند و پیام «تلاش باقی‌مانده» را می‌بلعید؛ کد OTP نامعتبر خطای
            // درخواست است نه سشن.
            return new WP_Error('invalid_otp', 'کد تایید وارد شده نامعتبر یا منقضی شده است.' . ($remaining > 0 ? ' تلاش باقی‌مانده: ' . $remaining : ''), array('status' => 400));
        }

        // کد مصرف شد؛ بودجه تلاش‌ها نیز پاک می‌شود
        delete_transient($storage_key);
        delete_transient($attempts_key);
        wp_cache_delete($attempts_key, 'mrdastbaaf_otp'); // BUG-28: پاک‌سازی شمارنده در آبجکت‌کش

        // v1.4.0: نام و نام‌خانوادگی اختیاری — فیلدهای ورودی پاکسازی می‌شوند
        // (فقط حروف فارسی/فاصله/نیم‌فاصله می‌مانند؛ حداکثر ۶۰ کاراکتر). ورودی
        // اختیاری است: نام‌های نامعتبر بی‌صدا دور ریخته می‌شوند و هرگز جریان
        // ورود کاربران فعلی را نمی‌شکنند.
        $first_name = $this->sanitize_person_name($request->get_param('first_name'));
        $last_name = $this->sanitize_person_name($request->get_param('last_name'));
        $display_name = trim($first_name . ' ' . $last_name);

        // جستجوی کاربر: user_login (شماره) سپس فال‌بک billing_phone (یافتهٔ ۲-۱۵:
        // حساب‌های ساخته‌شده خارج از مسیر OTP ممکن است login شان شماره نباشد)
        // v1.7.9: اول جست‌وجوی سریع (user_login + کش)؛ فال‌بک متا فقط در کش سرد
        // و با کوئری سبک get_var (LIMIT 1) به‌جای WP_User_Query سنگین.
        $t_find = microtime(true);
        $user = self::find_user_by_phone_fast($phone);
        if (!$user) {
            $user = self::find_user_by_phone_full($phone);
        }
        $find_elapsed = round(microtime(true) - $t_find, 2);
        if ($find_elapsed > 3) {
            MrDastbaaf_Sms_Service::write_admin_log(
                'هشدار — جست‌وجوی شماره در verify-otp کند بود (' . $find_elapsed . 's)؛ ریشهٔ احتمالی: متای billing_phone بدون ایندکس/کندی دیتابیس هاست.'
            );
        }

        if (!$user) {
            // v1.2.0: ساخت کاربر با wp_insert_user و نقش صریح «subscriber» — نقش
            // قبلی از تنظیمات پیش‌فرض سایت می‌آمد (get_option default_role) و اگر
            // ادمین نقش پیش‌فرض را تغییر داده بود، هنرجوی تازه با نقش ممتاز ساخته می‌شد.
            // v1.4.0: نام واقعی (در صورت ارسال) جای برچسب پیش‌فرض «هنرجو NNNN» می‌نشیند.
            $new_user_id = wp_insert_user(array(
                'user_login'   => $phone,
                'user_pass'    => wp_generate_password(24, true),
                'user_email'   => $phone . '@mrdastbaaf.local',
                'display_name' => ('' !== $display_name) ? $display_name : 'هنرجو ' . substr($phone, -4),
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'role'         => 'subscriber',
            ));
            if (is_wp_error($new_user_id)) {
                // BUG-31: خطای wp_insert_user (معمولاً username/email تکراری) با
                // 409 و پیام فارسی برگردانده می‌شود؛ کد خطای اصلی در لاگ سرور.
                error_log('MrDastbaaf: wp_insert_user failed — code: ' . $new_user_id->get_error_code() . ' | message: ' . $new_user_id->get_error_message());
                return new WP_Error('registration_conflict', 'ساخت حساب کاربری با این شماره موبایل ممکن نشد؛ احتمالاً حسابی با این شماره وجود دارد. لطفاً دوباره تلاش کنید.', array('status' => 409));
            }
            update_user_meta($new_user_id, 'billing_phone', $phone);
            // v1.4.0: متیاهای صورتحساب ووکامرس — تطبیق سفارش‌های مهمانِ آینده با
            // نام واقعی در فاکتور؛ فقط مقادیر غیرخالی نوشته می‌شوند.
            if ('' !== $first_name) {
                update_user_meta($new_user_id, 'billing_first_name', $first_name);
            }
            if ('' !== $last_name) {
                update_user_meta($new_user_id, 'billing_last_name', $last_name);
            }
            $user = get_user_by('ID', $new_user_id);
            // v1.7.9: کش مثبت شماره→کاربر — ورودهای بعدی هرگز به فال‌بک متا نمی‌رسند
            if ($user) {
                set_transient('mrdastbaaf_phone_uid_' . md5($phone), (int) $new_user_id, 12 * HOUR_IN_SECONDS);
            }
        }

        if (!$user) {
            // گارد دفاعی: پس از ساخت/یافتن، رکورد کاربر باید موجود باشد
            return new WP_Error('invalid_user', 'حساب کاربری شما یافت نشد؛ لطفاً دوباره وارد شوید.', array('status' => 401));
        }

        // v1.2.0: گارد نقش‌های ممتاز — حساب‌های مدیریتی/فروشگاهی از ورود اپ هنرجو
        // (OTP) خارج هستند تا با انبوه‌سازی شماره‌ها، توکن ممتاز فیش نشود.
        $role_guard = self::guard_student_role($user);
        if (is_wp_error($role_guard)) {
            return $role_guard;
        }

        // v1.4.0: نام برای کاربرِ موجود — display_name هرگز بی‌دلیل عوض نمی‌شود؛
        // فقط متیاهای صورتحساب ووکامرس (فاکتور) به‌روز می‌شوند. استثنا («خوددرمانی»):
        // اگر نام فعلی همان برچسب پیش‌فرض «هنرجو NNNN» باشد و نام تازه رسیده باشد،
        // نام واقعی جایگزین می‌شود تا کاربرِ ساخته‌شده با نسخهٔ قدیمی هم نام بگیرد.
        if ('' !== $first_name || '' !== $last_name) {
            if ('' !== $first_name) {
                update_user_meta($user->ID, 'billing_first_name', $first_name);
            }
            if ('' !== $last_name) {
                update_user_meta($user->ID, 'billing_last_name', $last_name);
            }
            if ('' !== $display_name && preg_match('/^هنرجو \d{4}$/u', (string) $user->display_name)) {
                $user_update = array(
                    'ID'           => $user->ID,
                    'display_name' => $display_name,
                );
                if ('' !== $first_name) {
                    $user_update['first_name'] = $first_name;
                }
                if ('' !== $last_name) {
                    $user_update['last_name'] = $last_name;
                }
                wp_update_user($user_update);
                $user = get_user_by('ID', $user->ID); // تازه‌سازی برای پاسخ
            }
        }

        // v1.2.0 (یافتهٔ ۲-۱۲): توکن سخت‌شده — فرمت «{user_id}.{secret}»؛ سرور فقط
        // hash('sha256', secret) را در usermeta نگه می‌دارد (نشت DB ≠ استفاده مجدد
        // از توکن) و مقایسه با hash_equals زمان-ثابت انجام می‌شود. هر ورود regenerate
        // می‌شود و ۳۰ روز اعتبار دارد. شکل پاسخ verify-otp تغییر نکرده است.
        $secret = wp_generate_password(32, false, false);
        $api_token = $user->ID . '.' . $secret;
        update_user_meta($user->ID, 'mrdastbaaf_api_token', hash('sha256', $secret));
        update_user_meta($user->ID, 'mrdastbaaf_api_token_issued', time());

        return rest_ensure_response(array(
            'success' => true,
            'token' => $api_token,
            'user' => $this->format_user_profile($user),
        ));
    }

    /**
     * v1.2.0: خروج PWA — ابطال توکن Bearer با حذف هر دو متیای توکن.
     * (کوکی سشن وردپرس مسیر جداگانه‌ای است و در این اندپوینت دست نمی‌خورد.)
     */
    public function handle_logout() {
        $user_id = self::maybe_authenticate_bearer();
        if ($user_id <= 0) {
            return new WP_Error('unauthorized', 'برای خروج ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
        }

        delete_user_meta($user_id, 'mrdastbaaf_api_token');
        delete_user_meta($user_id, 'mrdastbaaf_api_token_issued');

        // v1.2.0: ردپای ممیزی خروج
        MrDastbaaf_Admin_Controller::write_audit('auth_logout', 'user', (string) $user_id, 'خروج از اپ هنرجو و ابطال توکن Bearer');

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'با موفقیت از حساب کاربری خارج شدید.',
        ));
    }

    /**
     * v1.4.0: پاکسازی نام اختیاری هنرجو — تگ‌ها حذف، فقط حروف فارسی/فاصله/نیم‌فاصله
     * (ZWNJ) باقی می‌مانند؛ فاصله‌های تکراری جمع و سقف ۶۰ کاراکتر اعمال می‌شود.
     * ورودی اختیاری است — رشتهٔ خالی یعنی «نام ارسال نشده/معتبر نیست».
     *
     * @param mixed $raw مقدار خام پارامتر
     * @return string نام پاکسازی‌شده یا رشتهٔ خالی
     */
    private function sanitize_person_name($raw) {
        if (null === $raw) {
            return '';
        }
        $name = wp_strip_all_tags((string) $raw);
        // فقط محدودهٔ حروف فارسی/عربی + نیم‌فاصله + فاصله‌ها مجاز می‌مانند
        $name = preg_replace('/[^\x{0600}-\x{06FF}\x{200C}\s]+/u', '', (string) $name);
        $name = preg_replace('/\s+/u', ' ', (string) $name);
        $name = trim((string) $name);
        if ('' === $name) {
            return '';
        }
        return mb_substr($name, 0, 60);
    }

    /**
     * v1.7.9: جست‌وجوی سریع شماره → کاربر — فقط مسیرهای «پاسخ» (request-otp).
     *  ۱) user_login = شماره (ایندکس یکتا، سریع همیشه) — حساب‌های ساخته‌شده از PWA؛
     *  ۲) کش transient شماره→ID (مثبت: ۱۲ ساعت) — حاصل فال‌بک قبلی/ساخت حساب.
     * هرگز WP_User_Query/فال‌بک متا را اجرا نمی‌کند — ریشهٔ تعلیق ۴۵-۶۰ ثانیه‌ای
     * حادثهٔ تایم‌اوت همین بود.
     *
     * @param string $phone شمارهٔ نرمال‌شده (09xxxxxxxxx)
     * @return WP_User|false
     */
    public static function find_user_by_phone_fast($phone) {
        $user = get_user_by('login', $phone);
        if ($user) {
            return $user;
        }
        $cached_id = (int) get_transient('mrdastbaaf_phone_uid_' . md5($phone));
        if ($cached_id > 0) {
            $cached_user = get_user_by('ID', $cached_id);
            if ($cached_user) {
                return $cached_user;
            }
        }
        return false;
    }

    /**
     * v1.7.9: جست‌وجوی کامل شماره → کاربر — فقط کش سرد در verify-otp و پس‌زمینه
     * (دیسپچ، بعد از ارسال). به‌جای WP_User_query سنگین (JOIN + DISTINCT + ORDER BY
     * روی متای بدون ایندکس — ریشهٔ تعلیق ۴۵-۶۰ ثانیه‌ای)، کوئری سبک get_var با
     * LIMIT 1 اجرا می‌شود و نتیجه (مثبت/منفی) کش می‌شود.
     *
     * کش منفی فقط ۱۰ دقیقه است تا حساب ووکامرسی تازه‌ساخت‌شده با همان شماره
     * حداکثر ۱۰ دقیقه بعد در مسیر OTP قابل یافتن باشد.
     *
     * @param string $phone شمارهٔ نرمال‌شده (09xxxxxxxxx)
     * @return WP_User|false
     */
    public static function find_user_by_phone_full($phone) {
        $fast = self::find_user_by_phone_fast($phone);
        if ($fast) {
            return $fast;
        }

        global $wpdb;
        $t_meta = microtime(true);
        $uid = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = 'billing_phone' AND meta_value = %s LIMIT 1",
            $phone
        ));
        $meta_elapsed = round(microtime(true) - $t_meta, 2);

        // v1.7.9: تشخیص پایدار — اگر حتی کوئری سبک کند بود، ریشه (ایندکس/دیتابیس هاست)
        // از پیشخوان قابل مشاهده است و راه‌حل نهایی (ایندکس دستی یا جدول نگاشت) تصمیم‌گیری می‌شود.
        if ($meta_elapsed > 3) {
            MrDastbaaf_Sms_Service::write_admin_log(
                'هشدار — فال‌بک متای billing_phone کند بود (' . $meta_elapsed . 's)؛ ریشهٔ احتمالی: نبود ایندکس روی متا یا کندی دیتابیس هاست.'
            );
        }

        if ($uid > 0) {
            set_transient('mrdastbaaf_phone_uid_' . md5($phone), $uid, 12 * HOUR_IN_SECONDS);
            $user = get_user_by('ID', $uid);
            if ($user) {
                return $user;
            }
        }

        // کش منفی کوتاه — شمارهٔ واقعاً جدید دوباره اسکن نمی‌شود
        set_transient('mrdastbaaf_phone_uid_' . md5($phone), 0, 10 * MINUTE_IN_SECONDS);
        return false;
    }

    /**
     * v1.2.0: گارد نقش‌های ممتاز — ورود OTP فقط برای هنرجویان (subscriber/customer)
     * مجاز است؛ سایر نقش‌ها (ادمین، ادیتور، شاپ‌منیجر و ...) با 403 رد می‌شوند.
     *
     * @param WP_User|false $user
     * @return true|WP_Error true در صورت مجاز بودن
     */
    private static function guard_student_role($user) {
        if (!$user) {
            return true;
        }
        $roles = is_array($user->roles) ? $user->roles : array();
        $privileged = array_diff($roles, array('subscriber', 'customer'));
        if (!empty($privileged)) {
            return new WP_Error('role_not_allowed', 'ورود با این شماره به اپ هنرجو مجاز نیست؛ لطفاً با پشتیبانی تماس بگیرید.', array('status' => 403));
        }
        return true;
    }

    public function handle_get_me() {
        // v1.1.0: پشتیبانی Bearer — در کنار کوکی، توکن هدر Authorization نیز بررسی می‌شود
        self::maybe_authenticate_bearer();

        $user_id = get_current_user_id();
        $user = get_user_by('ID', $user_id);
        // BUG-30: کوکی احراز هویت معتبر اما رکورد کاربر حذف‌شده → قبلاً fatal (500)
        // روی $user->user_login رخ می‌داد؛ اکنون 401 تمیز برگردانده می‌شود.
        if (!$user) {
            return new WP_Error('invalid_user', 'حساب کاربری شما یافت نشد؛ لطفاً دوباره وارد شوید.', array('status' => 401));
        }
        return rest_ensure_response($this->format_user_profile($user));
    }

    /**
     * (منسوخ در v1.2.0) جایگزین: permission_logged_in() — این متد برای سازگاری
     * فراخوانی‌های ثالث که ممکن است مستقیماً صدا بزنند حفظ شده است.
     */
    public function check_user_logged_in() {
        return is_user_logged_in();
    }

    /**
     * v1.1.0: استخراج توکن opaque از هدر «Authorization: Bearer …»
     * (برای سازگاری با آپاچی‌های بدون گذر هدر، REDIRECT_HTTP_AUTHORIZATION هم چک می‌شود)
     * v1.2.0: فرمت توکن «{user_id}.{secret}» — نقطه در کاراکترهای مجاز regex هست
     * و تغییر نیکرده است.
     *
     * @return string توکن یا رشتهٔ خالی
     */
    public static function get_bearer_token() {
        $header = '';
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_AUTHORIZATION']));
        } elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = sanitize_text_field(wp_unslash((string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION']));
        }
        if ('' === $header) {
            return '';
        }
        if (!preg_match('/^Bearer\s+([A-Za-z0-9._\-]+)$/i', $header, $matches)) {
            return '';
        }
        return $matches[1];
    }

    /**
     * v1.2.0 (یافتهٔ ۲-۱۲): بازیابی کاربر از توکن — توکن به شکل «{user_id}.{secret}»
     * است؛ شناسهٔ کاربر مستقیماً پارس می‌شود (بدون get_users پرمخاطب روی meta_value)
     * و secret با hash_equals زمان-ثابت با هش sha256 ذخیره‌شده مقایسه می‌شود.
     * توکن منقضی (۳۰ روز) = نامعتبر (fail-closed).
     *
     * @param string $token توکن دریافتی از هدر
     * @return WP_User|null
     */
    private static function get_user_by_api_token($token) {
        if ('' === $token) {
            return null;
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        $user_id = absint($parts[0]);
        $secret = (string) $parts[1];
        if ($user_id <= 0 || '' === $secret) {
            return null;
        }
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return null;
        }
        $stored_hash = (string) get_user_meta($user->ID, 'mrdastbaaf_api_token', true);
        if ('' === $stored_hash || !hash_equals($stored_hash, hash('sha256', $secret))) {
            return null;
        }
        $issued = (int) get_user_meta($user->ID, 'mrdastbaaf_api_token_issued', true);
        if ($issued <= 0 || (time() - $issued) > (30 * DAY_IN_SECONDS)) {
            return null; // توکن منقضی است
        }
        return $user;
    }

    /**
     * v1.1.0: هوک determine_current_user — اگر کوکی کاربر را تعیین نکرده باشد،
     * هدر Bearer بررسی و کاربر از usermeta بازیابی می‌شود. فقط در درخواست‌های REST
     * فعال است تا روی فرانت‌اند/پنل اثر جانبی نداشته باشد.
     *
     * v1.2.0 (یافتهٔ ۰-۲): محدودسازی namespace — Bearer تنها وقتی بررسی می‌شود که
     * درخواست متعلق به namespace افزونه (…/wp-json/mrdastbaaf/v1 یا ?rest_route=/mrdastbaaf/v1…)
     * باشد؛ در سایر مسیرهای REST سایت (وب‌سرویس‌های ثالث) توکن ما اصلاً نگاه نمی‌شود.
     *
     * @param int|false $user_id شناسهٔ کاربر تعیین‌شده توسط فیلترهای قبلی
     * @return int|false
     */
    public static function determine_current_user_from_bearer($user_id) {
        if ((int) $user_id > 0) {
            return $user_id; // احراز قبلی (کوکی/اپلیکیشن‌پسورد) اولویت دارد
        }
        if (!defined('REST_REQUEST') || !REST_REQUEST) {
            return $user_id;
        }

        // v1.2.0: گارد namespace — فقط روی اندپوینت‌های خودمان فعال شو
        $is_our_namespace = false;
        if (isset($_SERVER['REQUEST_URI']) && strpos((string) $_SERVER['REQUEST_URI'], '/' . rest_get_url_prefix() . '/mrdastbaaf/v1') !== false) {
            $is_our_namespace = true;
        } elseif (isset($_GET['rest_route']) && strpos((string) $_GET['rest_route'], '/mrdastbaaf/v1') === 0) {
            $is_our_namespace = true;
        }
        if (!$is_our_namespace) {
            return $user_id;
        }

        $user = self::get_user_by_api_token(self::get_bearer_token());
        if ($user) {
            return (int) $user->ID;
        }
        return $user_id;
    }

    /**
     * v1.1.0: گارد درون‌هندلری — اگر فیلتر determine_current_user زودتر اجرا نشده
     * باشد (فراخوانی مستقیم هندلرها خارج از چرخهٔ REST)، هدر Bearer اینجا بررسی و
     * کاربر جاری ست می‌شود. در حالت عادی بی‌اثر است.
     *
     * @return int شناسهٔ کاربر جاری (۰ برای مهمان)
     */
    public static function maybe_authenticate_bearer() {
        if ((int) get_current_user_id() > 0) {
            return (int) get_current_user_id();
        }
        $user = self::get_user_by_api_token(self::get_bearer_token());
        if ($user) {
            wp_set_current_user((int) $user->ID);
        }
        return (int) get_current_user_id();
    }

    /**
     * v1.2.0 (یافتهٔ ۲-۱۱): IP واقعی کلاینت — پشت reverse-proxy همه درخواست‌ها
     * REMOTE_ADDR مشترک دارند و سقف per-IP بی‌اثر می‌شود. اگر ادمین در تنظیمات
     * هدر اعتمادشده (مثلاً X-Forwarded-For) ست کرده باشد، اولین IP فهرست (کلاینت
     * اصلی) خوانده می‌شود؛ در غیر این صورت REMOTE_ADDR. خروجی با filter_var
     * اعتبارسنجی می‌شود (هدر قابل جعل است — فقط برای rate-limit استفاده می‌شود،
     * نه تصمیم امنیتی دیگری).
     *
     * @return string IP معتبر یا رشتهٔ خالی
     */
    public static function get_client_ip() {
        $raw = '';
        $header = trim((string) get_option('mrdastbaaf_trusted_proxy_header', ''));
        if ('' !== $header) {
            $value = '';
            if (isset($_SERVER[$header])) {
                $value = (string) $_SERVER[$header];
            } else {
                // نام هدر HTTP استاندارد (مثل X-Forwarded-For) در PHP با پیشوند
                // HTTP_ و زیرخط ذخیره می‌شود؛ هر دو شکل پشتیبانی می‌شوند.
                $http_key = 'HTTP_' . strtoupper(str_replace('-', '_', $header));
                if (isset($_SERVER[$http_key])) {
                    $value = (string) $_SERVER[$http_key];
                }
            }
            if ('' !== $value) {
                $chain = explode(',', $value);
                $raw = trim($chain[0]); // اولین IP = کلاینت اصلی
            }
        }
        if ('' === $raw && isset($_SERVER['REMOTE_ADDR'])) {
            $raw = (string) $_SERVER['REMOTE_ADDR'];
        }
        $ip = sanitize_text_field(wp_unslash($raw));
        if ('' === $ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return '';
        }
        return $ip;
    }

    /**
     * BUG-28: خواندن شمارنده تلاش‌های OTP از منبع مشترک
     */
    private function get_otp_attempts($key) {
        if (wp_using_ext_object_cache()) {
            $cached = wp_cache_get($key, 'mrdastbaaf_otp');
            return (false === $cached) ? 0 : (int) $cached;
        }
        // fallback (BUG-28): بدون آبجکت‌کش خارجی از transient استفاده می‌شود
        return (int) get_transient($key);
    }

    /**
     * BUG-28: افزایش شمارنده تلاش‌های OTP.
     *
     * با آبجکت‌کش خارجی (Redis/Memcached) افزایش با wp_cache_incr اتمی است و
     * درخواست‌های هم‌زمان نمی‌توانند بودجه ۵ تلاشی را دور بزنند.
     * FALLBACK (سندشده): در نبود آبجکت‌کش خارجی، همان منطق transient قبلی
     * (get_transient + set_transient؛ check-then-set) اجرا می‌شود — این ضعف
     * تنها در نصب‌های بدون Redis/Memcached و در رقابت میلی‌ثانیه‌ای باقی می‌ماند.
     */
    private function incr_otp_attempts($key) {
        if (wp_using_ext_object_cache()) {
            $incremented = wp_cache_incr($key, 1, 'mrdastbaaf_otp');
            if (false === $incremented) {
                // کلید هنوز وجود نداشت — مقداردهی اولیه با TTL بودجه ۵ دقیقه‌ای
                wp_cache_set($key, 1, 'mrdastbaaf_otp', 300);
                return 1;
            }
            return (int) $incremented;
        }

        $attempts = (int) get_transient($key);
        $attempts++;
        set_transient($key, $attempts, 300); // بودجه تلاش: ۵ دقیقه
        return $attempts;
    }

    private function format_user_profile($user) {
        global $wpdb;
        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';

        $phone = $user->user_login;
        $masked_phone = substr($phone, 0, 4) . '***' . substr($phone, -4);

        // استعلام آخرین اشتراک فعال
        $now = current_time('mysql');
        $membership = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_memberships 
             WHERE user_id = %d AND status = 'active' AND expires_at > %s 
             ORDER BY expires_at DESC LIMIT 1",
            $user->ID, $now
        ));

        $membership_data = null;
        if ($membership) {
            // TIMEZONE FIX (فاز ۳): تاریخ‌ها با current_time ذخیره می‌شوند؛ مقایسه باید
            // با مرجع زمان محلی باشد نه time() واقعی (UTC) — وگرنه روزهای باقی‌مانده drift می‌کند.
            $diff_seconds = strtotime($membership->expires_at) - current_time('timestamp');
            $days_remaining = max(0, ceil($diff_seconds / (60 * 60 * 24)));

            $membership_data = array(
                'tier' => $membership->tier,
                'planId' => $membership->plan_id,
                'planName' => 'اشتراک ' . $membership->tier,
                'status' => 'active',
                'startedAt' => $membership->started_at,
                'expiresAt' => $membership->expires_at,
                'formattedExpireDateJalali' => date_i18n('Y/m/d', strtotime($membership->expires_at)),
                'daysRemaining' => $days_remaining,
            );
        }

        $roles = (array) $user->roles;
        $role = in_array('administrator', $roles, true) ? 'admin' : 'student';

        return array(
            'id' => (string) $user->ID,
            'phoneNumber' => $phone,
            'maskedPhone' => $masked_phone,
            'fullName' => $user->display_name,
            'role' => $role,
            'membership' => $membership_data,
        );
    }
}
