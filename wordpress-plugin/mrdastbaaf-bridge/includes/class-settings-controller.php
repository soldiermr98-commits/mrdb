<?php
/**
 * کنترلر صفحهٔ تنظیمات ادمین مستر دستباف (v1.1.0 — فایل NEW)
 *
 * منوی پیشخوان «مستر دستباف» با دو زیرصفحه:
 *  ۱) تنظیمات — فرم Settings API برای مرچنت زیبال، آدرس فرانت PWA، دستهٔ دوره‌ها
 *     و اطلاعات سرویس پیامک (ملی‌پیامک). همهٔ ورودی‌ها sanitize و رمز پیامک هرگز
 *     در خروجی فرم بازتاب داده نمی‌شود (فقط placeholder «ذخیره شده»).
 *  ۲) درس‌ها و ویدیوها — مدیریت درس‌های هر محصول ووکامرس (افزودن/ویرایش/حذف) با
 *     فراخوانی fetch به اندپوینت‌های REST ادمین (/mrdastbaaf/v1/admin/lessons*)
 *     همراه X-WP-Nonce.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Settings_Controller {

    const MENU_SLUG = 'mrdastbaaf-bridge';
    const LESSONS_SLUG = 'mrdastbaaf-lessons';
    const SETTINGS_GROUP = 'mrdastbaaf_settings_group';

    public function register() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    /**
     * ثبت منوی پیشخوان: «مستر دستباف» + دو زیرصفحه (تنظیمات / درس‌ها و ویدیوها)
     */
    public function register_admin_menu() {
        // صفحهٔ اصلی منو = تنظیمات (اسلاگ مشترک با زیرصفحهٔ اول)
        add_menu_page(
            'مستر دستباف',
            'مستر دستباف',
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_settings_page'),
            'dashicons-awards',
            58
        );

        add_submenu_page(
            self::MENU_SLUG,
            'تنظیمات مستر دستباف',
            'تنظیمات',
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            self::MENU_SLUG,
            'درس‌ها و ویدیوها',
            'درس‌ها و ویدیوها',
            'manage_options',
            self::LESSONS_SLUG,
            array($this, 'render_lessons_page')
        );
    }

    /**
     * ثبت گزینه‌ها با Settings API — هر ورودی با sanitize اختصاصی
     */
    public function register_settings() {
        // مرچنت زیبال — خالی = سندباکس
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_zibal_merchant', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_plain_text'),
            'default' => '',
        ));

        // آدرس فرانت PWA — پایهٔ ساخت callback هش‌محور درگاه زیبال
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_frontend_url', array(
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ));

        // دستهٔ دوره‌ها (slug ترم product_cat ووکامرس)
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_course_category', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_category_slug'),
            'default' => 'courses',
        ));

        // سرویس پیامک — none | melipayamak
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_sms_provider', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_sms_provider'),
            'default' => 'none',
        ));

        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_sms_username', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_plain_text'),
            'default' => '',
        ));

        // رمز سرویس پیامک — فقط اگر ورودی غیرخالی بود ذخیره می‌شود (پاک‌سازی تصادفی ممنوع)
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_sms_password', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_sms_password'),
            'default' => '',
        ));

        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_sms_body_id', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_plain_text'),
            'default' => '',
        ));

        // v1.2.0: هدر اعتمادشدهٔ پروکسی برای IP واقعی OTP — فقط الگوی نام هدر HTTP
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_trusted_proxy_header', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_proxy_header'),
            'default' => '',
        ));

        // v1.2.0: لیست سفید هاست‌های ویدیو — textarea (یکی در هر خط)
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_video_hosts', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_video_hosts'),
            'default' => 'dll.mrdastbaaf.com',
        ));

        // v1.2.0: توکن اندپوینت کرون — فقط نمایش در فرم؛ هیچ فیلد ورودی‌ای ثبت‌شده
        // نمی‌شود (تولید خودکار در مسیر ارتقا/فعال‌سازی؛ بازنویسی دستی از فرم ممنوع
        // تا با غلط تایپی توکن بی‌اعتبار نشود).
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_cron_token', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_cron_token_readonly'),
            'default' => '',
            'show_in_rest' => false,
        ));

        // v1.7.0: حالت استریم پروکسی — URL واقعی ویدیو هرگز به کلاینت نمی‌رسد.
        // فعال‌سازی: PHP ویدیو را از CDN می‌خواند و byte-by-byte به کلاینت stream می‌کند.
        // هشدار: بار سرور بالا — فقط برای ویدیوهای حساس فعال کنید.
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_stream_proxy_mode', array(
            'type' => 'boolean',
            'sanitize_callback' => function ($v) { return (bool) $v; },
            'default' => false,
            'show_in_rest' => false,
        ));

        // v1.7.4: «نسخهٔ مستقر PWA» — قلب مکانیزم آپدیت خودکار کلاینت‌ها.
        // پس از استقرار باندل جدید روی app.mrdastbaaf.com، همین عدد باید به
        // نسخهٔ جدید تغییر کند؛ کلاینت‌های 1.5.4+ (چکر نسخه) حداکثر تا ۲ دقیقه
        // بعد خودکار reload می‌شوند — حتی اپ‌های نصب‌شده (standalone).
        // v1.7.14: ذخیرهٔ این فیلد دیگر content_hash را نمی‌بُرد (مستثنی‌های bump)؛
        // آپدیت کلاینت فقط از فیلد pwa_version در /version می‌آید و تغییر باندل
        // «محتوای اپ» نیست — ری‌فچ بی‌دلیل داده‌ها نمی‌سازد.
        register_setting(self::SETTINGS_GROUP, 'mrdastbaaf_pwa_version', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_pwa_version'),
            'default' => MRDASTBAAF_PWA_VERSION_DEFAULT,
            'show_in_rest' => false,
        ));
    }

    /**
     * v1.2.0: نام هدر اعتمادشدهٔ پروکسی — فقط حروف لاتین/عدد/خط تیره (^[A-Za-z0-9-]+$)
     * تا مقدار واردشده نتواند کلید دلخواه $_SERVER تزریق کند؛ خالی = REMOTE_ADDR مستقیم.
     */
    public function sanitize_proxy_header($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9-]+$/', $value)) {
            return (string) get_option('mrdastbaaf_trusted_proxy_header', ''); // ورودی نامعتبر = حفظ مقدار قبلی
        }
        return $value;
    }

    /**
     * v1.2.0: هاست‌های ویدیو — هر خط با sanitize_text_field پاک و به حروف کوچک
     * تبدیل و به شکل رشتهٔ چندخطی ذخیره می‌شود (خروجی: رشته، نه آرایه).
     */
    public function sanitize_video_hosts($value) {
        $lines = explode("\n", (string) $value);
        $clean = array();
        foreach ($lines as $line) {
            $host = strtolower(sanitize_text_field($line));
            if ('' !== $host) {
                $clean[] = $host;
            }
        }
        if (empty($clean)) {
            return (string) get_option('mrdastbaaf_video_hosts', 'dll.mrdastbaaf.com'); // خالی = حفظ پیش‌فرض
        }
        return implode("\n", $clean);
    }

    /**
     * v1.2.0: توکن کرون فقط-نمایش — فرم این فیلد را ارسال نمی‌کند؛ در صورت ارسال
     * تصادفی، مقدار فعلی حفظ می‌شود (تولید/چرخش فقط از کد).
     */
    public function sanitize_cron_token_readonly($value) {
        return (string) get_option('mrdastbaaf_cron_token', '');
    }

    public function sanitize_plain_text($value) {
        return sanitize_text_field((string) $value);
    }

    /**
     * slug دستهٔ ووکامرس — حروف لاتین/عدد/خط تیره/زیرخط و فارسی مجاز است
     * (sanitize_key به دلیل حذف حروف فارسی استفاده نمی‌شود)
     */
    public function sanitize_category_slug($value) {
        $slug = sanitize_text_field((string) $value);
        return preg_replace('/[^A-Za-z0-9_\-\x{0600}-\x{06FF}]/u', '', $slug);
    }

    public function sanitize_sms_provider($value) {
        $provider = sanitize_key((string) $value);
        $allowed = array('none', 'melipayamak');
        return in_array($provider, $allowed, true) ? $provider : 'none';
    }

    /**
     * رمز پیامک — فقط وقتی ورودی غیرخالی است ذخیره می‌شود؛ ورودی خالی یعنی
     * «بدون تغییر» تا رمز ذخیره‌شده با ارسال فرم خالی از بین نرود.
     */
    /**
     * v1.7.4: sanitize نسخهٔ مستقر PWA — فقط فرمت x.y.z عددی معتبر پذیرفته
     * می‌شود؛ ورودی نامعتبر → مقدار فعلی حفظ می‌شود (هیچ‌وقت گزینه با مقدار
     * خراب overwrite نمی‌شود) و /version همچنان مقدار معتبر برمی‌گرداند.
     */
    public function sanitize_pwa_version($value) {
        $value = trim((string) $value);
        if (preg_match('/^\d+(\.\d+)*$/', $value)) {
            return $value;
        }
        // نامعتبر → حفظ مقدار فعلی (یا پیش‌فرض اگر هنوز seed نشده)
        $current = trim((string) get_option('mrdastbaaf_pwa_version', ''));
        return ('' !== $current) ? $current : MRDASTBAAF_PWA_VERSION_DEFAULT;
    }

    public function sanitize_sms_password($value) {
        $value = trim((string) $value);
        if ('' === $value) {
            return (string) get_option('mrdastbaaf_sms_password', '');
        }
        return $value;
    }

    /**
     * صفحهٔ «تنظیمات»
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $merchant = get_option('mrdastbaaf_zibal_merchant', '');
        $frontend_url = get_option('mrdastbaaf_frontend_url', '');
        $category = get_option('mrdastbaaf_course_category', 'courses');
        $provider = get_option('mrdastbaaf_sms_provider', 'none');
        $sms_username = get_option('mrdastbaaf_sms_username', '');
        $sms_password_set = '' !== (string) get_option('mrdastbaaf_sms_password', '');
        $sms_body_id = get_option('mrdastbaaf_sms_body_id', '');
        $sms_last_log = get_option('mrdastbaaf_sms_last_log', '');

        // v1.2.0: تنظیمات جدید — هدر پروکسی، هاست‌های ویدیو و توکن کرون
        $proxy_header = (string) get_option('mrdastbaaf_trusted_proxy_header', '');
        $video_hosts = (string) get_option('mrdastbaaf_video_hosts', 'dll.mrdastbaaf.com');
        $cron_token = (string) get_option('mrdastbaaf_cron_token', '');
        $cron_example_url = rest_url(MRDASTBAAF_API_NAMESPACE . '/cron/reconcile') . '?token=' . ($cron_token !== '' ? $cron_token : 'TOKEN');

        // v1.7.0: حالت استریم پروکسی — URL واقعی ویدیو پنهان می‌شود
        $stream_proxy_mode = (bool) get_option('mrdastbaaf_stream_proxy_mode', false);
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">⚙️</div>
                <div>
                    <h1 class="mrd-hero__title">تنظیمات مستر دستباف</h1>
                    <p class="mrd-hero__desc">اتصال افزونهٔ پل ارتباطی به درگاه زیبال، فرانت PWA، دستهٔ دوره‌ها و سرویس پیامک — همهٔ ورودی‌ها sanitize و رمزها هرگز در فرم بازتاب داده نمی‌شوند.</p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
                <div class="mrd-card">
                    <h2 class="mrd-card__title">پرداخت و دوره‌ها</h2>
                    <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-zibal-merchant">مرچنت زیبال</label></th>
                        <td>
                            <input type="text" id="mrd-zibal-merchant" name="mrdastbaaf_zibal_merchant" value="<?php echo esc_attr($merchant); ?>" class="regular-text code" dir="ltr" autocomplete="off" />
                            <p class="description">شناسهٔ مرچنت درگاه زیبال — <strong>خالی = سندباکس</strong> (مقدار «zibal» برای تست).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-frontend-url">آدرس فرانت PWA</label></th>
                        <td>
                            <input type="url" id="mrd-frontend-url" name="mrdastbaaf_frontend_url" value="<?php echo esc_attr($frontend_url); ?>" class="regular-text code" dir="ltr" placeholder="https://app.mrdastbaaf.com" />
                            <p class="description">پایهٔ ساخت آدرس کال‌بک پرداخت (فرمت هش‌محور: <code>…/#/payment/callback?orderId=…&amp;cb_token=…</code>). خالی = آدرس خود سایت.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-course-category">دستهٔ دوره‌ها</label></th>
                        <td>
                            <input type="text" id="mrd-course-category" name="mrdastbaaf_course_category" value="<?php echo esc_attr($category); ?>" class="regular-text code" dir="ltr" />
                            <p class="description">slug دستهٔ ووکامرس که دوره‌ها در آن قرار می‌گیرند (اندپوینت <code>GET /courses</code> همین دسته را می‌خواند). پیش‌فرض: <code>courses</code></p>
                        </td>
                    </tr>
                    </table>
                </div>

                <div class="mrd-card">
                    <h2 class="mrd-card__title">سرویس پیامک (کد تایید ورود)</h2>
                    <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-sms-provider">سرویس‌دهنده</label></th>
                        <td>
                            <select id="mrd-sms-provider" name="mrdastbaaf_sms_provider">
                                <option value="none" <?php selected($provider, 'none'); ?>>بدون ارسال واقعی (فقط هوک سفارشی)</option>
                                <option value="melipayamak" <?php selected($provider, 'melipayamak'); ?>>ملی‌پیامک (الگوی BaseServiceNumber)</option>
                            </select>
                            <p class="description">در حالت «بدون ارسال واقعی»، کد تایید در سامانه معتبر است اما پیامکی ارسال نمی‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-sms-username">نام کاربری ملی‌پیامک</label></th>
                        <td>
                            <input type="text" id="mrd-sms-username" name="mrdastbaaf_sms_username" value="<?php echo esc_attr($sms_username); ?>" class="regular-text code" dir="ltr" autocomplete="off" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-sms-password">رمز عبور ملی‌پیامک</label></th>
                        <td>
                            <input type="password" id="mrd-sms-password" name="mrdastbaaf_sms_password" class="regular-text code" dir="ltr" autocomplete="new-password"
                                value="" <?php echo $sms_password_set ? 'placeholder="ذخیره شده"' : 'placeholder="رمز را وارد کنید"'; ?> />
                            <p class="description">به دلایل امنیتی رمز ذخیره‌شده نمایش داده نمی‌شود؛ اگر خالی بگذارید رمز فعلی حفظ می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-sms-body-id">کد پترن (bodyId)</label></th>
                        <td>
                            <input type="text" id="mrd-sms-body-id" name="mrdastbaaf_sms_body_id" value="<?php echo esc_attr($sms_body_id); ?>" class="regular-text code" dir="ltr" autocomplete="off" />
                            <p class="description">شناسهٔ الگوی BaseServiceNumber در پنل ملی‌پیامک؛ فقط مقدار کد تایید داخل الگو جای‌گذاری می‌شود.</p>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="mrd-card">
                    <h2 class="mrd-card__title">امنیت و زیرساخت</h2>
                    <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-trusted-proxy-header">هدر اعتمادشدهٔ پروکسی</label></th>
                        <td>
                            <input type="text" id="mrd-trusted-proxy-header" name="mrdastbaaf_trusted_proxy_header" value="<?php echo esc_attr($proxy_header); ?>" class="regular-text code" dir="ltr" placeholder="مثال: X-Forwarded-For (خالی = مستقیم)" autocomplete="off" />
                            <p class="description">پشت reverse-proxy (مثل کلودفلر/انجین‌اکس)، همهٔ درخواست‌ها یک IP مشترک دارند و سقف anti-spam پیامک بی‌اثر می‌شود. نام هدری که IP واقعی کلاینت را حمل می‌کند وارد کنید (فقط اولین IP فهرست خوانده می‌شود). <strong>خالی = مستقیم (REMOTE_ADDR)</strong>. این هدر قابل جعل است و فقط برای rate-limit استفاده می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-video-hosts">هاست‌های مجاز ویدیو</label></th>
                        <td>
                            <textarea id="mrd-video-hosts" name="mrdastbaaf_video_hosts" class="large-text code" rows="3" dir="ltr"><?php echo esc_textarea($video_hosts); ?></textarea>
                            <p class="description">هر هاست در یک خط؛ آدرس ویدیوی درس‌ها فقط با این هاست‌ها پذیرفته می‌شود (به‌علاوهٔ https). پیش‌فرض: <code>dll.mrdastbaaf.com</code></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-cron-token">توکن کرون ریکنسیلیشن</label></th>
                        <td>
                            <input type="text" id="mrd-cron-token" value="<?php echo esc_attr($cron_token); ?>" class="regular-text code" dir="ltr" readonly onclick="this.select()" />
                            <p class="description">توکن اختصاصی اندپوینت <code>GET /cron/reconcile?token=…</code> — خودکار تولید شده و فقط-خواندنی است (هیچ‌گاه در پاسخ‌های عمومی نمی‌آید). برای اجرای مطمئن کرون سرور (به‌جای WP-Cron تزویری)، این دستور را در crontab سرور بگذارید:</p>
                            <code dir="ltr"><?php echo esc_html('*/10 * * * * curl -s "' . $cron_example_url . '"'); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="mrd-stream-proxy">حالت استریم امن (پروکسی)</label>
                            <span class="mrd-badge mrd-badge--new">v1.7.0</span>
                        </th>
                        <td>
                            <label class="mrd-switch">
                                <input type="checkbox" id="mrd-stream-proxy" name="mrdastbaaf_stream_proxy_mode" value="1" <?php checked($stream_proxy_mode, true); ?> />
                                <span class="mrd-switch__slider"></span>
                            </label>
                            <p class="description">
                                <strong>وقتی فعال باشد:</strong> URL واقعی ویدیو (مثل <code dir="ltr">https://dll.mrdastbaaf.com/...</code>) هرگز در DevTools مرورگر دیده نمی‌شود. به جای آن، <code dir="ltr">/wp-json/.../stream?token=...</code> در <code dir="ltr">&lt;video src&gt;</code> قرار می‌گیرد. توکن ۶۰ ثانیه‌ای است و حتی اگه کسی آن را کپی کند، بعد از ۱ دقیقه بی‌اثر می‌شود.
                                <br><strong>هشدار:</strong> بار سرور بالا — PHP باید هر بایت ویدیو را از CDN بخواند و به کلاینت بفرستد. برای ویدیوهای طولانی یا کاربران هم‌زمان زیاد، ممکن است هاست اشتراکی کُند شود. در صورت مشکل، غیرفعال کنید.
                                <br><strong>پیش‌فرض:</strong> غیرفعال (URL مستقیم CDN — سریع‌تر ولی قابل دیدن در DevTools).
                            </p>
                        </td>
                    </tr>
                </table>
                </div>

                <?php // v1.7.4: کارت مدیریت نسخه و آپدیت خودکار PWA ?>
                <div class="mrd-card">
                    <h2 class="mrd-card__title">نسخه و به‌روزرسانی PWA</h2>
                    <p class="description" style="margin-top:0;">
                        مکانیزم آپدیت خودکار: کلاینت‌های نسخهٔ 1.5.4 به بالا هر ۲ دقیقه اندپوینت <code>GET /version</code> را چک می‌کنند (حتی اپ‌های نصب‌شده روی گوشی). اگر عدد زیر <strong>بزرگ‌تر</strong> از نسخهٔ باندل آن‌ها باشد، خودکار بارگذاری مجدد می‌شوند — بدون هیچ کاری از سمت کاربر.
                    </p>
                    <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="mrd-pwa-version">نسخهٔ مستقر PWA</label>
                            <span class="mrd-badge mrd-badge--new">v1.7.4</span>
                        </th>
                        <td>
                            <input type="text" id="mrd-pwa-version" name="mrdastbaaf_pwa_version" value="<?php echo esc_attr((string) get_option('mrdastbaaf_pwa_version', MRDASTBAAF_PWA_VERSION_DEFAULT)); ?>" class="regular-text code" dir="ltr" placeholder="1.5.5" autocomplete="off" />
                            <p class="description">
                                <strong>چک‌لیست انتشار نسخهٔ جدید:</strong>
                                ۱) باندل جدید را روی <code dir="ltr">app.mrdastbaaf.com</code> مستقر کنید (ZIP مرکز دانلود)
                                ۲) عدد همین فیلد را به نسخهٔ باندل جدید تغییر دهید (مثلاً <code dir="ltr">1.5.5</code>)
                                ۳) ذخیره کنید — همین ذخیره، هش محتوا را هم تازه می‌کند و قیمت‌ها/محتوای همهٔ کلاینت‌ها refetch می‌شود.
                                کاربران می‌توانند نسخهٔ باندل خودشان را در <code dir="ltr">app.mrdastbaaf.com/#/debug</code> ببینند.
                                <em>فرمت مجاز: فقط عدد و نقطه (مثل 1.5.5) — مقدار نامعتبر نادیده گرفته می‌شود.</em>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">وضعیت فعلی</th>
                        <td>
                            <p class="description" style="margin:0;">
                                نسخهٔ اعلام‌شده به کلاینت‌ها: <code dir="ltr"><?php echo esc_html(mrdastbaaf_get_deployed_pwa_version()); ?></code>
                                &nbsp;·&nbsp; هش محتوا: <code dir="ltr"><?php echo esc_html((string) get_option('mrdastbaaf_content_hash', '—')); ?></code>
                                <br>نکته: فقط وقتی نسخهٔ سرور «جدیدتر» از باندل کلاینت باشد reload رخ می‌دهد؛ عدد کوچک‌تر عمداً نادیده گرفته می‌شود (ضد حلقهٔ reload — فیکس v1.5.5).
                                کاربرانی که هنوز روی باندل ≤1.5.3 چسبیده‌اند، یک‌بار صفحهٔ <code dir="ltr">#/debug</code> را باز کنند و «پاک‌سازی کامل و بارگذاری مجدد» را بزنند؛ از آن به بعد همهٔ آپدها خودکار است.
                            </p>
                        </td>
                    </tr>
                    </table>
                </div>

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>

            <?php if ('' !== (string) $sms_last_log): ?>
                <div class="mrd-card">
                    <h2 class="mrd-card__title">آخرین وضعیت ارسال پیامک</h2>
                    <p><code><?php echo esc_html((string) $sms_last_log); ?></code></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * صفحهٔ «درس‌ها و ویدیوها» — مدیریت درس‌های محصولات ووکامرس
     *
     * v1.3.0: فهرست دوره‌ها به‌صورت خودکار از دستهٔ ووکامرسی تنظیم‌شده
     * (mrdastbaaf_course_category، پیش‌فرض courses) خوانده می‌شود — نام و لینک
     * دوره فقط از محصول ووکامرس می‌آید و ادمین فقط فیلدهای «جلسه» را پر می‌کند.
     *
     * عملیات از طریق fetch به REST ادمین با X-WP-Nonce انجام می‌شود:
     *   GET    /mrdastbaaf/v1/admin/lessons?product_id=
     *   POST   /mrdastbaaf/v1/admin/lessons
     *   PUT    /mrdastbaaf/v1/admin/lessons/{id}
     *   DELETE /mrdastbaaf/v1/admin/lessons/{id}
     */
    public function render_lessons_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $woo_active = class_exists('WooCommerce');
        $products = array();
        $category_slug = (string) get_option('mrdastbaaf_course_category', 'courses');

        if ($woo_active) {
            // v1.3.0: فقط محصولات همین دسته — دقیقاً همان منطق REST عمومی
            // (GET /courses) که اپ Real لیست می‌کند؛ یعنی چیزی که ادمین اینجا
            // می‌بیند همان چیزی است که مخاطب در اپ می‌بیند.
            $query_args = array(
                'post_type'           => 'product',
                'post_status'         => array('publish', 'draft', 'private'),
                'posts_per_page'      => 200,
                'no_found_rows'       => true,
                'ignore_sticky_posts' => true,
                'orderby'             => 'title',
                'order'               => 'ASC',
            );
            if ('' !== $category_slug) {
                $query_args['tax_query'] = array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'slug',
                        'terms'    => $category_slug,
                    ),
                );
            }
            $query = new WP_Query($query_args);

            foreach ($query->posts as $product_post) {
                // وضعیت غیرمنتشرشده برچسب می‌خورد — این محصول در اپ (فقط publish) دیده نمی‌شود
                $status_suffix = ('publish' === $product_post->post_status)
                    ? ''
                    : ' — منتشرنشده';
                $products[] = array(
                    'id'     => (int) $product_post->ID,
                    'name'   => $product_post->post_title . $status_suffix,
                    'url'    => (string) get_permalink($product_post->ID),
                    'status' => (string) $product_post->post_status,
                );
            }
        }

        $boot = array(
            'restRoot'  => esc_url_raw(rest_url(MRDASTBAAF_API_NAMESPACE)),
            'nonce'     => wp_create_nonce('wp_rest'),
            'products'  => $products,
            'categorySlug' => $category_slug,
        );
        ?>
        <div class="wrap mrd-scope">
            <?php if (!$woo_active): ?>
                <div class="mrd-hero">
                    <div class="mrd-hero__icon" aria-hidden="true">🎬</div>
                    <div>
                        <h1 class="mrd-hero__title">درس‌ها و ویدیوها</h1>
                        <p class="mrd-hero__desc">ووکامرس فعال نیست؛ برای مدیریت درس‌ها ابتدا افزونهٔ ووکامرس را فعال کنید.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="mrd-hero">
                    <div class="mrd-hero__icon" aria-hidden="true">🎬</div>
                    <div>
                        <h1 class="mrd-hero__title">درس‌ها و ویدیوها</h1>
                        <p class="mrd-hero__desc">دوره‌ها به‌صورت خودکار از دستهٔ ووکامرسی <code dir="ltr"><?php echo esc_html($category_slug); ?></code> خوانده می‌شوند؛ نام، لینک و تصویر هر دوره را در ووکامرس مدیریت کنید و اینجا فقط «جلسه‌ها» را اضافه کنید. درس‌های «پیش‌نمایش رایگان» برای همهٔ بازدیدکنندگان قابل تماشا هستند.</p>
                    </div>
                </div>

                <div class="mrd-chips">
                    <span class="mrd-chip">دوره‌های این دسته: <?php echo esc_html(number_format_i18n(count($products))); ?></span>
                    <span class="mrd-chip mrd-chip--amber">فقط جلسات را اینجا ثبت کنید — اسم و لینک دوره از ووکامرس می‌آید</span>
                </div>

                <?php if (empty($products)): ?>
                    <div class="mrd-note mrd-note--warn">
                        هنوز محصولی در دستهٔ <strong><?php echo esc_html($category_slug); ?></strong> نیست — به همین دلیل لیست اینجا و اپلیکیشن خالی است.<br />
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>">مدیریت محصولات ووکامرس</a> یا
                        <a href="<?php echo esc_url(admin_url('edit-tags.php?taxonomy=product_cat&post_type=product')); ?>">بررسی دستهٔ <?php echo esc_html($category_slug); ?></a>
                        — پس از اینکه محصول را به این دسته اختصاص دادید، همین صفحه را نوسازی کنید.
                    </div>
                <?php endif; ?>

                <div class="mrd-card">
                    <h2 class="mrd-card__title">۱) انتخاب دوره</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mrd-lesson-product">دوره (محصول ووکامرس)</label></th>
                            <td>
                                <select id="mrd-lesson-product">
                                    <option value="">— انتخاب دوره —</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?php echo esc_attr((string) $product['id']); ?>" data-url="<?php echo esc_attr($product['url']); ?>"><?php echo esc_html($product['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div id="mrd-course-meta" class="mrd-course-meta" aria-live="polite"></div>
                                <p class="mrd-hint">انتخاب دوره = همان صفحه‌ای که مخاطب در اپ می‌بیند؛ نام دوره، توضیح، تصویر و قیمت از همان محصول ووکامرس خوانده می‌شود.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="mrd-card">
                    <h2 class="mrd-card__title">۲) فهرست جلسات دوره</h2>
                    <div class="mrd-table-wrap">
                        <table class="widefat striped" id="mrd-lessons-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>عنوان</th>
                                    <th>آدرس ویدیو</th>
                                    <th>مدت</th>
                                    <th>پیش‌نمایش رایگان</th>
                                    <th>سطح اشتراک لازم</th>
                                    <th>ترتیب</th>
                                    <th>عملیات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="8" class="mrd-empty">ابتدا یک دوره را انتخاب کنید.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="mrd-card">
                    <h2 class="mrd-card__title" id="mrd-lesson-form-title">۳) افزودن جلسهٔ جدید</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mrd-lesson-title">عنوان جلسه</label></th>
                            <td><input type="text" id="mrd-lesson-title" class="regular-text" maxlength="191" placeholder="مثلاً جلسهٔ اول: معرفی ابزار بافندگی" /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-video">آدرس ویدیو (https)</label></th>
                            <td>
                                <input type="url" id="mrd-lesson-video" class="large-text code" dir="ltr" placeholder="https://dll.mrdastbaaf.com/..." />
                                <p class="description">فقط نشانی‌های <code>https://</code> پذیرفته می‌شود.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-duration">مدت (دقیقه)</label></th>
                            <td>
                                <input type="number" id="mrd-lesson-duration" class="small-text" min="0" max="1440" step="1" value="0" />
                                <p class="description">مدت هر جلسه به دقیقه — در اپ جمعِ مدت‌ها به‌عنوان مدت دوره و مدت هر جلسه نمایش داده می‌شود؛ صفر یعنی بدون مدت.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-q480">کیفیت 480p (URL)</label></th>
                            <td>
                                <input type="url" id="mrd-lesson-q480" class="large-text code" dir="ltr" placeholder="https://dll.mrdastbaaf.com/..." />
                                <p class="description">هر سه کیفیت اختیاری‌اند — هر نشانی پرشده باید https و از هاست مجاز باشد؛ خالی یعنی آن کیفیت موجود نیست.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-q720">کیفیت 720p (URL)</label></th>
                            <td><input type="url" id="mrd-lesson-q720" class="large-text code" dir="ltr" placeholder="https://dll.mrdastbaaf.com/..." /></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-q1080">کیفیت 1080p (URL)</label></th>
                            <td><input type="url" id="mrd-lesson-q1080" class="large-text code" dir="ltr" placeholder="https://dll.mrdastbaaf.com/..." /></td>
                        </tr>
                        <tr>
                            <th scope="row">وضعیت دسترسی</th>
                            <td>
                                <label class="mrd-toggle" for="mrd-lesson-free">
                                    <input type="checkbox" id="mrd-lesson-free" value="1" />
                                    <span>پیش‌نمایش رایگان — این جلسه برای همهٔ کاربران باز است</span>
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-tier">سطح اشتراک لازم</label></th>
                            <td>
                                <select id="mrd-lesson-tier">
                                    <option value="bronze">برنزی</option>
                                    <option value="silver">نقره‌ای</option>
                                    <option value="gold">طلایی</option>
                                </select>
                                <p class="description">سطوح بالاتر شامل سطوح پایین‌تر هم هستند (طلایی = همه).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mrd-lesson-sort">ترتیب نمایش</label></th>
                            <td><input type="number" id="mrd-lesson-sort" class="small-text" min="0" max="9999" value="0" /></td>
                        </tr>
                    </table>
                    <p>
                        <button type="button" class="button button-primary" id="mrd-lesson-save">ذخیرهٔ جلسه</button>
                        <button type="button" class="button" id="mrd-lesson-cancel-edit" hidden>انصراف از ویرایش</button>
                        <span id="mrd-lesson-feedback" style="margin-inline-start:12px;font-weight:600;"></span>
                    </p>
                </div>

                <script>
                    var MRD_LESSONS_BOOT = <?php echo wp_json_encode($boot); ?>;
                    (function () {
                        'use strict';
                        var boot = MRD_LESSONS_BOOT;
                        var requestHeaders = {
                            'X-WP-Nonce': boot.nonce,
                            'Content-Type': 'application/json'
                        };
                        var restBase = boot.restRoot.replace(/\/+$/, '') + '/admin/lessons';
                        var productSelect = document.getElementById('mrd-lesson-product');
                        var tableBody = document.querySelector('#mrd-lessons-table tbody');
                        var feedbackEl = document.getElementById('mrd-lesson-feedback');
                        var saveButton = document.getElementById('mrd-lesson-save');
                        var cancelButton = document.getElementById('mrd-lesson-cancel-edit');
                        var formTitle = document.getElementById('mrd-lesson-form-title');
                        var editingId = null;
                        var lessonsCache = [];

                        // v1.4.0: کیفیت‌های اختیاری درس (نشانی‌ها باید https و از هاست مجاز باشند؛
                        // اعتبارسنجی نهایی سمت سرور است — اینجا فقط جمع‌آوری می‌شود)
                        var qualityInputs = [
                            { label: '480p',  el: document.getElementById('mrd-lesson-q480') },
                            { label: '720p',  el: document.getElementById('mrd-lesson-q720') },
                            { label: '1080p', el: document.getElementById('mrd-lesson-q1080') }
                        ];

                        function escapeHtml(value) {
                            var box = document.createElement('div');
                            box.textContent = (value === null || value === undefined) ? '' : String(value);
                            return box.innerHTML;
                        }

                        function tierLabel(tier) {
                            if (tier === 'silver') { return 'نقره‌ای'; }
                            if (tier === 'gold') { return 'طلایی'; }
                            return 'برنزی';
                        }

                        // v1.3.0: پیش‌نمایش دورهٔ انتخاب‌شده — نام و لینک از محصول ووکامرس
                        var courseMetaEl = document.getElementById('mrd-course-meta');

                        function updateCourseMeta() {
                            var selected = productSelect.options[productSelect.selectedIndex];
                            if (!productSelect.value || !selected) {
                                courseMetaEl.classList.remove('is-visible');
                                courseMetaEl.innerHTML = '';
                                return;
                            }
                            var courseUrl = selected.getAttribute('data-url') || '';
                            var courseName = escapeHtml(selected.textContent.replace(/ — منتشرنشده$/, ''));
                            courseMetaEl.innerHTML = 'در حال مدیریت جلسات: <strong>' + courseName + '</strong>'
                                + (courseUrl ? ' — <a href="' + escapeHtml(courseUrl) + '" target="_blank" rel="noopener noreferrer">مشاهدهٔ صفحهٔ دوره ↗</a>' : '');
                            courseMetaEl.classList.add('is-visible');
                        }

                        function feedback(message, isError) {
                            feedbackEl.textContent = message;
                            feedbackEl.style.color = isError ? '#b32d2e' : '#00a32a';
                        }

                        function emptyRow(text) {
                            tableBody.innerHTML = '<tr><td colspan="8" class="mrd-empty">' + escapeHtml(text) + '</td></tr>';
                        }

                        function fetchLessons(productId) {
                            emptyRow('در حال بارگذاری درس‌ها…');
                            fetch(restBase + '?product_id=' + encodeURIComponent(productId), {
                                method: 'GET',
                                headers: requestHeaders,
                                credentials: 'same-origin'
                            }).then(function (response) {
                                return response.json().then(function (data) {
                                    return { ok: response.ok, data: data };
                                });
                            }).then(function (result) {
                                if (!result.ok) {
                                    throw new Error((result.data && result.data.message) || 'خطا در دریافت درس‌ها');
                                }
                                renderRows(result.data.lessons || []);
                            }).catch(function (error) {
                                emptyRow(error.message || 'خطا در دریافت درس‌ها');
                            });
                        }

                        function renderRows(lessons) {
                            lessonsCache = lessons;
                            if (!lessons.length) {
                                emptyRow('هنوز درسی برای این دوره ثبت نشده است.');
                                return;
                            }
                            var html = '';
                            lessons.forEach(function (lesson, index) {
                                var durationText = (lesson.durationMinutes && lesson.durationMinutes > 0)
                                    ? escapeHtml(lesson.durationMinutes) + ' دقیقه'
                                    : '—';
                                html += '<tr>'
                                    + '<td>' + (index + 1) + '</td>'
                                    + '<td>' + escapeHtml(lesson.title) + '</td>'
                                    + '<td dir="ltr"><code>' + escapeHtml(lesson.videoUrl || '') + '</code></td>'
                                    + '<td>' + durationText + '</td>'
                                    + '<td>' + (lesson.isFree ? 'بله' : '—') + '</td>'
                                    + '<td>' + tierLabel(lesson.requiredTier) + '</td>'
                                    + '<td>' + escapeHtml(lesson.sortOrder) + '</td>'
                                    + '<td>'
                                    + '<button type="button" class="button" data-edit="' + escapeHtml(lesson.id) + '">ویرایش</button> '
                                    + '<button type="button" class="button button-link-delete" data-delete="' + escapeHtml(lesson.id) + '">حذف</button>'
                                    + '</td>'
                                    + '</tr>';
                            });
                            tableBody.innerHTML = html;
                        }

                        function resetForm() {
                            editingId = null;
                            document.getElementById('mrd-lesson-title').value = '';
                            document.getElementById('mrd-lesson-video').value = '';
                            document.getElementById('mrd-lesson-duration').value = '0';
                            qualityInputs.forEach(function (q) { q.el.value = ''; });
                            document.getElementById('mrd-lesson-free').checked = false;
                            document.getElementById('mrd-lesson-tier').value = 'bronze';
                            document.getElementById('mrd-lesson-sort').value = '0';
                            formTitle.textContent = '۳) افزودن جلسهٔ جدید';
                            cancelButton.hidden = true;
                            feedback('', false);
                        }

                        function fillForm(lesson) {
                            var storedQualities = {};
                            (lesson.qualities || []).forEach(function (q) {
                                storedQualities[q.label] = q.url;
                            });
                            document.getElementById('mrd-lesson-title').value = lesson.title || '';
                            document.getElementById('mrd-lesson-video').value = lesson.videoUrl || '';
                            document.getElementById('mrd-lesson-duration').value = String(lesson.durationMinutes === undefined ? 0 : lesson.durationMinutes);
                            qualityInputs.forEach(function (q) {
                                q.el.value = storedQualities[q.label] || '';
                            });
                            document.getElementById('mrd-lesson-free').checked = !!lesson.isFree;
                            document.getElementById('mrd-lesson-tier').value = lesson.requiredTier || 'bronze';
                            document.getElementById('mrd-lesson-sort').value = String(lesson.sortOrder === undefined ? 0 : lesson.sortOrder);
                            editingId = lesson.id;
                            formTitle.textContent = '۳) ویرایش جلسه (شناسه: ' + lesson.id + ')';
                            cancelButton.hidden = false;
                            feedback('', false);
                        }

                        productSelect.addEventListener('change', function () {
                            resetForm();
                            updateCourseMeta();
                            if (productSelect.value) {
                                fetchLessons(productSelect.value);
                            } else {
                                emptyRow('ابتدا یک دوره را انتخاب کنید.');
                            }
                        });

                        saveButton.addEventListener('click', function () {
                            var productId = productSelect.value;
                            if (!productId) {
                                feedback('ابتدا یک دوره را انتخاب کنید.', true);
                                return;
                            }
                            var payload = {
                                product_id: productId,
                                title: document.getElementById('mrd-lesson-title').value,
                                video_url: document.getElementById('mrd-lesson-video').value,
                                duration_minutes: document.getElementById('mrd-lesson-duration').value,
                                qualities: qualityInputs.reduce(function (list, q) {
                                    var url = (q.el.value || '').trim();
                                    if (url) { list.push({ label: q.label, url: url }); }
                                    return list;
                                }, []),
                                is_free: document.getElementById('mrd-lesson-free').checked,
                                required_tier: document.getElementById('mrd-lesson-tier').value,
                                sort_order: document.getElementById('mrd-lesson-sort').value
                            };
                            if (payload.video_url && payload.video_url.indexOf('https://') !== 0) {
                                feedback('آدرس ویدیو باید با https:// شروع شود.', true);
                                return;
                            }
                            var requestUrl = restBase;
                            var requestMethod = 'POST';
                            if (editingId) {
                                requestUrl += '/' + encodeURIComponent(editingId);
                                requestMethod = 'PUT';
                            }
                            saveButton.disabled = true;
                            feedback('در حال ذخیره…', false);
                            fetch(requestUrl, {
                                method: requestMethod,
                                headers: requestHeaders,
                                credentials: 'same-origin',
                                body: JSON.stringify(payload)
                            }).then(function (response) {
                                return response.json().then(function (data) {
                                    return { ok: response.ok, data: data };
                                });
                            }).then(function (result) {
                                saveButton.disabled = false;
                                if (!result.ok) {
                                    throw new Error((result.data && result.data.message) || 'ذخیره ناموفق بود');
                                }
                                feedback(editingId ? 'درس با موفقیت به‌روزرسانی شد.' : 'درس جدید ثبت شد.', false);
                                resetForm();
                                fetchLessons(productId);
                            }).catch(function (error) {
                                saveButton.disabled = false;
                                feedback(error.message || 'ذخیره ناموفق بود', true);
                            });
                        });

                        cancelButton.addEventListener('click', resetForm);

                        tableBody.addEventListener('click', function (event) {
                            var target = event.target;
                            if (!target || !target.closest) { return; }
                            var editButton = target.closest('[data-edit]');
                            var deleteButton = target.closest('[data-delete]');

                            if (editButton) {
                                var editId = parseInt(editButton.getAttribute('data-edit'), 10);
                                for (var i = 0; i < lessonsCache.length; i++) {
                                    if (parseInt(lessonsCache[i].id, 10) === editId) {
                                        fillForm(lessonsCache[i]);
                                        window.scrollTo({ top: document.getElementById('mrd-lesson-form-title').offsetTop - 60, behavior: 'smooth' });
                                        break;
                                    }
                                }
                            }

                            if (deleteButton) {
                                var deleteId = parseInt(deleteButton.getAttribute('data-delete'), 10);
                                if (!window.confirm('آیا از حذف این درس مطمئن هستید؟ این عملیات بازگشت‌پذیر نیست.')) {
                                    return;
                                }
                                deleteButton.disabled = true;
                                fetch(restBase + '/' + deleteId, {
                                    method: 'DELETE',
                                    headers: requestHeaders,
                                    credentials: 'same-origin'
                                }).then(function (response) {
                                    return response.json().then(function (data) {
                                        return { ok: response.ok, data: data };
                                    });
                                }).then(function (result) {
                                    if (!result.ok) {
                                        throw new Error((result.data && result.data.message) || 'حذف ناموفق بود');
                                    }
                                    if (editingId === deleteId) {
                                        resetForm();
                                    }
                                    feedback('درس حذف شد.', false);
                                    fetchLessons(productSelect.value);
                                }).catch(function (error) {
                                    deleteButton.disabled = false;
                                    feedback(error.message || 'حذف ناموفق بود', true);
                                });
                            }
                        });
                    })();
                </script>
            <?php endif; ?>
        </div>
        <?php
    }
}
