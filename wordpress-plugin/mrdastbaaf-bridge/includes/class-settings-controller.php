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
        ?>
        <div class="wrap">
            <h1>تنظیمات مستر دستباف</h1>
            <p>تنظیمات اتصال افزونهٔ پل ارتباطی مستر دستباف به درگاه زیبال، فرانت PWA و سرویس پیامک.</p>

            <form method="post" action="options.php">
                <?php settings_fields(self::SETTINGS_GROUP); ?>
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

                <h2 class="title">سرویس پیامک (کد تایید ورود)</h2>
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

                <?php submit_button('ذخیره تنظیمات'); ?>
            </form>

            <?php if ('' !== (string) $sms_last_log): ?>
                <h2 class="title">آخرین وضعیت ارسال پیامک</h2>
                <p><code><?php echo esc_html((string) $sms_last_log); ?></code></p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * صفحهٔ «درس‌ها و ویدیوها» — مدیریت درس‌های محصولات ووکامرس
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

        if ($woo_active) {
            if (function_exists('wc_get_products')) {
                $wc_products = wc_get_products(array(
                    'status'  => array('publish', 'draft', 'private'),
                    'limit'   => 200,
                    'orderby' => 'title',
                    'order'   => 'ASC',
                ));
                foreach ($wc_products as $wc_product) {
                    $products[] = array(
                        'id'   => $wc_product->get_id(),
                        'name' => $wc_product->get_name(),
                    );
                }
            } else {
                // fallback در نبود wc_get_products — خواندن مستقیم post type محصول
                $product_posts = get_posts(array(
                    'post_type'   => 'product',
                    'post_status' => array('publish', 'draft', 'private'),
                    'numberposts' => 200,
                    'orderby'     => 'title',
                    'order'       => 'ASC',
                ));
                foreach ($product_posts as $product_post) {
                    $products[] = array(
                        'id'   => (int) $product_post->ID,
                        'name' => $product_post->post_title,
                    );
                }
            }
        }

        $boot = array(
            'restRoot' => esc_url_raw(rest_url(MRDASTBAAF_API_NAMESPACE)),
            'nonce'    => wp_create_nonce('wp_rest'),
            'products' => $products,
        );
        ?>
        <div class="wrap">
            <h1>درس‌ها و ویدیوها</h1>
            <?php if (!$woo_active): ?>
                <div class="notice notice-error"><p>ووکامرس فعال نیست؛ برای مدیریت درس‌ها ابتدا افزونهٔ ووکامرس را فعال کنید.</p></div>
            <?php else: ?>
                <p>محصول (دوره) را انتخاب کنید و درس‌های آن را مدیریت کنید. آدرس ویدیو باید با <code>https://</code> شروع شود (هاست دانلود <code>dll.mrdastbaaf.com</code>). درس‌های «پیش‌نمایش رایگان» برای همهٔ بازدیدکنندگان قابل تماشا هستند.</p>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-lesson-product">دوره (محصول ووکامرس)</label></th>
                        <td>
                            <select id="mrd-lesson-product">
                                <option value="">— انتخاب دوره —</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?php echo esc_attr((string) $product['id']); ?>"><?php echo esc_html($product['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2 class="title">فهرست درس‌ها</h2>
                <table class="widefat striped" id="mrd-lessons-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>عنوان</th>
                            <th>آدرس ویدیو</th>
                            <th>پیش‌نمایش رایگان</th>
                            <th>سطح اشتراک لازم</th>
                            <th>ترتیب</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="7">ابتدا یک دوره را انتخاب کنید.</td></tr>
                    </tbody>
                </table>

                <h2 class="title" id="mrd-lesson-form-title">افزودن درس جدید</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-lesson-title">عنوان درس</label></th>
                        <td><input type="text" id="mrd-lesson-title" class="regular-text" maxlength="191" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-lesson-video">آدرس ویدیو (https)</label></th>
                        <td>
                            <input type="url" id="mrd-lesson-video" class="large-text code" dir="ltr" placeholder="https://dll.mrdastbaaf.com/..." />
                            <p class="description">فقط نشانی‌های <code>https://</code> پذیرفته می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-lesson-free">پیش‌نمایش رایگان</label></th>
                        <td><input type="checkbox" id="mrd-lesson-free" value="1" /> <label for="mrd-lesson-free">این درس برای همهٔ کاربران رایگان است</label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-lesson-tier">سطح اشتراک لازم</label></th>
                        <td>
                            <select id="mrd-lesson-tier">
                                <option value="bronze">برنزی</option>
                                <option value="silver">نقره‌ای</option>
                                <option value="gold">طلایی</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-lesson-sort">ترتیب نمایش</label></th>
                        <td><input type="number" id="mrd-lesson-sort" class="small-text" min="0" max="9999" value="0" /></td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="mrd-lesson-save">ذخیره درس</button>
                    <button type="button" class="button" id="mrd-lesson-cancel-edit" hidden>انصراف از ویرایش</button>
                    <span id="mrd-lesson-feedback" style="margin-inline-start:12px;"></span>
                </p>

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

                        function feedback(message, isError) {
                            feedbackEl.textContent = message;
                            feedbackEl.style.color = isError ? '#b32d2e' : '#00a32a';
                        }

                        function emptyRow(text) {
                            tableBody.innerHTML = '<tr><td colspan="7">' + escapeHtml(text) + '</td></tr>';
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
                                html += '<tr>'
                                    + '<td>' + (index + 1) + '</td>'
                                    + '<td>' + escapeHtml(lesson.title) + '</td>'
                                    + '<td dir="ltr"><code>' + escapeHtml(lesson.videoUrl || '') + '</code></td>'
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
                            document.getElementById('mrd-lesson-free').checked = false;
                            document.getElementById('mrd-lesson-tier').value = 'bronze';
                            document.getElementById('mrd-lesson-sort').value = '0';
                            formTitle.textContent = 'افزودن درس جدید';
                            cancelButton.hidden = true;
                            feedback('', false);
                        }

                        function fillForm(lesson) {
                            document.getElementById('mrd-lesson-title').value = lesson.title || '';
                            document.getElementById('mrd-lesson-video').value = lesson.videoUrl || '';
                            document.getElementById('mrd-lesson-free').checked = !!lesson.isFree;
                            document.getElementById('mrd-lesson-tier').value = lesson.requiredTier || 'bronze';
                            document.getElementById('mrd-lesson-sort').value = String(lesson.sortOrder === undefined ? 0 : lesson.sortOrder);
                            editingId = lesson.id;
                            formTitle.textContent = 'ویرایش درس (شناسه: ' + lesson.id + ')';
                            cancelButton.hidden = false;
                            feedback('', false);
                        }

                        productSelect.addEventListener('change', function () {
                            resetForm();
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
