<?php
/**
 * کنترلر پرداخت آنلاین زیبال و فیش‌های کارت به کارت (Payment & Checkout Controller)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Payment_Controller {

    public function register_routes() {
        // v1.2.0: permission مشترک نیازمند ورود (احراز صریح Bearer + is_user_logged_in)
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/payment/zibal/initiate', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_zibal_initiate'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/payment/zibal/verify', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_zibal_verify'),
            'permission_callback' => '__return_true', // استعلام توسط وب‌هوک یا بازگشت پرداخت — با توکن امضاشده محافظت می‌شود
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/payment/manual-transfer/submit', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_manual_transfer_submit'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));

        /**
         * v1.2.0: محرک دستی/سرور ریکنسیلیشن — برای هاست‌هایی که WP-Cron تزویرشان
         * غیرقابل‌اتکاست (مثلاً crontab سیستم با curl). توکن از آپشن
         * mrdastbaaf_cron_token با hash_equals مقایسه می‌شود (403 خودکار در شکست).
         * مستندات: README افزونه، بخش «کرون ریکنسیلیشن».
         */
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/cron/reconcile', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_cron_reconcile'),
            'permission_callback' => array($this, 'check_cron_token'),
        ));
    }

    /**
     * v1.2.0: اعتبارسنجی توکن اندپوینت کرون — مقایسهٔ زمان-ثابت با آپشن
     * mrdastbaaf_cron_token. توکن در نخستین ارتقا/فعال‌سازی تولید می‌شود (فایل اصلی)؛
     * هرگز در پاسخ‌ها بازتاب داده نمی‌شود.
     *
     * @return bool
     */
    public function check_cron_token() {
        $provided = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';
        $stored = (string) get_option('mrdastbaaf_cron_token', '');
        if ('' === $stored || '' === $provided) {
            return false;
        }
        return hash_equals($stored, $provided);
    }

    /**
     * v1.2.0: هندلر GET /cron/reconcile — اجرای ریکنسیلیشن و برگرداندن آخرین خلاصه
     */
    public function handle_cron_reconcile() {
        self::run_reconciliation();

        return rest_ensure_response(array(
            'success' => true,
            'log' => (string) get_option('mrdastbaaf_last_reconcile_log', ''),
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
     * v1.2.0 (یافتهٔ ۱-۱): گارد پیکربندی درگاه — بدون مرچنت واقعی، زیبال در حالت
     * سندباکس پیش‌فرض ('zibal') قرار می‌گیرد که در پروداکشن پول واقعی جابه‌جا نمی‌کند
     * و سفارش‌های جعلی می‌سازد. سندباکس فقط با ثابت صریح MRDASTBAAF_ALLOW_SANDBOX
     * (در wp-config برای محیط dev) باز می‌شود.
     *
     * @return bool true یعنی درگاه پیکربندی نشده (حالت سندباکس)
     */
    private function is_gateway_unconfigured() {
        $merchant = trim((string) get_option('mrdastbaaf_zibal_merchant', ''));
        return ('' === $merchant || 'zibal' === $merchant);
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
        // v1.1.0: پشتیبانی احراز هویت Bearer در کنار کوکی
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();

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

        // SECURITY FIX (v1.2.0 — یافتهٔ ۱-۱): گارد پیکربندی درگاه — قبل از ثبت هر سفارشی؛
        // بدون مرچنت واقعی دیگر سفارش سندباکسی ساخته نمی‌شود (پیش‌فرض قدیمی 'zibal').
        // سندباکس فقط با ثابت صریح MRDASTBAAF_ALLOW_SANDBOX=true در wp-config (محیط dev).
        if ($this->is_gateway_unconfigured() && (!defined('MRDASTBAAF_ALLOW_SANDBOX') || !MRDASTBAAF_ALLOW_SANDBOX)) {
            return new WP_Error('gateway_not_configured', 'درگاه پرداخت هنوز پیکربندی نشده است؛ لطفاً بعداً تلاش کنید.', array('status' => 503));
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
         * v1.2.0: مرچنت فقط از آپشن تنظیمات؛ بدون پیش‌فرض سندباکس (گارد بالا).
         */
        $merchant = trim((string) get_option('mrdastbaaf_zibal_merchant', ''));
        $amount_rial = $plan->price_toman * 10;
        $cb_token = $this->generate_callback_token($order_id);

        /**
         * v1.1.0: کال‌بک هش‌محور برای هاست استاتیک PWA — فرانت hash router دارد و
         * پارامترها (orderId و cb_token) داخل fragment خوانده می‌شوند؛ زیبال هم
         * trackId/success را به انتهای همین URL اضافه می‌کند. مسیر قبلی
         * «/payment/callback?orderId=…» (query-محور) جایگزین شده است.
         * پارامترهای initiate/verify بدون تغییر مانده‌اند.
         */
        $callback_url = trailingslashit(get_option('mrdastbaaf_frontend_url', home_url())) . '#/payment/callback?orderId=' . $order_id . '&cb_token=' . $cb_token;

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

        // SECURITY FIX (v1.2.0 — یافتهٔ ۱-۲): در شکست اتصال/پذیرش درگاه، سفارش pending
        // تازه‌ثبت‌شده لغو می‌شود — قبلاً رها می‌شد و سهمیهٔ ۵ سفارش pending کاربر را
        // مسدود می‌کرد. سپس WP_Error اصلی برگردانده می‌شود.
        if (is_wp_error($response)) {
            $this->cancel_pending_order($order_id, 'gateway_unreachable');
            return new WP_Error('gateway_error', 'خطا در برقراری ارتباط با درگاه پرداخت زیبال.', array('status' => 500));
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['result']) || $body['result'] != 100) {
            $this->cancel_pending_order($order_id, 'gateway_reject_result_' . (isset($body['result']) ? (string) $body['result'] : 'unknown'));
            return new WP_Error('gateway_reject', 'درگاه زیبال درخواست پرداخت را نپذیرفت (کد خطا: ' . (isset($body['result']) ? $body['result'] : '') . ').', array('status' => 502));
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
     * v1.2.0: لغو سفارش pending پس از شکست initiate — سفارش‌های بی‌درگاه نباید
     * در صف انتظار بمانند (سهمیهٔ ۵ سفارشِ BUG-35 مسدود نمی‌شود).
     *
     * @param string $order_id شناسهٔ سفارش
     * @param string $reason برچسب دلیل برای لاگ سرور
     * @return bool آیا لغو انجام شد
     */
    private function cancel_pending_order($order_id, $reason) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $cancelled = $wpdb->query($wpdb->prepare(
            "UPDATE $table_orders SET status = 'cancelled', updated_at = %s WHERE id = %s AND status = 'pending'",
            current_time('mysql'), $order_id
        ));
        if ($cancelled) {
            error_log('MrDastbaaf: pending order cancelled after initiate failure (' . $reason . ') — order: ' . $order_id);
        }
        return (bool) $cancelled;
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

        // IDEMPOTENCY (BUG-03d): اگر سفارش قبلاً تایید شده، دوباره activate/verify نکن
        // (این چک پیش از گارد ۴۸ ساعته است تا کال‌بکِ دیر یک سفارشِ تکمیل‌شده خطا نسازد).
        if ($order->status === 'completed') {
            return rest_ensure_response(array('success' => true, 'message' => 'این تراکنش قبلاً تایید گردیده است.'));
        }

        // SECURITY FIX (v1.2.0 — یافتهٔ ۲-۱۶): TTL توکن کال‌بک = ۴۸ ساعت؛ cb_token دائمی
        // بود و پیوند پرداختِ خیلی قدیم هم قابل استعلام می‌ماند. ریکنسیلیشن سرور-به-سرور
        // مستقیم verify_zibal_order را صدا می‌زند و از این گارد مستثنی است.
        $created_ts = strtotime((string) $order->created_at);
        if (!$created_ts || $created_ts < current_time('timestamp') - 2 * DAY_IN_SECONDS) {
            return new WP_Error('callback_expired', 'پیوند پرداخت منقضی شده است؛ در صورت پرداخت موفق، حداکثر تا ۴۸ ساعت به‌صورت خودکار فعال می‌شود.', array('status' => 403));
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
     * ریکنسیلیشن مشترک است.
     *
     * v1.2.0: گارد پیکربندی درگاه در ابتدای متد — بدون مرچنت واقعی، استعلام verify
     * بی‌معناست؛ null برگردانده می‌شود و فراخواننده‌ها آن را «غیرقابل‌دسترس/رَدشده»
     * فرض می‌کنند (فقط لاگ، بدون تغییر وضعیت به failed).
     *
     * @param string $track_id شناسه تراکنش زیبال
     * @return array|WP_Error|null پاسخ HTTP یا خطا؛ null = درگاه پیکربندی نشده
     */
    private function request_zibal_verify($track_id) {
        if ($this->is_gateway_unconfigured() && (!defined('MRDASTBAAF_ALLOW_SANDBOX') || !MRDASTBAAF_ALLOW_SANDBOX)) {
            return null;
        }

        $merchant = trim((string) get_option('mrdastbaaf_zibal_merchant', ''));

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
     * از کرون ریکنسیلیشن فراخوانی می‌شود.
     *
     * SECURITY FIX (BUG-33a): ادعای اتمیک سفارش «قبل از» هر اثر جانبی انجام
     * می‌شود (UPDATE مشروط به status='pending'). در رقابت ریدایرکت + کال‌بک
     * سرور-to-سرور فقط یکی از درخواست‌ها برنده می‌شود و grant دوباره‌گانه رخ
     * نمی‌دهد. انتقال‌های نهایی completed/failed فقط از وضعیت verifying انجام
     * می‌شود و نتیجه update نهایی بررسی می‌گردد. همهٔ انتقال‌های وضعیت
     * updated_at را مهر می‌زنند (ستون NEW اسکیما ۱.۳.۰) تا کرون بتواند
     * سفارش‌های verifying گیرکرده را بازیابی و شکست‌ها را بازبررسی کند.
     *
     * v1.2.0 (یافتهٔ ۱-۲): پارامتر $success کلاینت هرگز در تصمیم‌گیری استفاده
     * نمی‌شود — ریدایرکت کاربر به کال‌بک قابل جعل/ناقص است؛ استعلام سرور-به-سرور
     * زیبال (request_zibal_verify) تنها مرجع یکتای حقیقت است. امضا برای سازگاری
     * فراخوانی‌های موجود حفظ شده است.
     *
     * @param object $order   رکورد سفارش (از جدول orders)
     * @param string $success وضعیت کال‌بک زیبال — فقط برای سازگاری امضا؛ نادیده گرفته می‌شود
     * @return array|WP_Error داده پاسخ در موفقیت / WP_Error در خطا
     */
    public function verify_zibal_order($order, $success) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $track_id = (string) $order->zibal_track_id;
        $now = current_time('mysql');

        // BUG-33a: ادعای اتمیک — فقط از وضعیت pending به verifying (+ مهر updated_at)
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE $table_orders SET status = 'verifying', updated_at = %s WHERE id = %s AND status = 'pending'",
            $now, $order->id
        ));
        if (!$claimed) {
            $current_status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM $table_orders WHERE id = %s", $order->id));
            if ('completed' === $current_status) {
                return array('success' => true, 'message' => 'این تراکنش قبلاً تایید گردیده است.');
            }
            return new WP_Error('order_processing', 'این سفارش در حال پردازش است.', array('status' => 409));
        }

        // v1.2.0: استعلام واقعی از زیبال — نتیجهٔ آن تنها مرجع تصمیم است
        $verify_response = $this->request_zibal_verify($track_id);

        if (null === $verify_response) {
            // درگاه پیکربندی نشده — سفارش به pending برمی‌گردد؛ لاگ می‌شود ولی
            // وضعیت failed نمی‌گیرد (پول کاربر ممکن است واقعاً در راه باشد).
            error_log('MrDastbaaf: zibal verify skipped for track ' . $track_id . ' — gateway not configured (sandbox guard)');
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('verify_unreachable', 'ارتباط سرور با زیبال برقرار نشد؛ سفارش در وضعیت انتظار ماند و دوباره بررسی می‌شود.', array('status' => 502));
        }

        if (is_wp_error($verify_response)) {
            /**
             * SECURITY FIX (BUG-17): خطای شبکه/تایم‌اوت موقت به زیبال به معنای
             * «پرداخت ناموفق» نیست — پول کاربر ممکن است کسر شده باشد. اکنون سفارش
             * به pending برمی‌گردد تا کال‌بک بعدی یا ریکنسیلیشن دوره‌ای دوباره
             * verify کند.
             */
            error_log('MrDastbaaf: zibal verify unreachable for track ' . $track_id . ' — ' . $verify_response->get_error_message());
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('verify_unreachable', 'ارتباط سرور با زیبال برقرار نشد؛ سفارش در وضعیت انتظار ماند و دوباره بررسی می‌شود.', array('status' => 502));
        }

        $verify_body = json_decode(wp_remote_retrieve_body($verify_response), true);
        $verify_result = isset($verify_body['result']) ? (int) $verify_body['result'] : null;

        // BUG-33b (به‌روزشده v1.2.0): result=100 (تاییدشده) و result=201 (قبلاً تاییدشده)
        // هر دو موفق‌اند؛ فقط کدهای قطعی شکست (202 = پرداخت‌نشده/لغوشده، -1، -2)
        // failed می‌شوند و بقیه کدهای ناشناخته برای ریکنسیلیشن دوره‌ای pending می‌مانند.
        if (!in_array($verify_result, array(100, 201), true)) {
            if (in_array($verify_result, array(202, -1, -2), true)) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'failed', updated_at = %s WHERE id = %s AND status = 'verifying'",
                    $now, $order->id
                ));
                return new WP_Error('verify_rejected', 'تاییدیه پرداخت زیبال صادر نگردید.', array('status' => 400));
            }

            // کد ناشناخته — سفارش برای ریکنسیلیشن دوره‌ای در انتظار می‌ماند
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('verify_unknown', 'وضعیت تایید پرداخت نامشخص است؛ سفارش برای بررسی دوره‌ای در انتظار ماند.', array('status' => 502));
        }

        // SECURITY FIX (v1.2.0 — یافتهٔ ۱-۲): مبلغ پاسخ verify «الزامی» است —
        // پاسخ بدون amount (پاسخ غیرمنتظره/سندباکس جعلی) قابل اعتبارسنجی نیست.
        if (!isset($verify_body['amount'])) {
            error_log('MrDastbaaf: zibal verify response missing amount for order ' . $order->id . ' (track ' . $track_id . ')');
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('verify_no_amount', 'پاسخ تایید درگاه فاقد مبلغ است؛ سفارش برای بررسی دوره‌ای در انتظار ماند.', array('status' => 502));
        }

        // SECURITY FIX (v1.2.0 — یافتهٔ ۱-۲): قید orderId — پاسخ verify باید متعلق به
        // همین شماره سفارش باشد (زیبال orderId ارسالی در initiate را بازمی‌گرداند)؛
        // ناهمخوانی یعنی پاسخ درگاه به سفارش دیگری تعلق دارد → failed.
        if (isset($verify_body['orderId']) && (string) $verify_body['orderId'] !== (string) $order->order_number) {
            error_log('MrDastbaaf: zibal verify orderId mismatch for order ' . $order->id . ' — expected: ' . $order->order_number . ' | got: ' . (string) $verify_body['orderId']);
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'failed', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('order_mismatch', 'سفارش تاییدشده با فاکتور درخواستی مطابقت ندارد.', array('status' => 400));
        }

        // SECURITY FIX (BUG-03c): تطبیق مبلغ تاییدشده زیبال با فاکتور.
        // درخواست پرداخت با مبلغ ریالی (تومان × ۱۰) ارسال شده است؛ پس پاسخ verify
        // نیز باید دقیقاً همان مبلغ ریالی باشد. بدون این بررسی، پرداختِ کمتر از
        // مبلغ فاکتور هم اشتراک فعال می‌کرد (partial payment attack).
        $expected_rial = (int) $order->amount_toman * 10;
        if ((int) $verify_body['amount'] !== $expected_rial) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'failed', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
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
        $now = current_time('mysql');

        /**
         * SECURITY FIX (BUG-14 caller): فعال‌سازی اشتراک «قبل از» علامت‌گذاری
         * سفارش به‌عنوان completed انجام می‌شود. اگر grant_or_extend شکست بخورد
         * (پلن حذف‌شده/خطای DB)، سفارش به pending برمی‌گردد تا پشتیبانی دستی
         * بررسی کند و پول کاربر بدون اشتراک گم نشود.
         */
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $order->plan_id));

        $membership_result = MrDastbaaf_Membership_Helper::grant_or_extend($order->user_id, $plan, $order->id);
        if (false === $membership_result || !is_array($membership_result)) {
            if (is_array($membership_result) && isset($membership_result['mode']) && 'blocked_lower_tier' === $membership_result['mode']) {
                // v1.2.0: خرید سطح پایین‌تر روی اشتراک فعال سطح بالاتر رد می‌شود؛
                // سفارش به pending برمی‌گردد (خطای کد در فراخوانی‌های این کلاس map می‌شود).
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                    $now, $order->id
                ));
                return new WP_Error('lower_tier_blocked', $membership_result['message'], array('status' => 409));
            }
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('membership_grant_failed', 'پرداخت تایید شد اما فعال‌سازی خودکار اشتراک ممکن نشد؛ سفارش برای بررسی دستی پشتیبانی باقی ماند.', array('status' => 409));
        }

        // ثبت موفقیت فاکتور (بعد از فعال‌سازی موفق اشتراک) — BUG-33a: انتقال نهایی
        // فقط از verifying؛ false = خطای DB → سفارش در verifying می‌ماند (تلاش مجدد)
        $completed_updated = $wpdb->update($table_orders, array(
            'status' => 'completed',
            'zibal_ref_number' => $ref_number,
            'verified_at' => $now,
            'updated_at' => $now,
        ), array('id' => $order->id, 'status' => 'verifying'));

        if (false === $completed_updated) {
            error_log('MrDastbaaf: final completed update failed for order ' . $order->id . ' — ' . $wpdb->last_error);
            return new WP_Error('completion_update_failed', 'ثبت نهایی پرداخت ناموفق بود؛ سفارش در وضعیت پردازش باقی ماند و دوباره بررسی می‌شود.', array('status' => 500));
        }

        /**
         * v1.2.0: پیامک تایید خرید/تمدید به هنرجو — از طریق هوک، پیاده‌سازی
         * اعلان خرید در کار آینده.
         */
        do_action('mrdastbaaf_membership_granted', $order->user_id, $membership_result);

        return array(
            'success' => true,
            'orderNumber' => $order->order_number,
            'refNumber' => $ref_number,
            'membershipMode' => $membership_result['mode'], // 'created' یا 'extended' یا 'upgraded'
            'membershipExpiresAt' => $membership_result['expires_at'],
            'message' => 'پرداخت با موفقیت در شاپرک تایید شد و اشتراک فعال گردید.',
        );
    }

    /**
     * ثبت فیش کارت به کارت (وضعیت در انتظار بررسی ادمین)
     */
    public function handle_manual_transfer_submit(WP_REST_Request $request) {
        global $wpdb;
        // v1.1.0: پشتیبانی احراز هویت Bearer در کنار کوکی
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();

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
     * BUG-35 (منسوخ در v1.2.0): نام مستعار سازگاری — کرون روزانه قدیمی به رویداد
     * ۱۰دقیقه‌ای mrdastbaaf_reconciliation_tick مهاجرت کرده است. این متد برای
     * هوک‌های ثالث/فراخوانی‌های دستی موجود حفظ شده و مستقیماً run_reconciliation
     * را اجرا می‌کند.
     */
    public static function run_daily_reconciliation() {
        self::run_reconciliation();
    }

    /**
     * BUG-35 / v1.2.0: کرون ۱۰دقیقه‌ای ریکنسیلیشن سفارش‌های زیبال — چهار وظیفه:
     *
     *  ۱) بازیابی سفارش‌های verifying گیرکرده (updated_at > ۱۰ دقیقه قبل → pending)
     *     — اگر فرایند تایید بین ادعای اتمیک و انتقال نهایی کرش کند، بدون این بازیابی
     *     سفارش برای همیشه در verifying می‌ماند.
     *  ۲) دستهٔ pending با track_id و عمر > ۱۰ دقیقه — استعلام سرور-به-سرور از زیبال
     *     (کال‌بک گم‌شده نجات پیدا می‌کند)؛ سپس انقضای still-pending بالای ۲۴ ساعت.
     *  ۳) بازبررسی شکست‌های ۷۲ ساعت اخیر — «failed قابل بازبررسی ۷۲ ساعت»؛ زیبال
     *     گاهی با تأخیر نتیجه واقعی را نشان می‌دهد (واریز تأییدشده بعد از timeout).
     *     پس از ۷۲ ساعت شکست دائمی می‌شود و دست نمی‌خورد.
     *
     * @return void خلاصه در لاگ سرور و آپشن mrdastbaaf_last_reconcile_log ثبت می‌شود
     */
    public static function run_reconciliation() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $controller = new self();
        $now_ts = current_time('timestamp');
        $now = current_time('mysql');

        $processed = 0;
        $recovered = 0;
        $expired = 0;
        $rechecked_failed = 0;

        // ─── ۱) بازیابی سفارش‌های verifying گیرکرده (> ۱۰ دقیقه) ───
        $recover_cutoff = date('Y-m-d H:i:s', $now_ts - 10 * MINUTE_IN_SECONDS);
        $recovered = (int) $wpdb->query($wpdb->prepare(
            "UPDATE $table_orders
             SET status = 'pending', updated_at = %s
             WHERE status = 'verifying' AND updated_at IS NOT NULL AND updated_at < %s",
            $now, $recover_cutoff
        ));
        if ($recovered > 0) {
            error_log('MrDastbaaf: reconciliation recovered ' . $recovered . ' stuck verifying order(s) back to pending');
        }

        // ─── ۲) دستهٔ pending زیبال (کال‌بک گم‌شده) ───
        $batch_cutoff = date('Y-m-d H:i:s', $now_ts - 10 * MINUTE_IN_SECONDS);
        $stale_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_orders
             WHERE gateway = 'zibal' AND status = 'pending'
               AND zibal_track_id IS NOT NULL AND zibal_track_id != ''
               AND created_at < %s
             ORDER BY created_at ASC
             LIMIT 100",
            $batch_cutoff
        ));

        foreach ($stale_orders as $order) {
            $processed++;
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

        // انقضای سفارش‌های همچنان pending بالای ۲۴ ساعت (UPDATE مشروط — هر سفارشی
        // که در استعلام بالا completed/failed شده دیگر pending نیست و دست نمی‌خورد)
        $expire_cutoff = date('Y-m-d H:i:s', $now_ts - DAY_IN_SECONDS);
        $expired = (int) $wpdb->query($wpdb->prepare(
            "UPDATE $table_orders
             SET status = 'cancelled', updated_at = %s
             WHERE gateway = 'zibal' AND status = 'pending' AND created_at < %s",
            $now, $expire_cutoff
        ));
        if ($expired > 0) {
            error_log('MrDastbaaf: reconciliation expired ' . $expired . ' stale pending zibal order(s) to cancelled');
        }

        // ─── ۳) بازبررسی شکست‌های ۷۲ ساعت اخیر ───
        // شکستِ قطعی‌نما (timeout/202) ممکن است در زیبال بعداً «پرداخت‌شده» از آب
        // دربیاید؛ تا ۷۲ ساعت دوباره استعلام می‌کنیم. پس از آن شکست دائمی است و
        // در این چرخه هرگز انتخاب نمی‌شود (برای پرهیز از اسپمِ لاگ هر ۱۰ دقیقه).
        $fail_cutoff = date('Y-m-d H:i:s', $now_ts - 72 * HOUR_IN_SECONDS);
        $failed_orders = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_orders
             WHERE gateway = 'zibal' AND status = 'failed'
               AND updated_at IS NOT NULL AND updated_at >= %s
               AND zibal_track_id IS NOT NULL AND zibal_track_id != ''
             ORDER BY updated_at ASC
             LIMIT 100",
            $fail_cutoff
        ));

        foreach ($failed_orders as $order) {
            // failed → pending سپس هستهٔ تایید مشترک (ادعای اتمیک verify_zibal_order
            // فقط از pending می‌پذیرد؛ reset مشروط اتمیک می‌ماند و رقابت نداریم)
            $reset = $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'failed'",
                $now, $order->id
            ));
            if (!$reset) {
                continue; // هم‌زمان در مسیر دیگری تغییر کرده
            }
            $rechecked_failed++;
            $result = $controller->verify_zibal_order($order, '1');
            if (is_wp_error($result)) {
                // نتیجهٔ استعلام 100 نبود → هستهٔ تایید دوباره failed می‌کند و
                // در دورهای بعدی (تا ۷۲ ساعت) دوباره بررسی می‌شود.
                if ('order_processing' === $result->get_error_code()) {
                    continue;
                }
                error_log('MrDastbaaf: failed re-check for order ' . $order->id . ' — ' . $result->get_error_message());
                if ('verify_unreachable' === $result->get_error_code()) {
                    continue;
                }
            }
        }

        // ─── خلاصهٔ دور جاری ───
        $summary = '[' . $now . '] processed=' . $processed . ' recovered=' . $recovered . ' expired=' . $expired . ' rechecked_failed=' . $rechecked_failed;
        error_log('MrDastbaaf: reconciliation summary ' . $summary);
        update_option('mrdastbaaf_last_reconcile_log', $summary, false);
    }
}
