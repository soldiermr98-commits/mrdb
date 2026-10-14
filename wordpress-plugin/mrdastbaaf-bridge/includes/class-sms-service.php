<?php
/**
 * سرویس ارسال پیامک واقعی (v1.1.0)
 *
 * تا نسخهٔ ۱.۰.۳ ارسال OTP فقط به هوک «mrdastbaaf_send_otp_sms» واگذار می‌شد و
 * در نصب‌های بدون افزونهٔ ثالث، هیچ پیامکی واقعاً ارسال نمی‌شد. این کلاس ارسال
 * مستقیم از طریق الگوی BaseServiceNumber ملی‌پیامک را پیاده‌سازی می‌کند و نتیجهٔ
 * آخرین ارسال (موفق/خطا + پیام فارسی) را در آپشن mrdastbaaf_sms_last_log ثبت
 * می‌کند تا مدیر در صفحهٔ تنظیمات ببیند.
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
     * رفتار v1.1.0:
     *  - provider=none: فقط هوک سفارشی فراخوانی و نتیجه لاگ می‌شود (ارسال واقعی نیست).
     *  - provider=melipayamak: درخواست سرور-به-سرور به rest.payamak-panel.com با
     *    timeout ۱۵ ثانیه؛ متن پیامک فقط «کد» است (الگو در پنل جای‌گذاری می‌کند).
     *  - هوک سفارشی «mrdastbaaf_send_otp_sms» در هر حالت فراخوانی می‌شود تا
     *    افزونه‌های ثالث بدون تغییر کار کنند.
     *
     * @param string $phone شمارهٔ موبایل نرمال‌شده (09xxxxxxxxx)
     * @param string $code کد ۵ رقمی تایید
     * @return true|string در موفقیت true و در شکست رشتهٔ فارسی مناسب نمایش به کاربر
     */
    public static function send_otp($phone, $code) {
        // هوک سفارشی — سازگاری با افزونه‌های پیامکی ثالث (رفتار قبلی حفظ شده است)
        do_action('mrdastbaaf_send_otp_sms', $phone, $code);

        $phone_masked = substr((string) $phone, 0, 4) . '***' . substr((string) $phone, -4);
        $provider = get_option('mrdastbaaf_sms_provider', 'none');

        if ('melipayamak' !== $provider) {
            self::write_log('ارسال نشد — سرویس پیامک تنظیم نشده است (کد برای ' . $phone_masked . ' تولید شد).');
            return 'سرویس پیامک هنوز تنظیم نشده است؛ کد تایید شما در سامانه معتبر است اما پیامکی ارسال نشد.';
        }

        $username = trim((string) get_option('mrdastbaaf_sms_username', ''));
        $password = (string) get_option('mrdastbaaf_sms_password', '');
        $body_id = trim((string) get_option('mrdastbaaf_sms_body_id', ''));

        if ('' === $username || '' === $password) {
            self::write_log('خطا — نام کاربری یا رمز سرویس ملی‌پیامک تکمیل نشده است (شماره: ' . $phone_masked . ').');
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

        $response = wp_remote_post('https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber', array(
            'body'    => $request_body,
            'timeout' => 15,
        ));

        if (is_wp_error($response)) {
            self::write_log('خطا — ارتباط با ملی‌پیامک برقرار نشد: ' . $response->get_error_message() . ' (شماره: ' . $phone_masked . ')');
            return 'ارسال پیامک با خطای شبکه مواجه شد؛ کد تایید شما در سامانه معتبر است.';
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            self::write_log('خطا — پاسخ نامعتبر از ملی‌پیامک دریافت شد (شماره: ' . $phone_masked . ').');
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
            self::write_log('موفق — کد تایید برای ' . $phone_masked . ' ارسال شد (شناسه پیامک: ' . $value_label . ').');
            return true;
        }

        $str_status = isset($body['strRetStatus']) ? (string) $body['strRetStatus'] : '';
        self::write_log('خطا — ملی‌پیامک ارسال را نپذیرفت (RetStatus: ' . var_export($ret_status, true) . ('' !== $str_status ? ' / ' . $str_status : '') . ') (شماره: ' . $phone_masked . ').');
        return 'ارسال پیامک ناموفق بود؛ کد تایید شما در سامانه معتبر است. در صورت دریافت نکردن پیامک با پشتیبانی تماس بگیرید.';
    }

    /**
     * ثبت آخرین وضعیت ارسال پیامک در آپشن (autoload=false) برای نمایش در تنظیمات
     */
    private static function write_log($message) {
        update_option('mrdastbaaf_sms_last_log', '[' . date_i18n('Y/m/d H:i:s') . '] ' . $message, false);
    }
}
