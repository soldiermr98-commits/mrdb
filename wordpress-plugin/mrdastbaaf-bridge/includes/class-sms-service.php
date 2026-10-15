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
 *  فیکس: ارسال پیامک هرگز در درخواست REST اجرا نمی‌شود — با
 *  wp_schedule_single_event به صف WP-Cron می‌رود (spawn_cron برای شروع فوری) و
 *  نتیجه در لاگ پیشخوان با زمان‌سنجی هر مرحله ثبت می‌شود تا ریشهٔ کندی (هوک ثالث /
 *  سرویس‌دهنده / شبکهٔ خروجی هاست) از پیشخوان قابل تشخیص باشد.
 *
 * نکتهٔ امنیتی: رمز سرویس پیامک در پاسخ‌ها/لاگ‌ها هرگز بازتاب داده نمی‌شود و
 * شمارهٔ موبایل در لاگ فقط به شکل ماسک‌شده نوشته می‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Sms_Service {

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
     *
     * پاسخ REST هرگز منتظر سرویس‌دهنده پیامک نمی‌ماند؛ این متد فقط زمان‌بندی می‌کند
     * (چند millisecond) و spawn_cron درخواست loopback غیربلاک‌کننده می‌زند تا ارسال
     * چندثانیه‌ای انجام شود نه دقیقه‌ای. اگر سرویس پیامک اصلاً تنظیم/تکمیل نباشد
     * false برمی‌گرداند تا کنترلر با تلاش همگامِ سبک، هشدار دقیق فارسی را به کلاینت
     * بدهد (آن مسیر هیچ تماس شبکه‌ای ندارد و همیشه سریع است).
     *
     * @param string $phone شمارهٔ نرمال‌شده
     * @param string $code کد ۶ رقمی
     * @return bool true = به صف رفت / false = صف نشد (fallback همگام در کنترلر)
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
