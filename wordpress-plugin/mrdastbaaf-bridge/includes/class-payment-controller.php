<?php
/**
 * کنترلر پرداخت آنلاین زیبال و فیش‌های کارت به کارت (Payment & Checkout Controller)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Payment_Controller {

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/payment/zibal/initiate', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_zibal_initiate'),
            'permission_callback' => 'is_user_logged_in',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/payment/zibal/verify', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_zibal_verify'),
            'permission_callback' => '__return_true', // استعلام توسط وب‌هوک یا بازگشت پرداخت — با توکن امضاشده محافظت می‌شود
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/payment/manual-transfer/submit', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_manual_transfer_submit'),
            'permission_callback' => 'is_user_logged_in',
        ));
    }

    /**
     * SECURITY FIX (BUG-03): تولید توکن امضاشده برای URL کال‌بک.
     * قبلاً اندپوینت verify کاملاً باز بود: هر کسی با دانستن track_id می‌توانست
     * سفارش‌های در انتظار را «failed» کند یا کال‌بک جعلی بزند. اکنون هر سفارش
     * یک cb_token اختصاصی دارد که فقط در کال‌بک سرور-to-سرور/ریدایرکت زیبال
     * جاسازی می‌شود و با hash_equals اعتبارسنجی می‌گردد.
     */
    private function generate_callback_token($order_id) {
        $secret = wp_salt('auth');
        return hash_hmac('sha256', $order_id, $secret);
    }

    private function validate_callback_token($order_id, $provided_token) {
        if (empty($provided_token) || !is_string($provided_token)) {
            return false;
        }
        return hash_equals($this->generate_callback_token($order_id), $provided_token);
    }

    /**
     * DATA-INTEGRITY FIX (BUG-15): order_number دارای UNIQUE KEY است اما قبلاً با
     * wp_rand(10000, 99999) تولید می‌شد (فضای ۹۰هزار عددی). در برخورد،
     * $wpdb->insert بی‌صدا شکست می‌خورد و کد ادامه می‌یافت: کاربر پیام موفقیت
     * می‌گرفت در حالی که سفارشی در دیتابیس ثبت نشده بود (پرداخت بدون فاکتور).
     * این هلپر شماره یکتا تولید می‌کند و برخورد را با تلاش مجدد حل می‌کند.
     */
    private function generate_unique_order_number() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';

        for ($i = 0; $i < 5; $i++) {
            $candidate = 'MD-' . wp_rand(10000, 99999);
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM $table_orders WHERE order_number = %s LIMIT 1", $candidate)
            );
            if (null === $exists) {
                return $candidate;
            }
        }

        // fallback تقریباً غیرقابل‌تصور: پسوند زمانی (در محدوده varchar(30))
        return 'MD-' . wp_rand(10000, 99999) . '-' . base_convert((string) time(), 10, 36);
    }

    /**
     * DATA-INTEGRITY FIX (BUG-15): ثبت سفارش با بررسی نتیجه insert.
     * اگر ثبت به هر دلیل شکست بخورد، به هیچ عنوان پاسخ موفقیت برگردانده نمی‌شود.
     */
    private function insert_order_or_fail(array $order_data) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $inserted = $wpdb->insert($table_orders, $order_data);
        if (false === $inserted) {
            error_log('MrDastbaaf: order insert failed — ' . $wpdb->last_error);
            return new WP_Error('db_error', 'ثبت سفارش در پایگاه داده ناموفق بود. لطفاً دوباره تلاش کنید.', array('status' => 500));
        }
        return true;
    }

    /**
     * ایجاد فاکتور و درخواست شناسه پرداخت از زیبال (Server-to-Server Request)
     */
    public function handle_zibal_initiate(WP_REST_Request $request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $user = get_user_by('ID', $user_id);
        $plan_id = sanitize_text_field($request->get_param('plan_id'));

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s AND is_active = 1", $plan_id));

        if (!$plan) {
            return new WP_Error('invalid_plan', 'پلن انتخاب‌شده معتبر نیست.', array('status' => 400));
        }

        $order_id = 'ord_' . wp_generate_password(12, false);
        $order_number = $this->generate_unique_order_number(); // BUG-15: یکتا
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';

        // ثبت سفارش اولیه با وضعیت pending — DATA-INTEGRITY FIX (BUG-15): بررسی نتیجه
        $insert_check = $this->insert_order_or_fail(array(
            'id' => $order_id,
            'order_number' => $order_number,
            'user_id' => $user_id,
            'user_phone' => $user->user_login,
            'user_fullname' => $user->display_name,
            'plan_id' => $plan->id,
            'item_title' => $plan->title,
            'amount_toman' => $plan->price_toman,
            'gateway' => 'zibal',
            'status' => 'pending',
        ));
        if (is_wp_error($insert_check)) {
            return $insert_check;
        }

        /**
         * درخواست اتصال مستقیم از سرور به درگاه زیبال
         * TODO: دریافت کلید رسمی مرچنت زیبال از تنظیمات وردپرس (get_option('mrdastbaaf_zibal_merchant'))
         */
        $merchant = get_option('mrdastbaaf_zibal_merchant', 'zibal'); // 'zibal' = sandbox
        $amount_rial = $plan->price_toman * 10;
        $cb_token = $this->generate_callback_token($order_id);
        $callback_url = add_query_arg('cb_token', $cb_token, get_option('mrdastbaaf_frontend_url', home_url()) . '/payment/callback?orderId=' . $order_id);

        $response = wp_remote_post('https://gateway.zibal.ir/v1/request', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'merchant' => $merchant,
                'amount' => $amount_rial,
                'callbackUrl' => $callback_url,
                'description' => 'خرید اشتراک ' . $plan->title . ' در مستر دستباف',
                'orderId' => $order_number,
                'mobile' => $user->user_login,
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('gateway_error', 'خطا در برقراری ارتباط با درگاه پرداخت زیبال.', array('status' => 500));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['result']) || $body['result'] != 100) {
            return new WP_Error('gateway_reject', 'درگاه زیبال درخواست پرداخت را نپذیرفت (کد خطا: ' . ($body['result'] ?? '') . ').', array('status' => 502));
        }

        $track_id = (string) $body['trackId'];

        // به‌روزرسانی trackId در دیتابیس
        $wpdb->update($table_orders, array('zibal_track_id' => $track_id), array('id' => $order_id));

        return rest_ensure_response(array(
            'orderId' => $order_id,
            'orderNumber' => $order_number,
            'trackId' => $track_id,
            'paymentUrl' => 'https://gateway.zibal.ir/start/' . $track_id,
        ));
    }

    /**
     * تایید مستقیم سرور به سرور تراکنش زیبال (Server-Side Verification)
     */
    public function handle_zibal_verify(WP_REST_Request $request) {
        global $wpdb;
        $track_id = sanitize_text_field($request->get_param('track_id'));
        $success = sanitize_text_field($request->get_param('success'));
        $cb_token = $request->get_param('cb_token');

        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_orders WHERE zibal_track_id = %s", $track_id));

        if (!$order) {
            return new WP_Error('not_found', 'سفارش متناظر با این شناسه یافت نشد.', array('status' => 404));
        }

        // SECURITY FIX (BUG-03a): این اندپوینت عمومی است؛ بدون توکن معتبر کال‌بک،
        // هیچ تغییری در وضعیت سفارش مجاز نیست (نه failed و نه completed).
        if (!$this->validate_callback_token($order->id, $cb_token)) {
            return new WP_Error('forbidden', 'توکن کال‌بک نامعتبر است.', array('status' => 403));
        }

        // IDEMPOTENCY (BUG-03d): اگر سفارش قبلاً تایید شده، دوباره activate/verify نکن.
        if ($order->status === 'completed') {
            return rest_ensure_response(array('success' => true, 'message' => 'این تراکنش قبلاً تایید گردیده است.'));
        }

        if ($success !== '1') {
            $wpdb->update($table_orders, array('status' => 'failed'), array('id' => $order->id));
            return new WP_Error('cancelled', 'تراکنش توسط کاربر لغو شد یا ناموفق بود.', array('status' => 400));
        }

        $merchant = get_option('mrdastbaaf_zibal_merchant', 'zibal');

        // فراخوانی مستقیم متد verify از سرور
        $verify_response = wp_remote_post('https://gateway.zibal.ir/v1/verify', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'merchant' => $merchant,
                'trackId' => $track_id,
            )),
            'timeout' => 15,
        ));

        if (is_wp_error($verify_response)) {
            /**
             * SECURITY FIX (BUG-17): خطای شبکه/تایم‌اوت موقت به زیبال به معنای
             * «پرداخت ناموفق» نیست — پول کاربر ممکن است کسر شده باشد. قبلاً سفارش
             * بلافاصله failed می‌شد و مسیر بازیابی نداشت. اکنون سفارش pending می‌ماند
             * تا کال‌بک بعدی یا ریکنسیلیشن دوره‌ای دوباره verify کند.
             */
            error_log('MrDastbaaf: zibal verify unreachable for track ' . $track_id . ' — ' . $verify_response->get_error_message());
            return new WP_Error('verify_unreachable', 'ارتباط سرور با زیبال برقرار نشد؛ سفارش در وضعیت انتظار ماند و دوباره بررسی می‌شود.', array('status' => 502));
        }

        $verify_body = json_decode(wp_remote_retrieve_body($verify_response), true);
        if (!isset($verify_body['result']) || $verify_body['result'] != 100) {
            $wpdb->update($table_orders, array('status' => 'failed'), array('id' => $order->id));
            return new WP_Error('verify_rejected', 'تاییدیه پرداخت زیبال صادر نگردید.', array('status' => 400));
        }

        // SECURITY FIX (BUG-03c): تطبیق مبلغ تاییدشده زیبال با فاکتور.
        // درخواست پرداخت با مبلغ ریالی (تومان × ۱۰) ارسال شده است؛ پس پاسخ verify
        // نیز باید دقیقاً همان مبلغ ریالی باشد. بدون این بررسی، پرداختِ کمتر از
        // مبلغ فاکتور هم اشتراک فعال می‌کرد (partial payment attack).
        $expected_rial = (int) $order->amount_toman * 10;
        if (isset($verify_body['amount']) && (int) $verify_body['amount'] !== $expected_rial) {
            $wpdb->update($table_orders, array('status' => 'failed'), array('id' => $order->id));
            return new WP_Error('amount_mismatch', 'مبلغ پرداخت‌شده با فاکتور سفارش مطابقت ندارد.', array('status' => 400));
        }

        $ref_number = isset($verify_body['refNumber']) ? (string) $verify_body['refNumber'] : '';
        $now = current_time('mysql');

        /**
         * SECURITY FIX (BUG-14 caller): فعال‌سازی اشتراک «قبل از» علامت‌گذاری
         * سفارش به‌عنوان completed انجام می‌شود. اگر grant_or_extend شکست بخورد
         * (پلن حذف‌شده/خطای DB)، سفارش pending می‌ماند تا پشتیبانی دستی بررسی کند
         * و پول کاربر بدون اشتراک گم نشود.
         */
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $order->plan_id));

        $membership_result = MrDastbaaf_Membership_Helper::grant_or_extend($order->user_id, $plan, $order->id);
        if (false === $membership_result) {
            return new WP_Error('membership_grant_failed', 'پرداخت تایید شد اما فعال‌سازی خودکار اشتراک ممکن نشد؛ سفارش برای بررسی دستی پشتیبانی باقی ماند.', array('status' => 409));
        }

        // ثبت موفقیت فاکتور (بعد از فعال‌سازی موفق اشتراک)
        $wpdb->update($table_orders, array(
            'status' => 'completed',
            'zibal_ref_number' => $ref_number,
            'verified_at' => $now,
        ), array('id' => $order->id));

        /**
         * TODO: ارسال پیامک تایید خرید/تمدید به هنرجو (الگوی اوتینو)
         */
        do_action('mrdastbaaf_membership_granted', $order->user_id, $membership_result);

        return rest_ensure_response(array(
            'success' => true,
            'orderNumber' => $order->order_number,
            'refNumber' => $ref_number,
            'membershipMode' => $membership_result['mode'], // 'created' یا 'extended'
            'membershipExpiresAt' => $membership_result['expires_at'],
            'message' => 'پرداخت با موفقیت در شاپرک تایید شد و اشتراک فعال گردید.',
        ));
    }

    /**
     * ثبت فیش کارت به کارت (وضعیت در انتظار بررسی ادمین)
     */
    public function handle_manual_transfer_submit(WP_REST_Request $request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $user = get_user_by('ID', $user_id);
        $plan_id = sanitize_text_field($request->get_param('plan_id'));
        $tracking_code = sanitize_text_field($request->get_param('tracking_code'));
        $card_last_four = sanitize_text_field($request->get_param('card_last_four'));
        $deposit_date = sanitize_text_field($request->get_param('deposit_date'));
        $notes = sanitize_textarea_field($request->get_param('notes'));

        /**
         * INPUT VALIDATION FIX (BUG-16): قبلاً هر رشته‌ای بدون اعتبارسنجی ثبت می‌شد
         * و رکوردهای مالی نامعتبر در صف بررسی ادمین جمع می‌شد. الگوها اکنون با
         * نسخه کلاینت (فاز ۲) هم‌خوان است.
         */
        if (!preg_match('/^[0-9A-Za-z\\-]{4,30}$/', $tracking_code)) {
            return new WP_Error('invalid_tracking_code', 'شماره پیگیری باید ۴ تا ۳۰ کاراکتر و فقط شامل عدد یا حرف انگلیسی باشد.', array('status' => 400));
        }
        if (!preg_match('/^[0-9]{4}$/', $card_last_four)) {
            return new WP_Error('invalid_card_last_four', 'چهار رقم آخر کارت باید دقیقاً ۴ رقم عددی باشد.', array('status' => 400));
        }
        if ($deposit_date !== '' && !preg_match('/^[0-9\\x{0600}-\\x{06FF}\\/\\-\\. ]{4,20}$/u', $deposit_date)) {
            return new WP_Error('invalid_deposit_date', 'تاریخ واریز وارد شده معتبر نیست.', array('status' => 400));
        }
        if (mb_strlen($notes) > 500) {
            return new WP_Error('notes_too_long', 'توضیحات حداکثر ۵۰۰ کاراکتر است.', array('status' => 400));
        }

        /**
         * RATE LIMIT FIX (BUG-16): حداکثر ۵ فیش در ساعت برای هر کاربر — جلوگیری
         * از اسپم صف بررسی مالی توسط حساب‌های آلوده یا اسکریپت‌ها.
         */
        $rl_key = 'mrdastbaaf_transfer_rl_' . (int) $user_id;
        $submitted_count = (int) get_transient($rl_key);
        if ($submitted_count >= 5) {
            return new WP_Error('rate_limited', 'تعداد فیش‌های ثبت‌شده در یک ساعت بیش از حد مجاز است. لطفاً کمی بعد دوباره تلاش کنید.', array('status' => 429));
        }

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $plan_id));
        if (!$plan) {
            return new WP_Error('invalid_plan', 'پلن انتخابی معتبر نیست.', array('status' => 400));
        }

        $order_id = 'ord_' . wp_generate_password(12, false);
        $order_number = $this->generate_unique_order_number(); // BUG-15: یکتا
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        // ثبت سفارش در وضعیت معلق — DATA-INTEGRITY FIX (BUG-15): بررسی نتیجه
        $insert_check = $this->insert_order_or_fail(array(
            'id' => $order_id,
            'order_number' => $order_number,
            'user_id' => $user_id,
            'user_phone' => $user->user_login,
            'user_fullname' => $user->display_name,
            'plan_id' => $plan->id,
            'item_title' => $plan->title,
            'amount_toman' => $plan->price_toman,
            'gateway' => 'card_to_card',
            'status' => 'pending', // هیچ‌گاه مستقیماً فعال نمی‌شود!
        ));
        if (is_wp_error($insert_check)) {
            return $insert_check;
        }

        $transfer_inserted = $wpdb->insert($table_transfers, array(
            'order_id' => $order_id,
            'tracking_code' => $tracking_code,
            'card_last_four' => $card_last_four,
            'deposit_date_jalali' => $deposit_date,
            'sender_notes' => $notes,
        ));
        if (false === $transfer_inserted) {
            // سفارش یتیم نمی‌ماند — رکورد سفارش تازه‌ثبت‌شده حذف می‌شود
            $wpdb->delete($table_orders, array('id' => $order_id));
            error_log('MrDastbaaf: manual transfer insert failed — ' . $wpdb->last_error);
            return new WP_Error('db_error', 'ثبت فیش واریزی ناموفق بود. لطفاً دوباره تلاش کنید.', array('status' => 500));
        }

        // شمارنده rate-limit فقط بعد از ثبت موفق افزایش می‌یابد
        set_transient($rl_key, $submitted_count + 1, HOUR_IN_SECONDS);

        return rest_ensure_response(array(
            'success' => true,
            'orderId' => $order_id,
            'orderNumber' => $order_number,
            'status' => 'pending',
            'message' => 'فیش واریزی شما ثبت شد و در صف بررسی کارشناسان مالی قرار گرفت.',
        ));
    }
}
