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
        // BUG-36: گارد کاربر نامعتبر — رقابت با لاگ‌اوت نباید به خطای PHP روی $user ختم شود
        if (!$user) {
            return new WP_Error('unauthorized', 'برای شروع پرداخت ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
        }
        $plan_id = sanitize_text_field($request->get_param('plan_id'));

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s AND is_active = 1", $plan_id));

        if (!$plan) {
            return new WP_Error('invalid_plan', 'پلن انتخاب‌شده معتبر نیست.', array('status' => 400));
        }

        // BUG-36: پلن رایگان هرگز نباید به درگاه پرداخت هدایت شود
        if ((int) $plan->price_toman <= 0) {
            return new WP_Error('free_plan_no_gateway', 'پلن رایگان نیازی به پرداخت درگاه ندارد.', array('status' => 400));
        }

        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';

        // BUG-35: حداکثر ۵ سفارش زیبالِ در انتظار برای هر کاربر — جلوگیری از
        // انباشت سفارش‌های رهاشده پیش از رسیدن به سقف کرون انقضا
        $pending_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_orders WHERE user_id = %d AND gateway = 'zibal' AND status = 'pending'",
            $user_id
        ));
        if ($pending_count >= 5) {
            return new WP_Error('too_many_pending_orders', 'تعداد سفارش‌های در انتظار پرداخت شما بیش از حد مجاز است؛ ابتدا سفارش‌های باز خود را تکمیل کنید.', array('status' => 429));
        }

        $order_id = 'ord_' . wp_generate_password(12, false);
        $order_number = $this->generate_unique_order_number(); // BUG-15: یکتا

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

        // به‌روزرسانی trackId در دیتابیس — BUG-36: بررسی نتیجه update و ثبت خطا در لاگ
        $track_updated = $wpdb->update($table_orders, array('zibal_track_id' => $track_id), array('id' => $order_id));
        if (false === $track_updated) {
            error_log('MrDastbaaf: zibal_track_id update failed for order ' . $order_id . ' — ' . $wpdb->last_error);
        }

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

        // BUG-33: هسته مشترک تایید — هم از اندپوینت کال‌بک و هم از کرون ریکنسیلیشن استفاده می‌شود
        $result = $this->verify_zibal_order($order, $success);
        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * BUG-35: فراخوانی HTTP متد verify زیبال — بین اندپوینت کال‌بک و کرون
     * روزانه ریکنسیلیشن مشترک است.
     */
    private function request_zibal_verify($track_id) {
        $merchant = get_option('mrdastbaaf_zibal_merchant', 'zibal');

        return wp_remote_post('https://gateway.zibal.ir/v1/verify', array(
            'headers' => array('Content-Type' => 'application/json'),
            'body' => json_encode(array(
                'merchant' => $merchant,
                'trackId' => $track_id,
            )),
            'timeout' => 15,
        ));
    }

    /**
     * BUG-33/BUG-35: هسته مشترک تایید سفارش زیبال — هم از اندپوینت کال‌بک و هم
     * از کرون روزانه ریکنسیلیشن فراخوانی می‌شود.
     *
     * SECURITY FIX (BUG-33a): ادعای اتمیک سفارش «قبل از» هر اثر جانبی انجام
     * می‌شود (UPDATE مشروط به status='pending'). در رقابت ریدایرکت + کال‌بک
     * سرور-to-سرور فقط یکی از درخواست‌ها برنده می‌شود و grant دوباره‌گانه رخ
     * نمی‌دهد. انتقال‌های نهایی completed/failed فقط از وضعیت verifying انجام
     * می‌شود و نتیجه update نهایی بررسی می‌گردد.
     *
     * @param object $order   رکورد سفارش (از جدول orders)
     * @param string $success وضعیت کال‌بک زیبال ('1' پرداخت‌شده، '2' قبلاً تاییدشده)
     * @return array|WP_Error داده پاسخ در موفقیت / WP_Error در خطا
     */
    public function verify_zibal_order($order, $success) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $track_id = (string) $order->zibal_track_id;

        // BUG-33a: ادعای اتمیک — فقط از وضعیت pending به verifying
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $table_orders SET status = 'verifying' WHERE id = %s AND status = 'pending'",
            $order->id
        ));
        if (!$claimed) {
            $current_status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_orders WHERE id = %s", $order->id));
            if ('completed' === $current_status) {
                return array('success' => true, 'message' => 'این تراکنش قبلاً تایید گردیده است.');
            }
            return new WP_Error('order_processing', 'این سفارش در حال پردازش است.', array('status' => 409));
        }

        // BUG-33b: success=1 (پرداخت‌شده) و success=2 (قبلاً تاییدشده) هر دو پذیرفته می‌شوند
        $success = (string) $success;
        if (!in_array($success, array('1', '2'), true)) {
            if (in_array($success, array('3', '202', '-1', '-2'), true)) {
                // کد قطعی شکست (لغو/ریورس توسط کاربر یا پرداخت‌نشده)
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'failed' WHERE id = %s AND status = 'verifying'",
                    $order->id
                ));
                return new WP_Error('cancelled', 'تراکنش توسط کاربر لغو شد یا ناموفق بود.', array('status' => 400));
            }

            // وضعیت نامشخص — برای ریکنسیلیشن دوره‌ای به pending برمی‌گردد (BUG-33b)
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending' WHERE id = %s AND status = 'verifying'",
                $order->id
            ));
            return new WP_Error('unknown_status', 'وضعیت پرداخت نامشخص است؛ سفارش در انتظار بررسی دوره‌ای باقی ماند.', array('status' => 400));
        }

        // فراخوانی مستقیم متد verify از سرور
        $verify_response = $this->request_zibal_verify($track_id);

        if (is_wp_error($verify_response)) {
            /**
             * SECURITY FIX (BUG-17): خطای شبکه/تایم‌اوت موقت به زیبال به معنای
             * «پرداخت ناموفق» نیست — پول کاربر ممکن است کسر شده باشد. اکنون سفارش
             * به pending برمی‌گردد تا کال‌بک بعدی یا ریکنسیلیشن دوره‌ای دوباره
             * verify کند.
             */
            error_log('MrDastbaaf: zibal verify unreachable for track ' . $track_id . ' — ' . $verify_response->get_error_message());
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending' WHERE id = %s AND status = 'verifying'",
                $order->id
            ));
            return new WP_Error('verify_unreachable', 'ارتباط سرور با زیبال برقرار نشد؛ سفارش در وضعیت انتظار ماند و دوباره بررسی می‌شود.', array('status' => 502));
        }

        $verify_body = json_decode(wp_remote_retrieve_body($verify_response), true);
        $verify_result = isset($verify_body['result']) ? (int) $verify_body['result'] : null;

        // BUG-33b: result=100 (تاییدشده) و result=201 (قبلاً تاییدشده) هر دو موفق‌اند؛
        // فقط کدهای قطعی شکست (202 = پرداخت‌نشده/لغوشده، -1، -2 و ...) failed می‌شوند و
        // بقیه کدهای ناشناخته برای ریکنسیلیشن دوره‌ای pending می‌مانند.
        if (!in_array($verify_result, array(100, 201), true)) {
            if (in_array($verify_result, array(202, -1, -2), true)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'failed' WHERE id = %s AND status = 'verifying'",
                    $order->id
                ));
                return new WP_Error('verify_rejected', 'تاییدیه پرداخت زیبال صادر نگردید.', array('status' => 400));
            }

            // کد ناشناخته — سفارش برای ریکنسیلیشن دوره‌ای در انتظار می‌ماند
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending' WHERE id = %s AND status = 'verifying'",
                $order->id
            ));
            return new WP_Error('verify_unknown', 'وضعیت تایید پرداخت نامشخص است؛ سفارش برای بررسی دوره‌ای در انتظار ماند.', array('status' => 502));
        }

        // SECURITY FIX (BUG-03c): تطبیق مبلغ تاییدشده زیبال با فاکتور.
        // درخواست پرداخت با مبلغ ریالی (تومان × ۱۰) ارسال شده است؛ پس پاسخ verify
        // نیز باید دقیقاً همان مبلغ ریالی باشد. بدون این بررسی، پرداختِ کمتر از
        // مبلغ فاکتور هم اشتراک فعال می‌کرد (partial payment attack).
        $expected_rial = (int) $order->amount_toman * 10;
        if (isset($verify_body['amount']) && (int) $verify_body['amount'] !== $expected_rial) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'failed' WHERE id = %s AND status = 'verifying'",
                $order->id
            ));
            return new WP_Error('amount_mismatch', 'مبلغ پرداخت‌شده با فاکتور سفارش مطابقت ندارد.', array('status' => 400));
        }

        $ref_number = isset($verify_body['refNumber']) ? (string) $verify_body['refNumber'] : '';

        return $this->finalize_verified_order($order, $ref_number);
    }

    /**
     * BUG-33a: تکمیل سفارش تاییدشده — فعال‌سازی اشتراک قبل از completed (الگوی
     * BUG-14) و انتقال نهایی فقط از وضعیت verifying با بررسی نتیجه update.
     */
    private function finalize_verified_order($order, $ref_number) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';

        /**
         * SECURITY FIX (BUG-14 caller): فعال‌سازی اشتراک «قبل از» علامت‌گذاری
         * سفارش به‌عنوان completed انجام می‌شود. اگر grant_or_extend شکست بخورد
         * (پلن حذف‌شده/خطای DB)، سفارش به pending برمی‌گردد تا پشتیبانی دستی
         * بررسی کند و پول کاربر بدون اشتراک گم نشود.
         */
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $order->plan_id));

        $membership_result = MrDastbaaf_Membership_Helper::grant_or_extend($order->user_id, $plan, $order->id);
        if (false === $membership_result) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending' WHERE id = %s AND status = 'verifying'",
                $order->id
            ));
            return new WP_Error('membership_grant_failed', 'پرداخت تایید شد اما فعال‌سازی خودکار اشتراک ممکن نشد؛ سفارش برای بررسی دستی پشتیبانی باقی ماند.', array('status' => 409));
        }

        // ثبت موفقیت فاکتور (بعد از فعال‌سازی موفق اشتراک) — BUG-33a: انتقال نهایی
        // فقط از verifying؛ false = خطای DB → سفارش در verifying می‌ماند (تلاش مجدد)
        $completed_updated = $wpdb->update($table_orders, array(
            'status' => 'completed',
            'zibal_ref_number' => $ref_number,
            'verified_at' => current_time('mysql'),
        ), array('id' => $order->id, 'status' => 'verifying'));

        if (false === $completed_updated) {
            error_log('MrDastbaaf: final completed update failed for order ' . $order->id . ' — ' . $wpdb->last_error);
            return new WP_Error('completion_update_failed', 'ثبت نهایی پرداخت ناموفق بود؛ سفارش در وضعیت پردازش باقی ماند و دوباره بررسی می‌شود.', array('status' => 500));
        }

        /**
         * TODO: ارسال پیامک تایید خرید/تمدید به هنرجو (الگوی اوتینو)
         */
        do_action('mrdastbaaf_membership_granted', $order->user_id, $membership_result);

        return array(
            'success' => true,
            'orderNumber' => $order->order_number,
            'refNumber' => $ref_number,
            'membershipMode' => $membership_result['mode'], // 'created' یا 'extended'
            'membershipExpiresAt' => $membership_result['expires_at'],
            'message' => 'پرداخت با موفقیت در شاپرک تایید شد و اشتراک فعال گردید.',
        );
    }

    /**
     * ثبت فیش کارت به کارت (وضعیت در انتظار بررسی ادمین)
     */
    public function handle_manual_transfer_submit(WP_REST_Request $request) {
        global $wpdb;
        $user_id = get_current_user_id();
        $user = get_user_by('ID', $user_id);
        // BUG-36: گارد کاربر نامعتبر — بدون آن دسترسی به $user->user_login خطای PHP می‌دهد
        if (!$user) {
            return new WP_Error('unauthorized', 'برای ثبت فیش واریزی ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
        }
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
        // BUG-36: پرس‌وجوی پلن هم‌سطح initiate باید is_active = 1 را رعایت کند
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s AND is_active = 1", $plan_id));
        if (!$plan) {
            return new WP_Error('invalid_plan', 'پلن انتخابی معتبر نیست.', array('status' => 400));
        }

        $order_id = 'ord_' . wp_generate_password(12, false);
        $order_number = $this->generate_unique_order_number(); // BUG-15: یکتا
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        // BUG-27: جلوگیری از ادعای تکراری فیش — اگر همین شماره پیگیری با همین
        // ۴ رقم کارت روی سفارشی ثبت شده باشد که «رد نشده»، درخواست با 409 رد
        // می‌شود (یک فیش نمی‌تواند دو بار اشتراک بخرد).
        $duplicate_transfer = $wpdb->get_var($wpdb->prepare(
            "SELECT t.order_id
             FROM $table_transfers t
             INNER JOIN $table_orders o ON o.id = t.order_id
             WHERE t.tracking_code = %s AND t.card_last_four = %s AND o.status != 'rejected'
             LIMIT 1",
            $tracking_code,
            $card_last_four
        ));
        if ($duplicate_transfer) {
            return new WP_Error('duplicate_receipt', 'این شماره پیگیری با این کارت قبلاً ثبت شده است؛ در صورت مشکل با پشتیبانی تماس بگیرید.', array('status' => 409));
        }

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

    /**
     * BUG-35: کرون روزانه — انقضای سفارش‌های زیبالِ معلق قدیمی (بالای ۲۴ ساعت)
     * و ریکنسیلیشن سفارش‌های واقعاً پرداخت‌شده‌ای که کال‌بک‌شان گم شده است.
     * زمان‌بندی و پاک‌سازی این رویداد در فایل اصلی افزونه انجام می‌شود (BUG-25).
     */
    public static function run_daily_reconciliation() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';

        $cutoff = date('Y-m-d H:i:s', current_time('timestamp') - DAY_IN_SECONDS);
        $stale_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_orders
             WHERE gateway = 'zibal' AND status = 'pending' AND created_at < %s
             ORDER BY created_at ASC
             LIMIT 50",
            $cutoff
        ));

        if (empty($stale_orders)) {
            return;
        }

        $controller = new self();

        foreach ($stale_orders as $order) {
            if (!empty($order->zibal_track_id)) {
                // یک بار استعلام واقعی از زیبال — سفارشِ پرداخت‌شده کامل می‌شود
                $result = $controller->verify_zibal_order($order, '1');
                if (is_wp_error($result)) {
                    if ('order_processing' === $result->get_error_code()) {
                        continue; // هم‌اکنون در مسیر کال‌بک پردازش می‌شود — دست نمی‌زنیم
                    }
                    error_log('MrDastbaaf: reconciliation attempt for order ' . $order->id . ' — ' . $result->get_error_message());
                    if ('verify_unreachable' === $result->get_error_code()) {
                        continue; // شبکه موقتاً قطع بوده — دور بعد کرون دوباره تلاش می‌کند
                    }
                }
            }

            // پس از استعلام، سفارشِ همچنان pending منقضا می‌شود (بالای ۲۴ ساعت)
            $current_status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_orders WHERE id = %s", $order->id));
            if ('pending' === $current_status) {
                $expired = $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'cancelled' WHERE id = %s AND status = 'pending'",
                    $order->id
                ));
                if ($expired) {
                    error_log('MrDastbaaf: stale pending zibal order expired to cancelled — order: ' . $order->id);
                }
            }
        }
    }
}
