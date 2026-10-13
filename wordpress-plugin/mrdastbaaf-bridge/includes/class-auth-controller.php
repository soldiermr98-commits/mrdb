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

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/auth/me', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_me'),
            'permission_callback' => array($this, 'check_user_logged_in'),
        ));
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

        // ABUSE FIX (BUG-29): سقف ساعتی بر اساس IP — مهاجم با شماره‌های مختلف
        // نمی‌تواند یک IP را برای ارسال انبوه پیامک مصرف کند (۱۰ درخواست در ساعت).
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $ip_key = '';
        $ip_count = 0;
        if ($ip !== '') {
            $ip_key = 'mrdastbaaf_otp_ip_' . md5($ip);
            $ip_count = (int) get_transient($ip_key);
            if ($ip_count >= 10) {
                return new WP_Error('rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', array('status' => 429));
            }
        }

        // ABUSE FIX (BUG-29): شمارنده سراسری ساعتی (Option) — سقف ایمنی کل سیستم
        // پیامک (۲۰۰ درخواست در ساعت) در برابر حملات توزیع‌شده چند-IP.
        $global_count = (int) get_option('mrdastbaaf_otp_hourly_count', 0);
        $global_window_started = (int) get_option('mrdastbaaf_otp_hourly_started', 0);
        if ($global_window_started === 0 || (time() - $global_window_started) >= HOUR_IN_SECONDS) {
            $global_count = 0;
            $global_window_started = time();
        }
        if ($global_count >= 200) {
            return new WP_Error('rate_limited', 'تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', array('status' => 429));
        }

        // تولید کد ۵ رقمی
        $otp_code = wp_rand(10000, 99999);

        // ذخیره هش کد برای ۲ دقیقه
        $storage_key = 'mrdastbaaf_otp_val_' . md5($phone);
        set_transient($storage_key, wp_hash_password((string)$otp_code), 120);
        set_transient($rate_key, true, 120);

        // ABUSE FIX (BUG-29): ثبت مصرف سهمیه IP و سراسری پیش از ارسال پیامک
        if ($ip_key !== '') {
            set_transient($ip_key, $ip_count + 1, HOUR_IN_SECONDS);
        }
        update_option('mrdastbaaf_otp_hourly_count', $global_count + 1, false);
        update_option('mrdastbaaf_otp_hourly_started', $global_window_started, false);

        /**
         * اتصال به افزونه اوتینو یا هوک پیامکی وردپرس
         * TODO: دریافت الگوی پیامک اوتینو (Otino Hook/Pattern Code)
         */
        do_action('mrdastbaaf_send_otp_sms', $phone, $otp_code);

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'کد تایید پیامکی ارسال گردید.',
            'expires_in_seconds' => 120,
        ));
    }

    /**
     * اعتبارسنجی کد تایید و ورود هنرجو
     */
    public function handle_verify_otp(WP_REST_Request $request) {
        $phone = sanitize_text_field($request->get_param('phone'));
        $otp_code = sanitize_text_field($request->get_param('otp_code'));

        $phone = preg_replace('/^(\+98|0098)/', '0', trim($phone));
        $storage_key = 'mrdastbaaf_otp_val_' . md5($phone);

        // SECURITY FIX (BUG-02): brute-force protection.
        // The old endpoint had NO attempt limit: a 5-digit code (90,000 combinations)
        // valid for 120 seconds could be brute-forced by hammering this endpoint.
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
            return new WP_Error('invalid_otp', 'کد تایید وارد شده نامعتبر یا منقضی شده است.' . ($remaining > 0 ? ' تلاش باقی‌مانده: ' . $remaining : ''), array('status' => 401));
        }

        // کد مصرف شد؛ بودجه تلاش‌ها نیز پاک می‌شود
        delete_transient($storage_key);
        delete_transient($attempts_key);
        wp_cache_delete($attempts_key, 'mrdastbaaf_otp'); // BUG-28: پاک‌سازی شمارنده در آبجکت‌کش

        // جستجو یا ساخت کاربر در wp_users
        $user = get_user_by('login', $phone);
        if (!$user) {
            $user_id = wp_create_user($phone, wp_generate_password(24, true), $phone . '@mrdastbaaf.local');
            if (is_wp_error($user_id)) {
                // BUG-31: خطای wp_create_user (معمولاً username/email تکراری) بدون
                // status بود و به 500 نگاشت می‌شد؛ اکنون 409 با پیام فارسی و ثبت
                // کد خطای اصلی در لاگ سرور.
                error_log('MrDastbaaf: wp_create_user failed — code: ' . $user_id->get_error_code() . ' | message: ' . $user_id->get_error_message());
                return new WP_Error('registration_conflict', 'ساخت حساب کاربری با این شماره موبایل ممکن نشد؛ احتمالاً حسابی با این شماره وجود دارد. لطفاً دوباره تلاش کنید.', array('status' => 409));
            }
            $user = get_user_by('ID', $user_id);
            wp_update_user(array(
                'ID' => $user_id,
                'display_name' => 'هنرجو ' . substr($phone, -4),
            ));
            update_user_meta($user_id, 'billing_phone', $phone);
        }

        // تنظیم کوکی ورود رسمی وردپرس
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true, is_ssl());

        return rest_ensure_response(array(
            'success' => true,
            'user' => $this->format_user_profile($user),
        ));
    }

    public function handle_get_me() {
        $user_id = get_current_user_id();
        $user = get_user_by('ID', $user_id);
        // BUG-30: کوکی احراز هویت معتبر اما رکورد کاربر حذف‌شده → قبلاً fatal (500)
        // روی $user->user_login رخ می‌داد؛ اکنون 401 تمیز برگردانده می‌شود.
        if (!$user) {
            return new WP_Error('invalid_user', 'حساب کاربری شما یافت نشد؛ لطفاً دوباره وارد شوید.', array('status' => 401));
        }
        return rest_ensure_response($this->format_user_profile($user));
    }

    public function check_user_logged_in() {
        return is_user_logged_in();
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
