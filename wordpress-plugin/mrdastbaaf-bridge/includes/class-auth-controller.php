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

        // تولید کد ۵ رقمی
        $otp_code = wp_rand(10000, 99999);

        // ذخیره هش کد برای ۲ دقیقه
        $storage_key = 'mrdastbaaf_otp_val_' . md5($phone);
        set_transient($storage_key, wp_hash_password((string)$otp_code), 120);
        set_transient($rate_key, true, 120);

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
        $hashed_code = get_transient($storage_key);

        if (!$hashed_code || !wp_check_password($otp_code, $hashed_code)) {
            return new WP_Error('invalid_otp', 'کد تایید وارد شده نامعتبر یا منقضی شده است.', array('status' => 401));
        }

        // حذف کد یک‌بارمصرف پس از استفاده موفق
        delete_transient($storage_key);

        // جستجو یا ساخت کاربر در wp_users
        $user = get_user_by('login', $phone);
        if (!$user) {
            $user_id = wp_create_user($phone, wp_generate_password(24, true), $phone . '@mrdastbaaf.local');
            if (is_wp_error($user_id)) {
                return $user_id;
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
        return rest_ensure_response($this->format_user_profile($user));
    }

    public function check_user_logged_in() {
        return is_user_logged_in();
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
            $diff_seconds = strtotime($membership->expires_at) - time();
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
