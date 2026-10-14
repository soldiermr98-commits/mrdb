<?php
/**
 * کنترلر پرداخت آنلاین زیبال و فیش‌های کارت به کارت (Payment & Checkout Controller)
 *
 * v1.5.0: علاوه بر پلن‌های عضویت، initiate با پارامتر اختیاری product_id مسیر
 * «خرید تک‌دوره‌ای» را می‌سازد (plan_id = course-{id})؛ در تایید، سفارشِ واقعی
 * ووکامرس در وضعیت processing ساخته می‌شود و بقیهٔ هستهٔ تایید/ریکنسیلیشن عیناً
 * مشترک باقی می‌ماند.
 *
 * v1.6.0: مسیر سوم «خرید محصول فیزیکی از داخل وب‌اپ» — initiate با
 * {product_id, kind: 'shop'} + اطلاعات ارسال (full_name/phone/address/postal_code).
 * در تایید، سفارش ووکامرسِ واقعی (processing) با نشانی صورتحساب/ارسال کامل ساخته
 * می‌شود → ایمیل استاندارد «سفارش جدید» ووکامرس برای ادمین. سبک: بدون جدول/ستون
 * جدید — اطلاعات ارسال در user_meta «mrdastbaaf_shop_shipping» (یک ردیف به‌ازای
 * هر کاربر، دفعهٔ بعد پیش‌پر می‌شود) نگهداری و در یادداشت سفارش هم درج می‌گردد.
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
     * v1.5.0: اعتبارسنجی محصول دوره برای خرید تک‌دوره‌ای از وب‌اپ — چهار گارد:
     *  ۱) محصول موجود و منتشرشده؛
     *  ۲) عضو دستهٔ دوره‌ها (آپشن mrdastbaaf_course_category — دقیقاً همان گارد
     *     has_term کنترلر دوره‌ها)؛
     *  ۳) قیمت مثبت (get_price با فال‌بک wc_get_price_to_display)؛
     *  ۴) خرید تکراری — بررسی سبک درون همین کلاس: سفارش‌های پرداخت‌شدهٔ حساب کاربر
     *     (همان دو وضعیت تشخیص مالکیت دوره: wc-completed + wc-processing) و نگاشت
     *     اقلام به شناسهٔ محصول (variation هم اضافه می‌شود، همان سبک کنترلر دوره‌ها).
     *
     * @param int $product_id شناسهٔ محصول ووکامرس
     * @param int $user_id    شناسهٔ کاربر خریدار
     * @return array|WP_Error {title:string, price:int} در موفقیت
     */
    private function validate_course_product_for_purchase($product_id, $user_id) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product || 'publish' !== get_post_status($product_id)) {
            return new WP_Error('invalid_product', 'دوره انتخاب‌شده یافت نشد یا منتشر نشده است.', array('status' => 400));
        }

        $course_category = (string) get_option('mrdastbaaf_course_category', 'courses');
        if ('' !== $course_category && !has_term($course_category, 'product_cat', $product_id)) {
            return new WP_Error('not_a_course', 'محصول انتخاب‌شده دوره نیست.', array('status' => 400));
        }

        $price = (int) $product->get_price();
        if ($price <= 0 && function_exists('wc_get_price_to_display')) {
            $price = (int) wc_get_price_to_display($product);
        }
        if ($price <= 0) {
            return new WP_Error('course_not_purchasable', 'این دوره قابل خرید آنلاین نیست؛ با پشتیبانی تماس بگیرید.', array('status' => 400));
        }

        $owned_ids = array();
        $paid_orders = function_exists('wc_get_orders') ? wc_get_orders(array(
            'customer_id' => (int) $user_id,
            'status'      => array('wc-completed', 'wc-processing'),
            'limit'       => 100,
        )) : array();
        if (is_array($paid_orders)) {
            foreach ($paid_orders as $paid_order) {
                if (!$paid_order || !method_exists($paid_order, 'get_items')) {
                    continue;
                }
                foreach ($paid_order->get_items() as $item) {
                    if (!method_exists($item, 'get_product_id')) {
                        continue;
                    }
                    $owned_ids[] = (int) $item->get_product_id();
                    $variation_id = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
                    if ($variation_id > 0) {
                        $owned_ids[] = $variation_id;
                    }
                }
            }
        }
        if (in_array((int) $product_id, $owned_ids, true)) {
            return new WP_Error('already_owned', 'شما قبلاً این دوره را خریداری کرده‌اید؛ از بخش «دوره‌های من» داشبورد به آن دسترسی دارید.', array('status' => 409));
        }

        return array(
            'title' => (string) $product->get_name(),
            'price' => $price,
        );
    }

    /**
     * v1.6.0: اعتبارسنجی محصول فیزیکی/ویژهٔ پیشخوان برای خرید درون‌اپ — آینهٔ
     * validate_course_product_for_purchase اما بدون گارد «قبلاً خریداری‌شده»
     * (محصول فیزیکی تکراراً قابل خرید است) و بدون قید دستهٔ دوره‌ها؛ به‌جای آن
     * محصول باید در فهرست تیک‌خورده‌های «محصولات فیزیکی» یا «محصولات ویژه»
     * پیشخوان باشد (همان چیزی که در اپ نمایش داده می‌شود).
     *
     * @param int $product_id شناسهٔ محصول ووکامرس
     * @return array|WP_Error array{title: string, price: int} در موفقیت
     */
    private function validate_shop_product_for_purchase($product_id) {
        $product = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if (!$product || 'publish' !== get_post_status($product_id)) {
            return new WP_Error('product_not_found', 'محصول انتخاب‌شده یافت نشد یا قابل خرید نیست.', array('status' => 404));
        }

        if (!MrDastbaaf_Shop_Controller::is_shop_product($product_id)) {
            return new WP_Error('product_not_available', 'این محصول برای فروش در وب‌اپلیکیشن فعال نیست.', array('status' => 400));
        }

        $price = (int) $product->get_price();
        if ($price <= 0) {
            return new WP_Error('product_price_invalid', 'قیمت این محصول در ووکامرس تنظیم نشده است؛ لطفاً با پشتیبانی تماس بگیرید.', array('status' => 400));
        }

        return array(
            'title' => (string) get_the_title($product_id),
            'price' => $price,
        );
    }

    /**
     * v1.6.0: نرمال‌سازی موبایل ایرانی — ارقام فارسی/عربی → انگلیسی، حذف کاراکترهای
     * غیرعددی، هدایت 0098/98/+98 و 9x ده‌رقمی به قالب استاندارد 09xxxxxxxxx.
     * خروجی نامعتبر همان رشتهٔ پاک‌شده است؛ caller با regex نهایی تصمیم می‌گیرد.
     *
     * @param mixed $raw ورودی خام (رشته/عدد)
     * @return string
     */
    private function normalize_ir_mobile($raw) {
        $digits = $this->to_english_digits((string) $raw);
        $digits = preg_replace('/\D+/', '', $digits);
        if (strpos($digits, '0098') === 0) {
            $digits = '0' . substr($digits, 4);
        } elseif (strpos($digits, '98') === 0 && strlen($digits) >= 12) {
            $digits = '0' . substr($digits, 2);
        }
        if (strpos($digits, '9') === 0 && strlen($digits) === 10) {
            $digits = '0' . $digits;
        }
        return $digits;
    }

    /**
     * v1.6.0: تبدیل ارقام فارسی/عربی به انگلیسی — نسخهٔ سبک سمت سرور از همان
     * منطق utils/format.ts اپ (تا ورودی‌های فارسی‌صفحه کلاید با regex عددی کار کنند).
     *
     * @param string $value
     * @return string
     */
    private function to_english_digits($value) {
        return strtr((string) $value, array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ));
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
        // v1.5.0: خرید تک‌دوره‌ای — شناسهٔ اختیاری محصول ووکامرس (وقتی plan_id خالی است)
        $product_id = absint($request->get_param('product_id'));
        // v1.6.0: kind = 'shop' → خرید محصول فیزیکی؛ خالی/هر چیز دیگر → مسیر دورهٔ 1.5.0 (سازگاری کامل)
        $kind = sanitize_text_field($request->get_param('kind'));
        $shop_shipping_meta = null;

        /**
         * v1.5.0: دو مسیر سفارش با یک هستهٔ مشترک — اشتراک (plan_id) یا خرید
         * تک‌دوره‌ای (product_id). هر دو مسیر به همان رکورد جدول orders ختم می‌شوند؛
         * برای دوره‌ها plan_id با پیشوند «course-» ذخیره می‌شود (finalize_verified_order
         * شاخه‌اش را جدا می‌کند) و همهٔ گاردهای پایین (درگاه، سقف pending، BUG-15،
         * insert_or_fail) بدون استثنا روی هر دو اعمال می‌شوند.
         *
         * v1.6.0: مسیر سوم — product_id + kind='shop' (محصول فیزیکی) با پیشوند
         * «shop-»؛ اطلاعات ارسال پس از پذیرش درگاه ذخیره و در finalize مصرف می‌شود.
         */
        if ('' !== $plan_id) {
            $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
            $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s AND is_active = 1", $plan_id));

            if (!$plan) {
                return new WP_Error('invalid_plan', 'پلن انتخاب‌شده معتبر نیست.', array('status' => 400));
            }

            // BUG-36: پلن رایگان هرگز نباید به درگاه پرداخت هدایت شود
            if ((int) $plan->price_toman <= 0) {
                return new WP_Error('free_plan_no_gateway', 'پلن رایگان نیازی به پرداخت درگاه ندارد.', array('status' => 400));
            }

            $order_plan_id = (string) $plan->id;
            $item_title = (string) $plan->title;
            $amount_toman = (int) $plan->price_toman;
            $zibal_description = 'خرید اشتراک ' . $plan->title . ' در مستر دستباف';
        } elseif ($product_id > 0 && 'shop' === $kind) {
            /**
             * v1.6.0: خرید محصول فیزیکی از داخل وب‌اپ — همان هستهٔ initiate؛ فقط
             * اعتبارسنجی محصول از فهرست «محصولات فیزیکی/ویژه» پیشخوان است و
             * اطلاعات ارسال (نام/موبایل/نشانی/کدپستی) پیش از ثبت سفارش سنجیده
             * می‌شود. بدون ورود، هیچ مسیری اجرا نمی‌شود (permission_logged_in).
             */
            $shop_check = $this->validate_shop_product_for_purchase($product_id);
            if (is_wp_error($shop_check)) {
                return $shop_check;
            }

            $ship_full_name = sanitize_text_field($request->get_param('full_name'));
            $ship_phone = $this->normalize_ir_mobile($request->get_param('phone'));
            $ship_address = trim((string) sanitize_textarea_field($request->get_param('address')));
            $ship_postal = trim((string) preg_replace('/\D+/', '', $this->to_english_digits((string) $request->get_param('postal_code'))));

            if ('' === $ship_full_name) {
                return new WP_Error('invalid_shipping', 'نام و نام خانوادگی گیرنده الزامی است.', array('status' => 400));
            }
            if (!preg_match('/^09\d{9}$/', $ship_phone)) {
                return new WP_Error('invalid_shipping', 'شماره موبایل گیرنده معتبر نیست (مثال: 09123456789).', array('status' => 400));
            }
            if (mb_strlen($ship_address) < 10) {
                return new WP_Error('invalid_shipping', 'برای ارسال محصول، نشانی کامل پستی (استان، شهر، خیابان، پلاک) الزامی است.', array('status' => 400));
            }
            if ('' !== $ship_postal && !preg_match('/^\d{5,10}$/', $ship_postal)) {
                return new WP_Error('invalid_shipping', 'کد پستی معتبر نیست.', array('status' => 400));
            }

            $order_plan_id = 'shop-' . $product_id; // در محدودهٔ varchar(64)
            $item_title = $shop_check['title'];
            $amount_toman = $shop_check['price'];
            $zibal_description = 'خرید محصول ' . $shop_check['title'] . ' در مستر دستباف';
            // پس از موفقیت درگاه در همین initiate ذخیره می‌شود (پیش از return)
            $shop_shipping_meta = array(
                'full_name'   => $ship_full_name,
                'phone'       => $ship_phone,
                'address'     => $ship_address,
                'postal_code' => $ship_postal,
            );
        } elseif ($product_id > 0) {
            $course_check = $this->validate_course_product_for_purchase($product_id, $user_id);
            if (is_wp_error($course_check)) {
                return $course_check;
            }

            $order_plan_id = 'course-' . $product_id; // در محدودهٔ varchar(64)
            $item_title = $course_check['title'];
            $amount_toman = $course_check['price'];
            $zibal_description = 'خرید دوره ' . $course_check['title'] . ' در مستر دستباف';
        } else {
            return new WP_Error('invalid_plan', 'پلن انتخاب‌شده معتبر نیست.', array('status' => 400));
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
            'plan_id' => $order_plan_id,
            'item_title' => $item_title,
            'amount_toman' => $amount_toman,
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
        $amount_rial = $amount_toman * 10;
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
                'description' => $zibal_description,
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

        /**
         * v1.6.0: اطلاعات ارسال محصول فیزیکی — فقط پس از پذیرش درگاه ذخیره می‌شود
         * تا شکستِ initiate هیچ ردیفی جا نگذارد. یک کلید ثابت به‌ازای هر کاربر
         * (بازنویسی هر بار) — دفعهٔ بعدِ خرید همین اطلاعات پیش‌پر می‌شود و هیچ
         * ردیفِ یتیمی انبار نمی‌شود (پاکسازی لازم ندارد؛ سبک ماندن افزونه).
         */
        if (is_array($shop_shipping_meta)) {
            update_user_meta($user_id, 'mrdastbaaf_shop_shipping', $shop_shipping_meta);
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
     *
     * v1.5.0: سفارش‌های «course-{id}» (خرید تک‌دوره‌ای) پیش از پرس‌وجوی پلن به
     * شاخهٔ اختصاصی finalize_course_order می‌روند — همان هستهٔ ادعای اتمیک، گاردهای
     * مبلغ و انتقال نهایی verifying→completed برای هر دو مسیر مشترک است و از طریق
     * همین متد هم مسیر کال‌بک و هم ریکنسیلیشن کرون به شاخهٔ دوره می‌رسد.
     */
    private function finalize_verified_order($order, $ref_number) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $now = current_time('mysql');

        // v1.5.0: شاخهٔ خرید تک‌دوره‌ای — plan_id با پیشوند «course-»
        if (strpos((string) $order->plan_id, 'course-') === 0) {
            return $this->finalize_course_order($order, $ref_number);
        }

        // v1.6.0: شاخهٔ خرید محصول فیزیکی — plan_id با پیشوند «shop-»
        if (strpos((string) $order->plan_id, 'shop-') === 0) {
            return $this->finalize_shop_order($order, $ref_number);
        }

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
     * v1.5.0: تکمیل سفارش تاییدشدهٔ خرید تک‌دوره‌ای — ساخت «سفارش واقعی ووکامرس»
     * در وضعیت processing (هر دو وضعیت wc-completed/wc-processing در تشخیص مالکیت
     * دوره معتبرند → دوره همان لحظه در اپ باز می‌شود) + ایمیل استاندارد «سفارش جدید»
     * ووکامرس برای ادمین.
     *
     * الگوی BUG-14: فعال‌سازی قبل از completed؛ اگر ساخت سفارش ووکامرس به هر دلیل
     * شکست بخورد (محصول حذف‌شده/خطای DB/Exception)، رکورد سفارش افزونه به pending
     * برمی‌گردد تا ریکنسیلیشن/پشتیبانی دستی بررسی کند و پول کاربر گم نشود —
     * خطا هرگز بلعیده نمی‌شود.
     *
     * @param object $order      رکورد سفارش افزونه (plan_id = course-{id})
     * @param string $ref_number شماره مرجع زیبال
     * @return array|WP_Error پاسخ قراردادی در موفقیت
     */
    private function finalize_course_order($order, $ref_number) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $now = current_time('mysql');

        $product_id = absint(substr((string) $order->plan_id, 7));
        $product = ($product_id > 0 && function_exists('wc_get_product')) ? wc_get_product($product_id) : false;
        if (!$product) {
            // الگوی membership_grant_failed — سفارش برای بررسی دستی باقی می‌ماند
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            error_log('MrDastbaaf: course product missing for verified order ' . $order->id . ' (plan_id: ' . $order->plan_id . ')');
            return new WP_Error('course_order_failed', 'پرداخت تایید شد اما ایجاد سفارش دوره ممکن نشد؛ سفارش برای بررسی دستی باقی ماند.', array('status' => 409));
        }

        try {
            $wc_order = wc_create_order(array('customer_id' => (int) $order->user_id, 'created_via' => 'mrdastbaaf-webapp'));
            if (is_wp_error($wc_order)) {
                throw new Exception($wc_order->get_error_message());
            }
            $wc_order->add_product($product, 1);
            $wc_order->set_address(array(
                'first_name' => (string) $order->user_fullname,
                'phone'      => (string) $order->user_phone,
            ), 'billing');
            $wc_order->set_payment_method_title('درگاه زیبال (وب‌اپ مستر دستباف)');
            $wc_order->update_meta_data('_mrdastbaaf_order_number', (string) $order->order_number);
            $wc_order->update_meta_data('_mrdastbaaf_zibal_track', (string) $order->zibal_track_id);
            $wc_order->add_order_note('خرید تک‌دوره‌ای از وب‌اپ — کد پیگیری زیبال: ' . (string) $order->zibal_track_id);
            $wc_order->update_status('processing');
            $wc_order->save();
        } catch (Exception $e) {
            error_log('MrDastbaaf: WC course order creation failed for order ' . $order->id . ' — ' . $e->getMessage());
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('course_order_failed', 'پرداخت تایید شد اما ایجاد سفارش دوره ممکن نشد؛ سفارش برای بررسی دستی باقی ماند.', array('status' => 409));
        } catch (Throwable $e) {
            error_log('MrDastbaaf: WC course order creation error (Throwable) for order ' . $order->id . ' — ' . $e->getMessage());
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('course_order_failed', 'پرداخت تایید شد اما ایجاد سفارش دوره ممکن نشد؛ سفارش برای بررسی دستی باقی ماند.', array('status' => 409));
        }

        // ثبت موفقیت فاکتور — کپی همان انتقال نهایی هستهٔ اشتراک (فقط از verifying)
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

        // آینهٔ mrdastbaaf_membership_granted برای پیاده‌سازی اعلان خرید دوره در کار آینده
        do_action('mrdastbaaf_course_purchased', (int) $order->user_id, $product_id, $order->id);

        return array(
            'success' => true,
            'orderNumber' => $order->order_number,
            'refNumber' => $ref_number,
            'membershipMode' => 'course',
            'membershipExpiresAt' => null,
            'message' => 'پرداخت با موفقیت تایید شد و دوره به حساب شما اضافه شد.',
        );
    }

    /**
     * v1.6.0: تکمیل سفارش تاییدشدهٔ «خرید محصول فیزیکی از وب‌اپ» — ساخت سفارش
     * ووکامرسِ واقعی در وضعیت processing با نشانی کامل صورتحساب/ارسال (از
     * user_meta «mrdastbaaf_shop_shipping») → استوک محصول کم می‌شود و ایمیل
     * استاندارد «سفارش جدید» ووکامرس برای ادمین می‌رود (اعلان ادمین بدون هیچ
     * کانال اضافه‌ای). یادداشت سفارش، خلاصهٔ نشانی + کد پیگیری زیبال را دارد.
     *
     * الگوی BUG-14 عیناً حفظ شده: هر شکستی (محصول حذف‌شده/Exception) سفارش را
     * به pending برمی‌گرداند تا ریکنسیلیشن/پشتیبانی دستی بررسی کند — پول کاربر
     * هرگز گم نمی‌شود و خطا بلعیده نمی‌شود. متای ارسال حذف نمی‌شود تا هم برای
     * تلاش بعدی ریکنسیلیشن موجود باشد و هم به‌عنوان نشانی پیش‌پرِ خرید بعدی کاربر.
     *
     * @param object $order      رکورد سفارش افزونه (plan_id = shop-{id})
     * @param string $ref_number شماره مرجع زیبال
     * @return array|WP_Error پاسخ قراردادی در موفقیت
     */
    private function finalize_shop_order($order, $ref_number) {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $now = current_time('mysql');

        $product_id = absint(substr((string) $order->plan_id, 5)); // پسوند «shop-»
        $product = ($product_id > 0 && function_exists('wc_get_product')) ? wc_get_product($product_id) : false;
        if (!$product) {
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            error_log('MrDastbaaf: shop product missing for verified order ' . $order->id . ' (plan_id: ' . $order->plan_id . ')');
            return new WP_Error('shop_order_failed', 'پرداخت تایید شد اما ایجاد سفارش محصول ممکن نشد؛ سفارش برای بررسی دستی باقی ماند.', array('status' => 409));
        }

        // اطلاعات ارسال ثبت‌شده در initiate (یک ردیف به‌ازای هر کاربر) — غایب = خرید قدیمی/دستی
        $shipping = get_user_meta((int) $order->user_id, 'mrdastbaaf_shop_shipping', true);
        $shipping = is_array($shipping) ? $shipping : array();
        $full_name = isset($shipping['full_name']) ? sanitize_text_field($shipping['full_name']) : '';
        $phone = isset($shipping['phone']) ? sanitize_text_field($shipping['phone']) : '';
        $address = isset($shipping['address']) ? sanitize_textarea_field($shipping['address']) : '';
        $postal = isset($shipping['postal_code']) ? sanitize_text_field($shipping['postal_code']) : '';

        try {
            $wc_order = wc_create_order(array('customer_id' => (int) $order->user_id, 'created_via' => 'mrdastbaaf-webapp'));
            if (is_wp_error($wc_order)) {
                throw new Exception($wc_order->get_error_message());
            }
            $wc_order->add_product($product, 1);

            // کشور پیش‌فرض فروشگاه («IR:THR» → «IR») — ساب‌کی استان حذف می‌شود
            $base_country = get_option('woocommerce_default_country', 'IR');
            $base_country = (is_string($base_country) && '' !== $base_country) ? substr($base_country, 0, 2) : 'IR';

            $billing = array(
                'first_name' => ('' !== $full_name) ? $full_name : (string) $order->user_fullname,
                'phone'      => ('' !== $phone) ? $phone : (string) $order->user_phone,
                'country'    => $base_country,
            );
            if ('' !== $address) {
                $billing['address_1'] = $address;
                $billing['postcode'] = $postal;
            }
            $wc_order->set_address($billing, 'billing');

            if ('' !== $address) {
                $wc_order->set_address(array(
                    'first_name' => $billing['first_name'],
                    'address_1'  => $address,
                    'postcode'   => $postal,
                    'country'    => $base_country,
                ), 'shipping');
            } else {
                $wc_order->add_order_note('نشانی پستی در وب‌اپ ثبت نشده بود — هماهنگی ارسال با تماس تلفنی.');
            }

            $wc_order->set_payment_method_title('درگاه زیبال (وب‌اپ مستر دستباف)');
            $wc_order->update_meta_data('_mrdastbaaf_order_number', (string) $order->order_number);
            $wc_order->update_meta_data('_mrdastbaaf_zibal_track', (string) $order->zibal_track_id);
            $wc_order->add_order_note('خرید محصول فیزیکی از وب‌اپ — کد پیگیری زیبال: ' . (string) $order->zibal_track_id);
            $wc_order->update_status('processing');
            $wc_order->save();
        } catch (Exception $e) {
            error_log('MrDastbaaf: WC shop order creation failed for order ' . $order->id . ' — ' . $e->getMessage());
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('shop_order_failed', 'پرداخت تایید شد اما ایجاد سفارش محصول ممکن نشد؛ سفارش برای بررسی دستی باقی ماند.', array('status' => 409));
        } catch (Throwable $e) {
            error_log('MrDastbaaf: WC shop order creation error (Throwable) for order ' . $order->id . ' — ' . $e->getMessage());
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $order->id
            ));
            return new WP_Error('shop_order_failed', 'پرداخت تایید شد اما ایجاد سفارش محصول ممکن نشد؛ سفارش برای بررسی دستی باقی ماند.', array('status' => 409));
        }

        // ثبت موفقیت فاکتور — کپی همان انتقال نهایی هستهٔ اشتراک (فقط از verifying)
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

        // آینهٔ هوک‌های دیگر مسیرها برای اطلاع‌رسانی‌های آینده
        do_action('mrdastbaaf_shop_purchased', (int) $order->user_id, $product_id, $order->id);

        return array(
            'success' => true,
            'orderNumber' => $order->order_number,
            'refNumber' => $ref_number,
            'membershipMode' => 'shop',
            'membershipExpiresAt' => null,
            'message' => 'پرداخت با موفقیت تایید شد؛ سفارش محصول شما در سایت ثبت شد و برای هماهنگی ارسال با شما تماس گرفته می‌شود.',
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
