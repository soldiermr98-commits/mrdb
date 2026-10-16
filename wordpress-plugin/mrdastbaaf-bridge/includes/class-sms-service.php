<?php
/**
 * سرویس ارسال پیامک واقعی (v1.1.0) — دیسپچ فرایند-جدید از v1.7.8
 *
 * تا نسخهٔ ۱.۰.۳ ارسال OTP فقط به هوک «mrdastbaaf_send_otp_sms» واگذار می‌شد و
 * در نصب‌های بدون افزونهٔ ثالث، هیچ پیامکی واقعاً ارسال نمی‌شد. این کلاس ارسال
 * مستقیم از طریق الگوی BaseServiceNumber ملی‌پیامک را پیاده‌سازی می‌کند و نتیجهٔ
 * آخرین ارسال (موفق/خطا + پیام فارسی) را در آپشن mrdastbaaf_sms_last_log ثبت
 * می‌کند تا مدیر در صفحهٔ تنظیمات ببیند.
 *
 * v1.7.6 (حادثهٔ تایم‌اوت OTP — ۲۰۲۶/۱۰/۱۶):
 *  اندازه‌گیری زندهٔ پروداکشن نشان داد مسیر «تولید کد + ارسال همگام» در همان
 *  درخواست REST تا ۴۶-۶۲ ثانیه طول می‌کشد (در حالی که ملی‌پیامک از شبکهٔ دیگر
 *  ۱.۳ ثانیه‌ای پاسخ می‌دهد) و در نهایت پاسخ HTTP 200 با بدنهٔ خالی برمی‌گردد؛
 *  وب‌اپ با سقف ۲۰ ثانیه‌ای خود پیام «زمان انتظار پاسخ سرور به پایان رسید» می‌داد.
 *  فیکس ۱.۷.۶: ارسال پیامک هرگز در درخواست REST اجرا نمی‌شود — با
 *  wp_schedule_single_event به صف WP-Cron می‌رود (spawn_cron برای شروع فوری) و
 *  نتیجه در لاگ پیشخوان با زمان‌سنجی هر مرحله ثبت می‌شود تا ریشهٔ کندی (هوک ثالث /
 *  سرویس‌دهنده / شبکهٔ خروجی هاست) از پیشخوان قابل تشخیص باشد.
 *
 * v1.7.7 (تکمیل فیکس — پروب‌های زندهٔ پس از نصب ۱.۷.۶):
 *  پروب‌های پروداکشن بعد از نصب ۱.۷.۶ نشان داد مسیر معتبر هنوز تا ۷۴.۶ ثانیه
 *  معلق می‌ماند (HTTP 200 با ۰ بایت)، در حالی که transient نرخ در «همان درخواست
 *  معلق» نوشته شده بود (پروب B: 429 در ۲.۳s) و verify-otp با bcrypt هم ۲.۱s
 *  پاسخ می‌داد؛ یعنی تعلیق دقیقاً بعد از نوشتن‌ها و داخل schedule_async_send /
 *  fallback همگام بود (زمان‌بندی کرون، loopback یا هوک ثالث — از بیرون تفکیک‌ناپذیر).
 *  فیکس ۱.۷.۷: ارسال با register_shutdown_function به بعد از fastcgi_finish_request
 *  منتقل شد.
 *
 * v1.7.8 (فیکس نهایی — پروب‌های زندهٔ پس از نصب ۱.۷.۷):
 *  پروب‌ها روی پروداکشن ۱.۷.۷ تکرار شد: مسیر 429 → 1.7s، مسیر verify-otp → 1.9s،
 *  اما مسیر «گذرندهٔ» request-otp همچنان ۳۶.۱s و ۵۵.۳s طول می‌کشید و پاسخ
 *  HTTP 200 با ۰ بایت بود و هیچ ردیفی در mrdastbaaf_sms_last_log ثبت نمی‌شد.
 *  نتیجهٔ تشخیص: روی این هاست (LiteSpeed + WAF) «هیچ» کاری در فرایند PHP یک
 *  درخواست نباید بیش از چند ثانیه طول بکشد؛ سرور فرایند را قطع می‌کند و چون
 *  fastcgi_finish_request/litespeed_finish_request پاسخ را زودتر تحویل نمی‌دهند،
 *  هم بدنهٔ پاسخ گم می‌شود و هم ادامهٔ کار (ارسال پیامک/ثبت لاگ) هرگز اجرا نمی‌شود.
 *  نتیجه: register_shutdown_function روی این هاست بی‌اثر است.
 *  معماری ۱.۷.۸ — «باکس خروجی + دیسپچ در فرایند واقعاً جداست»:
 *   ۱) مسیر پاسخ request-otp فقط primitives: اعتبارسنجی + rate-limit + bcrypt +
 *      نوشتن transient + ثبت در «باکس خروجی» (transient) — بدون هیچ شبکه/کرون/هوکی.
 *   ۲) تریگر فوری دیسپچ: یک درخواست loopback «غیربلاک‌کننده» با سقف زمانی عدد
 *      صحیح ۲ ثانیه (هشدار: timeout کسری مثل 0.01 در cURL به long=0 یعنی
 *      «بی‌نهایت» گرد می‌شود — ریشهٔ تعلیق ۷۴s نسخهٔ ۱.۷.۶!) به اندپوینت
 *      POST /auth/otp-dispatch. حتی اگر این تریگر شکست بخورد، مسیر پاسخ دست‌نخورده
 *      در ~۲-۳ ثانیه تحویل می‌شود.
 *   ۳) اندپوینت دیسپچ در فرایند PHP «جدید» اجرا می‌شود (درخواست loopback مستقل):
 *      باکس را می‌خواند، ملی‌پیامک را اول صدا می‌زند (سریع و اثبات‌شده) و لاگ را
 *      قبل از هر کار کندی دیگری می‌نویسد. هوک ثالث فقط وقتی اجرا می‌شود که
 *      ملی‌پیامک تنظیم نباشد (سایت‌هایی که فقط با افزونهٔ پیامکی ثالث کار می‌کنند) —
 *      هوک‌های کند دیگر هرگز جلوی ارسال/لاگ ملی‌پیامک را نمی‌گیرند.
 *   ۴) خودترمیمی: هوک init روی «هر» درخواست وردپرس (پنل/سایت/REST) با سقف
 *      زمانی ۴۵ ثانیه باکسِ غیرخالی را دوباره تریگر می‌کند؛ پس حتی شکست تریگر
 *      فوری با بعدیِ درخواست‌های سایت جبران می‌شود.
 *   ۵) مارکر «ارسال‌شده» (mrdastbaaf_otp_sent_*) از ارسال تکراری جلوگیری می‌کند و
 *      ورودی‌های بالای ۱۵ دقیقه / سه تلاش ناموفق با لاگ فارسی حذف می‌شوند.
 *
 * نکتهٔ امنیتی: رمز سرویس پیامک در پاسخ‌ها/لاگ‌ها هرگز بازتاب داده نمی‌شود و
 * شمارهٔ موبایل در لاگ فقط به شکل ماسک‌شده نوشته می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Sms_Service {

    /** v1.7.8: نام transient باکس خروجی (صف ارسال‌های در انتظار دیسپچ). */
    const OUTBOX_TRANSIENT = 'mrdastbaaf_otp_outbox';

    /** v1.7.8: فلگ autoload شدهٔ «باکس غیرخالی است» — چک init بدون کوئری اضافه. */
    const OUTBOX_PENDING_OPTION = 'mrdastbaaf_otp_outbox_pending';

    /** v1.7.8: حداکثر تعداد ورودی همزمان در باکس (قدیمی‌ترین حذف می‌شود). */
    const OUTBOX_MAX_ENTRIES = 5;

    /** v1.7.8: عمر مفید ورودی باکس — کد OTP فقط ۱۲۰ ثانیه اعتبار دارد. */
    const OUTBOX_TTL_SECONDS = 900;

    /** v1.7.8: قفل جلوگیری از دیسپچ موازی. */
    const DISPATCH_LOCK_TRANSIENT = 'mrdastbaaf_otp_dispatch_lock';

    /** v1.7.8: کلید فاصلهٔ حداقلی دو تریگر خودترمیمی init. */
    const RETRIGGER_COOLDOWN_TRANSIENT = 'mrdastbaaf_otp_retrigger_cd';

    /** v1.7.8: فاصلهٔ حداقلی دو تریگر خودترمیمی init (ثانیه). */
    const RETRIGGER_COOLDOWN_SECONDS = 45;

    /** v1.7.8: آپشن‌ها/توابع — فقط خواندن آپشن (بدون شبکه) — نسخهٔ ۱.۷.۷ حفظ شد. */
    public static function is_ready() {
        if ('melipayamak' !== get_option('mrdastbaaf_sms_provider', 'none')) {
            return false;
        }
        if ('' === trim((string) get_option('mrdastbaaf_sms_username', ''))) {
            return false;
        }
        if ('' === (string) get_option('mrdastbaaf_sms_password', '')) {
            return false;
        }
        return true;
    }

    // ─────────────────────────────────────────────────────────────────────
    // v1.7.8: باکس خروجی + تریگر دیسپچ
    // ─────────────────────────────────────────────────────────────────────

    /**
     * v1.7.8: ثبت ارسال در «باکس خروجی» — فقط دو نوشتنِ سریع (transient + آپشن
     * autoload شده). هیچ شبکه/کرون/هوکی در این متد اجرا نمی‌شود و در مسیر پاسخ
     * request-otp امن است.
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code  کد ۶ رقمی
     * @return void
     */
    public static function queue_outbox_send($phone, $code) {
        $phone = (string) $phone;
        $code  = (string) $code;

        $outbox = get_transient(self::OUTBOX_TRANSIENT);
        if (!is_array($outbox)) {
            $outbox = array();
        }

        // کد تازهٔ همان شماره جایگزین ورودی قبلی می‌شود (ضد ارسال کد کهنه)
        $kept = array();
        foreach ($outbox as $entry) {
            if (is_array($entry) && isset($entry['phone']) && (string) $entry['phone'] === $phone) {
                continue;
            }
            $kept[] = $entry;
        }
        $kept[] = array(
            'phone'    => $phone,
            'code'     => $code,
            'ts'       => time(),
            'attempts' => 0,
        );
        if (count($kept) > self::OUTBOX_MAX_ENTRIES) {
            $kept = array_slice($kept, -self::OUTBOX_MAX_ENTRIES);
        }

        set_transient(self::OUTBOX_TRANSIENT, $kept, self::OUTBOX_TTL_SECONDS);
        // فلگ autoload=yes → چک init در هوک init از alloptions می‌خواند (صفر کوئری اضافه)
        update_option(self::OUTBOX_PENDING_OPTION, time(), true);
    }

    /** v1.7.8: آیا باکس خروجی ورودی در انتظار دارد؟ (خواندن آپشن autoload شده) */
    public static function has_pending_outbox() {
        return (int) get_option(self::OUTBOX_PENDING_OPTION, 0) > 0;
    }

    /**
     * v1.7.8: خودترمیمی — روی هوک init «هر» درخواست وردپرس اجرا می‌شود؛ اگر باکس
     * غیرخالی باشد و از آخرین تریگر بیش از ۴۵ ثانیه گذشته باشد، دیسپچ را دوباره
     * تریگر می‌کند. هزینهٔ حالت عادی (باکس خالی): یک خواندن از alloptions کش‌شده —
     * صفر کوئری اضافه.
     *
     * @return void
     */
    public static function maybe_retrigger() {
        if (!self::has_pending_outbox()) {
            return;
        }
        if (get_transient(self::RETRIGGER_COOLDOWN_TRANSIENT)) {
            return;
        }
        set_transient(self::RETRIGGER_COOLDOWN_TRANSIENT, 1, self::RETRIGGER_COOLDOWN_SECONDS);
        self::trigger_dispatch();
    }

    /**
     * v1.7.10: گارد سقف زمانی تریگر — با اولویت PHP_INT_MAX نصب می‌شود تا هر
     * بازنویسیِ فیلتر http_request_timeout از سوی افزونه‌های ثالث (پشتیبان‌گیری،
     * به‌روزرسانی خودکار، امنیت و…) که سقف ۲ ثانیه را به ۳۰-۶۰ ثانیه بالا می‌برد
     * بی‌اثر شود. پروب زندهٔ ۱.۷.۹ (۱۶ اکتبر): request-otp با timeout=2 هم ~۴۷
     * ثانیه معلق ماند و فرایند پیش از نوشتن لاگ کشته شد — همین گارد برای همین
     * سناریوست. مقدار نهایی: همیشه ۲ ثانیهٔ صحیح.
     *
     * @param mixed $timeout مقدار timeout پس از زنجیرهٔ فیلترها
     * @return int سقف ۲ ثانیه
     */
    public static function force_trigger_timeout_guard($timeout) {
        return 2;
    }

    /**
     * v1.7.8: تریگر دیسپچ — درخواست loopback غیربلاک‌کننده به
     * POST /auth/otp-dispatch (فرایند PHP مستقل = هیچ کندی به درخواست جاری
     * منتقل نمی‌شود).
     *
     * نکتهٔ بحرانی: timeout باید «عدد صحیح ≥ ۱» باشد. WP_Http مقدار timeout را
     * مستقیم به CURLOPT_TIMEOUT (long) می‌دهد؛ مقدار کسری مثل 0.01 به 0 گرد
     * می‌شود که در cURL یعنی «بدون سقف زمانی» — همین ریشهٔ تعلیق ۷۴ ثانیه‌ای
     * spawn_cron در نسخهٔ ۱.۷.۶ بود (spawn_cron هسته با timeout=0.01 کار می‌کند).
     *
     * v1.7.10: گارد http_request_timeout با اولویت PHP_INT_MAX حین فراخوانی نصب
     * و بلافاصله برداشته می‌شود تا سقف ۲ ثانیه از هر منبعی (افزونهٔ ثالث/موهیتیس)
     * مصون بماند؛ زمان واقعی تریگر نیز اندازه‌گیری و اگر >۳s شد در لاگ ثبت می‌شود.
     *
     * @param bool $log_errors ثبت خطا در لاگ پیشخوان (برای تریگر فوری request-otp روشن است)
     * @return bool true = تریگر ارسال شد یا دیسپچ دیگری در جریان بود
     */
    public static function trigger_dispatch($log_errors = false) {
        if (get_transient(self::DISPATCH_LOCK_TRANSIENT)) {
            return true; // دیسپچ دیگری در جریان است — باکس را همان پردازش می‌کند
        }

        add_filter('http_request_timeout', array(__CLASS__, 'force_trigger_timeout_guard'), PHP_INT_MAX);
        $t_trigger = microtime(true);
        $response = wp_remote_post(
            rest_url('mrdastbaaf/v1/auth/otp-dispatch'),
            array(
                'timeout'  => 2, // عدد صحیح — هرگز کسری نکنید (توضیح بالا)
                'blocking' => false,
                'body'     => array('trigger' => 'outbox'),
            )
        );
        remove_filter('http_request_timeout', array(__CLASS__, 'force_trigger_timeout_guard'), PHP_INT_MAX);
        $trigger_elapsed = round(microtime(true) - $t_trigger, 2);

        if (is_wp_error($response)) {
            if ($log_errors && !get_transient('mrdastbaaf_otp_dispatch_err_cd')) {
                // ثبت خطا با سقف نرخ ۱۰ دقیقه‌ای — لاگ پیشخوان ریشهٔ واقعی را نشان می‌دهد
                set_transient('mrdastbaaf_otp_dispatch_err_cd', 1, 600);
                self::write_admin_log(
                    'هشدار — تریگر دیسپچ پیامک به فرایند پس‌زمینه ناموفق بود: ' . $response->get_error_message()
                    . ' (زمان: ' . $trigger_elapsed . 's)؛ ارسال با درخواست بعدی سایت (خودترمیمی ۴۵ ثانیه‌ای) دوباره تلاش می‌شود.'
                );
            }
            return false;
        }

        // v1.7.10: تشخیص پایدار — اگر با گارد فعال هم بیش از ۳ ثانیه طول کشید،
        // ریشه جای دیگری است (شبکهٔ خروجی هاست به خود دامنه / کش آبجکت / قفل
        // جدول آپشن‌ها) و با سقف نرخ ۱۰ دقیقه‌ای در لاگ ثبت می‌شود.
        if ($trigger_elapsed > 3 && !get_transient('mrdastbaaf_otp_trigger_slow_cd')) {
            set_transient('mrdastbaaf_otp_trigger_slow_cd', 1, 600);
            self::write_admin_log(
                'هشدار — تریگر دیسپچ با گارد سقف ۲ ثانیه‌ای هم ' . $trigger_elapsed . 's طول کشید؛'
                . ' ریشهٔ احتمالی: کندی loopback (شبکهٔ خروجی هاست به خود دامنه) یا قفل جدول آپشن‌ها.'
            );
        }

        return true;
    }

    // ─────────────────────────────────────────────────────────────────────
    // v1.7.8: پردازش باکس در فرایند جداست (اندپوینت دیسپچ / کرون)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * v1.7.8: اجرای دیسپچ — از اندپوینت REST /auth/otp-dispatch (فرایند loopback
     * مستقل) یا رویداد کرون فراخوانی می‌شود. در فرایند جاری «هیچ کلاینتی منتظر
     * پاسخ نیست»؛ کندی هوک ثالث/سرویس‌دهنده به هیچ درخواست کاربری منتقل نمی‌شود.
     *
     * ترتیب عمدی: ملی‌پیامک اول (سریع و اثبات‌شده) → ثبت لاگ → مارکر ارسال.
     * هوک ثالث فقط وقتی اجرا می‌شود که ملی‌پیامک تنظیم نباشد؛ در نتیجه هوک‌های
     * کند هرگز جلوی ارسال/لاگ ملی‌پیامک را نمی‌گیرند (ریشهٔ تعلیق ۳۵-۵۵s حادثه).
     *
     * @return array خلاصهٔ نتیجه برای پاسخ REST/کرون
     */
    public static function handle_dispatch() {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true); // قطع اتصال loopback پردازش را نمی‌کشد
        }

        $result = array(
            'processed' => 0,
            'results'   => array(),
            'pending'   => 0,
        );

        if (get_transient(self::DISPATCH_LOCK_TRANSIENT)) {
            $result['skipped'] = 'lock';
            return $result;
        }
        set_transient(self::DISPATCH_LOCK_TRANSIENT, 1, 30);

        try {
            // پایان try/finally در پایین متد — قفل در «هر» مسیر خروج آزاد می‌شود
            $outbox = get_transient(self::OUTBOX_TRANSIENT);
            if (!is_array($outbox) || empty($outbox)) {
                update_option(self::OUTBOX_PENDING_OPTION, 0, true);
                $result['skipped'] = 'empty';
                return $result;
            }

            $remaining = array();
            foreach ($outbox as $entry) {
                if (!is_array($entry) || !isset($entry['phone'], $entry['code'])) {
                    continue;
                }

                $phone = (string) $entry['phone'];
                $code  = (string) $entry['code'];
                $mask  = substr($phone, 0, 4) . '***' . substr($phone, -4);
                $age   = time() - (int) $entry['ts'];

                // سقف پردازش در هر دیسپچ: ۲ ورودی (v1.7.9: با timeout ملی‌پیامک ۱۰s
                // بدترین حالت ~۲۲ ثانیه زیر آستانهٔ کشته‌شدن فرایند این هاست)
                if (count($result['results']) >= 2) {
                    $remaining[] = $entry;
                    continue;
                }

                if ($age > self::OUTBOX_TTL_SECONDS) {
                    self::write_log('رد شد — ورودی باکس خروجی قدیمی‌تر از ۱۵ دقیقه بود (' . $mask . ')؛ حذف شد.');
                    continue;
                }

                // کد منقضی/مصرف‌شده = ارسال بی‌مصرف؛ لغو با لاگ (رفتار ۱.۷.۶ حفظ شد)
                if (get_transient('mrdastbaaf_otp_val_' . md5($phone)) === false) {
                    self::write_log('رد شد — کد OTP ' . $mask . ' منقضی یا مصرف شده بود؛ ارسال لغو شد.');
                    continue;
                }

                // ضد ارسال تکراری (بعد از موفقیت دیسپچ قبلی)
                if (get_transient('mrdastbaaf_otp_sent_' . md5($phone . '|' . $code))) {
                    continue;
                }

                $send_result = self::send_direct($phone, $code);
                $result['processed'] = (int) $result['processed'] + 1;

                // v1.7.9: پس از ارسال، کش شماره→کاربر گرم می‌شود تا verify-otp
                // (مسیر پاسخ) هرگز منتظر فال‌بک متای کند نماند. عمداً «بعد از»
                // ارسال است تا هر کندی احتمالی به ارسال پیامک آسیب نزند.
                if (class_exists('MrDastbaaf_Auth_Controller')) {
                    MrDastbaaf_Auth_Controller::find_user_by_phone_full($phone);
                }

                if (true === $send_result) {
                    set_transient('mrdastbaaf_otp_sent_' . md5($phone . '|' . $code), 1, 5 * MINUTE_IN_SECONDS);
                    $result['results'][] = array('phone' => $mask, 'status' => 'sent');
                } else {
                    $attempts = isset($entry['attempts']) ? (int) $entry['attempts'] + 1 : 1;
                    $result['results'][] = array(
                        'phone'    => $mask,
                        'status'   => 'failed',
                        'attempts' => $attempts,
                    );
                    // تا ۳ تلاش (هر تریگر بعدی = یک تلاش)؛ بعد از آن حذف قطعی
                    if ($attempts < 3 && $age < 600) {
                        $entry['attempts'] = $attempts;
                        $remaining[] = $entry;
                    }
                }
            }

            // v1.7.8: ادغام ایمن — در فاصلهٔ خواندن/نوشتن ما ممکن است درخواست‌های
            // تازه‌ای ورودی به باکس اضافه کرده باشند؛ بازنویسی کورکورانه آن‌ها را
            // گم می‌کند. ورودی‌های تازه (کلید phone|code جدید) به لیست باقی‌مانده
            // اضافه می‌شوند تا در دیسپچ بعدی پردازش شوند.
            $known_keys = array();
            foreach ($remaining as $e) {
                if (is_array($e) && isset($e['phone'], $e['code'])) {
                    $known_keys[] = (string) $e['phone'] . '|' . (string) $e['code'];
                }
            }
            $fresh = get_transient(self::OUTBOX_TRANSIENT);
            if (is_array($fresh)) {
                foreach ($fresh as $e) {
                    if (!is_array($e) || !isset($e['phone'], $e['code'])) {
                        continue;
                    }
                    $key = (string) $e['phone'] . '|' . (string) $e['code'];
                    if (!in_array($key, $known_keys, true)) {
                        $known_keys[] = $key;
                        $remaining[] = $e;
                    }
                }
            }

            if (empty($remaining)) {
                delete_transient(self::OUTBOX_TRANSIENT);
                update_option(self::OUTBOX_PENDING_OPTION, 0, true);
            } else {
                if (count($remaining) > self::OUTBOX_MAX_ENTRIES) {
                    $remaining = array_slice($remaining, -self::OUTBOX_MAX_ENTRIES);
                }
                set_transient(self::OUTBOX_TRANSIENT, $remaining, self::OUTBOX_TTL_SECONDS);
                update_option(self::OUTBOX_PENDING_OPTION, time(), true);
            }
            $result['pending'] = count($remaining);
        } catch (Throwable $e) {
            // هیچ استثنایی نباید فرایند دیسپچ را نیمه‌کاره و قفل‌شده رها کند
            self::write_admin_log('خطا — پردازش باکس خروجی با استثنا متوقف شد: ' . $e->getMessage());
        } finally {
            // آزادی قفل در «هر» مسیر خروج (شامل return زودهنگام داخل try)
            delete_transient(self::DISPATCH_LOCK_TRANSIENT);
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ارسال
    // ─────────────────────────────────────────────────────────────────────

    /**
     * v1.7.8: ارسال مستقیم — مسیر دیسپچ (فرایند جداست).
     * ملی‌پیامک اول؛ هوک ثالث فقط در نبود ملی‌پیامک.
     *
     * @param string $phone شمارهٔ موبایل نرمال‌شده (09xxxxxxxxx)
     * @param string $code  کد ۶ رقمی
     * @return true|string true در موفقیت؛ رشتهٔ فارسی در شکست
     */
    public static function send_direct($phone, $code) {
        if (self::is_ready()) {
            return self::send_melipayamak($phone, $code);
        }

        if (has_action('mrdastbaaf_send_otp_sms')) {
            $timing = array();
            $t_hook = microtime(true);
            do_action('mrdastbaaf_send_otp_sms', $phone, $code);
            $timing[] = 'هوک ثالث: ' . round(microtime(true) - $t_hook, 2) . 's';
            self::write_log(
                'واگذاری به هوک ثالث انجام شد — شماره: ' . substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4)
                . self::timing_suffix($timing) . '.'
            );
            return true;
        }

        self::write_log('ارسال نشد — سرویس پیامک تنظیم نشده است (کد برای ' . substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4) . ' تولید شد).');
        return 'سرویس پیامک هنوز تنظیم نشده است؛ کد تایید شما در سامانه معتبر است اما پیامکی ارسال نشد.';
    }

    /**
     * ارسال کد تایید یک‌بارمصرف (OTP) با الگوی پترن BaseServiceNumber.
     *
     * v1.7.8: این متد برای «سازگاری» حفظ شده (هوک ثالث اول + ملی‌پیامک) و دیگر
     * در هیچ مسیر پاسخ کاربر اجرا نمی‌شود؛ مسیر اصلی send_direct + دیسپچ است.
     *
     * @param string $phone شمارهٔ موبایل نرمال‌شده (09xxxxxxxxx)
     * @param string $code  کد ۶ رقمی
     * @param string $mode 'full' = هوک + سرویس‌دهنده (پیش‌فرض)
     * @return true|string در موفقیت true و در شکست رشتهٔ فارسی مناسب نمایش به کاربر
     */
    public static function send_otp($phone, $code, $mode = 'full') {
        $phone_masked = substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4);
        $provider = get_option('mrdastbaaf_sms_provider', 'none');
        $timing = array();

        // هوک سفارشی — سازگاری با افزونه‌های پیامکی ثالث (فقط در مسیر full)
        if ('full' === $mode) {
            $t_hook = microtime(true);
            do_action('mrdastbaaf_send_otp_sms', $phone, $code);
            $timing[] = 'هوک ثالث: ' . round(microtime(true) - $t_hook, 2) . 's';
        }

        if ('melipayamak' !== $provider) {
            self::write_log('ارسال نشد — سرویس پیامک تنظیم نشده است (کد برای ' . $phone_masked . ' تولید شد)' . self::timing_suffix($timing) . '.');
            return 'سرویس پیامک هنوز تنظیم نشده است؛ کد تایید شما در سامانه معتبر است اما پیامکی ارسال نشد.';
        }

        return self::send_melipayamak($phone, $code, $timing);
    }

    /**
     * v1.7.8: هستهٔ ارسال ملی‌پیامک (استخراج‌شده از send_otp — منطق دست‌نخورده).
     * نتیجهٔ موفق/خطا با زمان‌سنجی در mrdastbaaf_sms_last_log ثبت می‌شود.
     *
     * @param string $phone شمارهٔ موبایل نرمال‌شده (09xxxxxxxxx)
     * @param string $code  کد ۶ رقمی
     * @param array  $timing مراحل قبلی برای پسوند لاگ (مثلاً زمان هوک ثالث)
     * @return true|string true در موفقیت؛ رشتهٔ فارسی در شکست
     */
    private static function send_melipayamak($phone, $code, $timing = array()) {
        $phone_masked = substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4);

        $username = trim((string) get_option('mrdastbaaf_sms_username', ''));
        $password = (string) get_option('mrdastbaaf_sms_password', '');
        $body_id  = trim((string) get_option('mrdastbaaf_sms_body_id', ''));

        if ('' === $username || '' === $password) {
            self::write_log('خطا — نام کاربری یا رمز سرویس ملی‌پیامک تکمیل نشده است (شماره: ' . $phone_masked . ')' . self::timing_suffix($timing) . '.');
            return 'اطلاعات ورود سرویس پیامک تکمیل نشده است؛ کد تایید شما در سامانه معتبر است اما پیامکی ارسال نشد.';
        }

        // الگوی BaseServiceNumber — فقط مقدار کد در متن قرار می‌گیرد؛ پنل ملی‌پیامک
        // آن را داخل الگوی ثبت‌شده (bodyId) جای‌گذاری می‌کند.
        $request_body = array(
            'username' => $username,
            'password' => $password,
            'to'       => (string) $phone,
            'text'     => (string) $code,
        );
        if ('' !== $body_id) {
            $request_body['bodyId'] = $body_id;
        }

        $t_api = microtime(true);
        // v1.7.9: timeout ۱۵→۱۰ — دیسپچ با ۲ ورودی هرگز از ~۲۲ ثانیه فراتر نمی‌رود
        // (آستانهٔ کشته‌شدن فرایند این هاست ~۳۰-۶۰ ثانیه است)
        $response = wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', array(
            'body'    => $request_body,
            'timeout' => 10,
        ));
        $timing[] = 'ملی‌پیامک: ' . round(microtime(true) - $t_api, 2) . 's';

        if (is_wp_error($response)) {
            self::write_log('خطا — ارتباط با ملی‌پیامک برقرار نشد: ' . $response->get_error_message() . ' (شماره: ' . $phone_masked . ')' . self::timing_suffix($timing) . '.');
            return 'ارسال پیامک با خطای شبکه مواجه شد؛ کد تایید شما در سامانه معتبر است.';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            self::write_log('خطا — پاسخ نامعتبر از ملی‌پیامک دریافت شد (شماره: ' . $phone_masked . ')' . self::timing_suffix($timing) . '.');
            return 'پاسخ سرویس پیامک نامعتبر بود؛ کد تایید شما در سامانه معتبر است.';
        }

        // ملی‌پیامک در نسخه‌های مختلف پنل کلیدها را با حروف بزرگ/کوچک متفاوت برمی‌گرداند
        // (RetStatus/retStatus و Value/value) — هر دو حالت بررسی می‌شود.
        $ret_status = null;
        foreach (array('retStatus', 'RetStatus') as $status_key) {
            if (isset($body[$status_key])) {
                $ret_status = (int) $body[$status_key];
                break;
            }
        }
        $value = null;
        foreach (array('Value', 'value') as $value_key) {
            if (isset($body[$value_key])) {
                $value = $body[$value_key];
                break;
            }
        }

        // موفقیت: RetStatus==1 یا وجود Value
        if (1 === $ret_status || (null !== $value && '' !== $value && false !== $value)) {
            $value_label = is_scalar($value) ? (string) $value : '-';
            self::write_log('موفق — کد تایید برای ' . $phone_masked . ' ارسال شد (شناسه پیامک: ' . $value_label . ')' . self::timing_suffix($timing) . '.');
            return true;
        }

        $str_status = isset($body['strRetStatus']) ? (string) $body['strRetStatus'] : '';
        self::write_log('خطا — ملی‌پیامک ارسال را نپذیرفت (RetStatus: ' . var_export($ret_status, true) . ('' !== $str_status ? ' / ' . $str_status : '') . ') (شماره: ' . $phone_masked . ')' . self::timing_suffix($timing) . '.');
        return 'ارسال پیامک ناموفق بود؛ کد تایید شما در سامانه معتبر است. در صورت دریافت نکردن پیامک با پشتیبانی تماس بگیرید.';
    }

    /**
     * v1.7.6: صف‌کردن ارسال OTP در WP-Cron — v1.7.8: منسوخ برای مسیر اصلی
     * (باکس خروجی + دیسپچ جایگزین شد) و فقط برای سازگاری حفظ شده است.
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code  کد ۶ رقمی
     * @return bool true = به صف رفت / false = صف نشد
     */
    public static function schedule_async_send($phone, $code) {
        if (!self::is_ready()) {
            return false;
        }

        $hook = 'mrdastbaaf_otp_async_send';
        $args = array((string) $phone, (string) $code);

        // محکم‌کاری: رویداد قبلیِ همین شماره/کد را پاک کن
        $pending = wp_next_scheduled($hook, $args);
        if ($pending) {
            wp_unschedule_event($pending, $hook, $args);
        }

        $scheduled = wp_schedule_single_event(time() + 10, $hook, $args);
        if (is_wp_error($scheduled)) {
            self::write_log('خطا — زمان‌بندی ارسال پس‌زمینه OTP شکست خورد: ' . $scheduled->get_error_message() . ' (شماره: ' . substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4) . ').');
            return false;
        }

        self::write_log('صف شد — ارسال OTP برای ' . substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4) . ' به WP-Cron واگذار شد.');
        return true;
    }

    /**
     * v1.7.6: اجرای صف — از WP-Cron فراخوانی می‌شود (سازگاری رویدادهای در جریان
     * نسخه‌های قبلی). v1.7.8: ارسال با send_direct (ملی‌پیامک اول).
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code  کد ۶ رقمی
     * @return void
     */
    public static function handle_async_send($phone, $code) {
        $phone = (string) $phone;
        if (get_transient('mrdastbaaf_otp_val_' . md5($phone)) === false) {
            self::write_log('رد شد — کد OTP ' . substr($phone, 0, 4) . '***' . substr($phone, -4) . ' منقضی یا مصرف شده بود؛ ارسال پس‌زمینه لغو شد.');
            return;
        }
        // ضد ارسال تکراری (اگر دیسپچ/تور ایمنی قبلاً فرستاده باشد)
        if (get_transient('mrdastbaaf_otp_sent_' . md5($phone . '|' . (string) $code))) {
            self::write_log('رد شد — این کد قبلاً با موفقیت ارسال شده بود؛ تلاش دوم لغو شد.');
            return;
        }
        self::send_direct($phone, (string) $code);
    }

    /**
     * پسوند زمان‌سنجی برای لاگ پیشخوان — خالی اگر مرحله‌ای ثبت نشده باشد.
     *
     * @param array $timing رشته‌های «نام مرحله: Xs»
     * @return string
     */
    private static function timing_suffix(array $timing) {
        if (empty($timing)) {
            return '';
        }
        return ' {' . implode(' / ', $timing) . '}';
    }

    /**
     * v1.7.8: ثبت پیام تشخیصی در لاگ پیشخوان — برای هشدارهای مسیر پاسخ/تریگر که
     * از کلاس‌های دیگر (کنترلر احراز هویت) ثبت می‌شوند.
     *
     * @param string $message پیام کامل فارسی
     * @return void
     */
    public static function write_admin_log($message) {
        self::write_log($message);
    }

    /**
     * ثبت آخرین وضعیت ارسال پیامک در آپشن (autoload=false) برای نمایش در تنظیمات
     */
    private static function write_log($message) {
        update_option('mrdastbaaf_sms_last_log', '[' . date_i18n('Y/m/d H:i:s') . '] ' . $message, false);
    }
}
