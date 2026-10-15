<?php
/**
 * سرویس ارسال پیامک واقعی (v1.1.0) — ارسال پس‌زمینه از v1.7.6
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
 *  فیکس نهایی: مسیر پاسخ REST از ۱.۷.۷ هیچ عملیات زمان‌بندی/شبکه‌ای/هوکی ندارد؛
 *  ارسال با register_shutdown_function به «بعد از تحویل پاسخ» می‌رود — ابتدا
 *  fastcgi_finish_request (کلاینت پاسخ را کامل می‌گیرد) و بعد هوک ثالث +
 *  ملی‌پیامک. تور ایمنی: اگر ارسال shutdown شکست بخورد، یک رویداد WP-Cron در +۹۰
 *  ثانیه زمان‌بندی می‌شود؛ مارکر «ارسال‌شده» از ارسال تکراری جلوگیری می‌کند.
 *
 * نکتهٔ امنیتی: رمز سرویس پیامک در پاسخ‌ها/لاگ‌ها هرگز بازتاب داده نمی‌شود و
 * شمارهٔ موبایل در لاگ فقط به شکل ماسک‌شده نوشته می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Sms_Service {

    /**
     * v1.7.7: صف ارسال‌های در انتظار shutdown — پر شده در درخواست REST، خالی‌شده
     * بعد از تحویل پاسخ (run_shutdown_sends). هر درخواست PHP یک پروسهٔ مستقل است؛
     * پس static بین درخواست‌ها نشت نمی‌کند.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private static $pending_shutdown_sends = array();

    /** آیا shutdown handler قبلاً ثبت شده است؟ (هر درخواست فقط یک‌بار) */
    private static $shutdown_registered = false;

    /**
     * v1.7.7: آیا سرویس پیامک آمادهٔ ارسال است؟ (فقط خواندن آپشن‌ها — بدون شبکه)
     * در مسیر پاسخ REST استفاده می‌شود تا smsWarning دقیق بدون هیچ تماس سنگین
     * به کلاینت برسد.
     *
     * @return bool
     */
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

    /**
     * v1.7.7: واگذاری ارسال به shutdown — پس از اینکه پاسخ REST به کلاینت تحویل شد.
     *
     * این متد در مسیر پاسخ فقط داده را در صف static می‌گذارد و handler را ثبت
     * می‌کند (هر دو چند millisecond)؛ هیچ شبکه/کرون/هوکی اجرا نمی‌کند.
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code  کد ۶ رقمی
     * @return void
     */
    public static function queue_shutdown_send($phone, $code) {
        self::$pending_shutdown_sends[] = array((string) $phone, (string) $code);
        if (!self::$shutdown_registered) {
            self::$shutdown_registered = true;
            register_shutdown_function(array(__CLASS__, 'run_shutdown_sends'));
        }
    }

    /**
     * v1.7.7: اجرای صف — بعد از پایان اسکریپت (پاسخ REST تحویل شده یا در آستانهٔ
     * تحویل است). اول پاسخ را قطعی تحویل می‌دهیم (finish_request) و بعد هر ارسال
     * را با همان منطق کامل (هوک ثالث + ملی‌پیامک) انجام می‌دهیم؛ اگر ارسال شکست
     * بخورد، تور ایمنی WP-Cron در +۹۰ ثانیه یک تلاش دوم زمان‌بندی می‌شود.
     *
     * @return void
     */
    public static function run_shutdown_sends() {
        if (empty(self::$pending_shutdown_sends)) {
            return;
        }

        // ۱) تحویل قطعی پاسخ به کلاینت — هر خطایی پس از این نقطه به کاربر نمی‌رسد.
        if (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // ۲) ارسال‌های صف‌شده — خارج از چرخهٔ پاسخ، با سقف زمانی خودشان (۱۵s ملی‌پیامک).
        foreach (self::$pending_shutdown_sends as $send) {
            list($phone, $code) = $send;
            try {
                $result = self::send_otp($phone, $code, 'full');
                if (true === $result) {
                    // مارکر ضد ارسال تکراری (تور ایمنی کرون آن را می‌بیند)
                    set_transient('mrdastbaaf_otp_sent_' . md5($phone . '|' . $code), 1, 5 * MINUTE_IN_SECONDS);
                } else {
                    self::schedule_retry_event($phone, $code);
                }
            } catch (Throwable $e) {
                // هیچ خطای پس‌زمینه‌ای نباید به هیچ‌چیز آسیب بزند
            }
        }
        self::$pending_shutdown_sends = array();
    }

    /**
     * v1.7.7: تور ایمنی — زمان‌بندی یک تلاش دوم WP-Cron (فقط وقتی ارسال اول شکست
     * خورد). نوشتن آپشن کرون اینجا انجام می‌شود، یعنی بعد از تحویل پاسخ.
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code  کد ۶ رقمی
     * @return void
     */
    private static function schedule_retry_event($phone, $code) {
        $hook = 'mrdastbaaf_otp_async_send';
        $args = array((string) $phone, (string) $code);
        if (wp_next_scheduled($hook, $args)) {
            return;
        }
        wp_schedule_single_event(time() + 90, $hook, $args);
    }

    /**
     * ارسال کد تایید یک‌بارمصرف (OTP) با الگوی پترن BaseServiceNumber.
     *
     * پارامتر $mode (v1.7.6):
     *  - 'full' (پیش‌فرض): هوک سفارشی + ارسال ملی‌پیامک — مخصوص اجرای پس‌زمینه (WP-Cron)
     *    و fallback همگامِ نادر. هوک «mrdastbaaf_send_otp_sms» تا افزونه‌های ثالث
     *    بدون تغییر کار کنند فراخوانی می‌شود.
     *  - در هر دو حالت نتیجه و زمان‌سنجی مراحل در mrdastbaaf_sms_last_log ثبت می‌شود.
     *
     * @param string $phone شمارهٔ موبایل نرمال‌شده (09xxxxxxxxx)
     * @param string $code کد ۶ رقمی تایید
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

        $username = trim((string) get_option('mrdastbaaf_sms_username', ''));
        $password = (string) get_option('mrdastbaaf_sms_password', '');
        $body_id = trim((string) get_option('mrdastbaaf_sms_body_id', ''));

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
        $response = wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', array(
            'body'    => $request_body,
            'timeout' => 15,
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
     * v1.7.6: صف‌کردن ارسال OTP در WP-Cron + شروع فوری cron.
     * v1.7.7: دیگر در مسیر پاسخ REST فراخوانی نمی‌شود (پروب‌های زنده نشان داد همین
     * نقطه می‌تواند پاسخ را تا ۷۴s نگه دارد)؛ برای سازگاری حفظ شده و فقط از
     * پس‌زمینه/کدهای ثالث قابل استفاده است. مسیر اصلی اکنون queue_shutdown_send است.
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code کد ۶ رقمی
     * @return bool true = به صف رفت / false = صف نشد
     */
    public static function schedule_async_send($phone, $code) {
        // اگر سرویس/اعتبارنامه آماده نیست، صف نکن — کنترلر با ارسال همگامِ سبک
        // هشدار دقیق (تنظیم‌نشده بودن سرویس/اعتبارنامه) را همان‌جا به کلاینت می‌دهد.
        if ('melipayamak' !== get_option('mrdastbaaf_sms_provider', 'none')) {
            return false;
        }
        if ('' === trim((string) get_option('mrdastbaaf_sms_username', '')) || '' === (string) get_option('mrdastbaaf_sms_password', '')) {
            return false;
        }

        $hook = 'mrdastbaaf_otp_async_send';
        $args = array((string) $phone, (string) $code);

        // محکم‌کاری: رویداد قبلیِ همین شماره/کد را پاک کن (قفل ۲ دقیقه‌ای rate-limit
        // عملاً جلوی دوبارکاری را می‌گیرد؛ این فقط اضافه‌ای ایمن است).
        $pending = wp_next_scheduled($hook, $args);
        if ($pending) {
            wp_unschedule_event($pending, $hook, $args);
        }

        $scheduled = wp_schedule_single_event(time() + 10, $hook, $args);
        if (is_wp_error($scheduled)) {
            self::write_log('خطا — زمان‌بندی ارسال پس‌زمینه OTP شکست خورد: ' . $scheduled->get_error_message() . ' (شماره: ' . substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4) . ').');
            return false;
        }

        // شروع فوری WP-Cron — درخواست loopback غیربلاک‌کننده؛ پاسخ REST را عقب نمی‌اندازد.
        spawn_cron();

        self::write_log('صف شد — ارسال OTP برای ' . substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4) . ' به WP-Cron واگذار شد (پاسخ REST بدون انتظار برگشت).');
        return true;
    }

    /**
     * v1.7.6: اجرای صف — از WP-Cron فراخوانی می‌شود (افزوده‌شده در فایل اصلی افزونه).
     *
     * اگر تا زمان اجرا کد منقضی (۱۲۰ ثانیه) یا مصرف شده باشد، ارسال لغو می‌شود تا
     * پیامک بی‌مصرف به کاربر نرود؛ در غیر این صورت ارسال کامل ('full') انجام و
     * نتیجه با زمان‌سنجی ثبت می‌شود.
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code کد ۶ رقمی
     * @return void
     */
    public static function handle_async_send($phone, $code) {
        $phone = (string) $phone;
        if (get_transient('mrdastbaaf_otp_val_' . md5($phone)) === false) {
            self::write_log('رد شد — کد OTP ' . substr($phone, 0, 4) . '***' . substr($phone, -4) . ' منقضی یا مصرف شده بود؛ ارسال پس‌زمینه لغو شد.');
            return;
        }
        // v1.7.7: اگر ارسال shutdown موفق بوده، تلاش دوم لازم نیست (ضد پیامک تکراری)
        if (get_transient('mrdastbaaf_otp_sent_' . md5($phone . '|' . (string) $code))) {
            self::write_log('رد شد — این کد قبلاً با موفقیت ارسال شده بود؛ تلاش دوم تور ایمنی لغو شد.');
            return;
        }
        self::send_otp($phone, (string) $code, 'full');
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
     * ثبت آخرین وضعیت ارسال پیامک در آپشن (autoload=false) برای نمایش در تنظیمات
     */
    private static function write_log($message) {
        update_option('mrdastbaaf_sms_last_log', '[' . date_i18n('Y/m/d H:i:s') . '] ' . $message, false);
    }
}
