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

        // v1.2.0: گارد نقش‌های ممتاز — اگر حسابی با این شماره وجود دارد که هنرجو نیست
        // (ادمین/شاپ‌منیجر و ...)، نباید کد ورود اپ هنرجو برایش ارسال شود.
        $existing_user = self::find_user_by_phone($phone);
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
         * v1.1.0: ارسال واقعی OTP از طریق سرویس پیامک (ملی‌پیامک با الگوی BaseServiceNumber).
         * هوک سفارشی «mrdastbaaf_send_otp_sms» درون سرویس همچنان فراخوانی می‌شود تا
         * افزونه‌های ثالث بدون تغییر کار کنند. نتیجه (موفق/خطا) در آپشن
         * mrdastbaaf_sms_last_log ثبت می‌شود تا ادمین در تنظیمات ببیند.
         */
        $sms_result = MrDastbaaf_Sms_Service::send_otp($phone, $otp_code);

        $response = array(
            'success' => true,
            'message' => 'کد تایید پیامکی ارسال گردید.',
            'expires_in_seconds' => 120,
        );
        if (true !== $sms_result) {
            // حتی اگر ارسال پیامک شکست خورد، کد در سیستم معتبر است — پاسخ موفق
            // برگردانده می‌شود و فقط هشدار به کلاینت می‌رود.
            $response['smsWarning'] = is_string($sms_result) ? $sms_result : 'ارسال پیامک با خطا مواجه شد؛ کد تایید شما در سامانه معتبر است.';
        }

        return rest_ensure_response($response);
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

        // جستجوی کاربر: user_login (شماره) سپس فال‌بک billing_phone (یافتهٔ ۲-۱۵:
        // حساب‌های ساخته‌شده خارج از مسیر OTP ممکن است login شان شماره نباشد)
        $user = self::find_user_by_phone($phone);

        if (!$user) {
            // v1.2.0: ساخت کاربر با wp_insert_user و نقش صریح «subscriber» — نقش
            // قبلی از تنظیمات پیش‌فرض سایت می‌آمد (get_option default_role) و اگر
            // ادمین نقش پیش‌فرض را تغییر داده بود، هنرجوی تازه با نقش ممتاز ساخته می‌شد.
            $new_user_id = wp_insert_user(array(
                'user_login'   => $phone,
                'user_pass'    => wp_generate_password(24, true),
                'user_email'   => $phone . '@mrdastbaaf.local',
                'display_name' => 'هنرجو ' . substr($phone, -4),
                'role'         => 'subscriber',
            ));
            if (is_wp_error($new_user_id)) {
                // BUG-31: خطای wp_insert_user (معمولاً username/email تکراری) با
                // 409 و پیام فارسی برگردانده می‌شود؛ کد خطای اصلی در لاگ سرور.
                error_log('MrDastbaaf: wp_insert_user failed — code: ' . $new_user_id->get_error_code() . ' | message: ' . $new_user_id->get_error_message());
                return new WP_Error('registration_conflict', 'ساخت حساب کاربری با این شماره موبایل ممکن نشد؛ احتمالاً حسابی با این شماره وجود دارد. لطفاً دوباره تلاش کنید.', array('status' => 409));
            }
            update_user_meta($new_user_id, 'billing_phone', $phone);
            $user = get_user_by('ID', $new_user_id);
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
     * v1.2.0: یافتن کاربر با شماره موبایل — ابتدا user_login (شماره خام مسیر OTP)،
     * سپس فال‌بک meta billing_phone.
     *
     * @param string $phone شماره نرمال‌شدهٔ ۰۹…
     * @return WP_User|false
     */
    private static function find_user_by_phone($phone) {
        $user = get_user_by('login', $phone);
        if ($user) {
            return $user;
        }
        $found = get_users(array(
            'meta_key'   => 'billing_phone',
            'meta_value' => $phone,
            'number'     => 1,
            'fields'     => 'all',
        ));
        return !empty($found) ? $found[0] : false;
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
