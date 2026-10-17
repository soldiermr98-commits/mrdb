<?php
/**
 * زیرصفحه‌های پیشخوان «پلن‌های عضویت» و «بررسی فیش‌ها» (v1.2.1 — فایل NEW)
 *
 * کارفرمای غیرفنی باید قیمت/مدت/متن پلن‌های عضویت و فیش‌های کارت‌به‌کارت را از
 * پیشخوان وردپرس مدیریت کند (پنل ادمین دموی PWA در حالت Real عمداً مسدود است).
 * دو زیرصفحهٔ NEW زیر منوی «مستر دستباف»:
 *
 *  ۱) پلن‌های عضویت — CRUD کامل جدول wp_mrdastbaaf_plans با فرم self-posting
 *     (admin-post.php): فهرست پلن‌ها + افزودن/ویرایش با همهٔ ستون‌ها، toggle سریع
 *     فعال/غیرفعال از جدول و حذف با confirm. textarea «هر ویژگی در یک خط» در
 *     ذخیره به JSON آرایهٔ رشته‌ها تبدیل (و در ویرایش برعکس) می‌شود. پس از هر
 *     نوشتن، کش REST لیست پلن‌ها (mrdastbaaf_plans_cache) باطل می‌شود تا اپ
 *     Real فوراً پلن‌های تازه را ببیند. هیچ تغییری در اسکیما (نسخهٔ ۱.۳.۰)
 *     داده نشده است — فقط همین ستون‌های موجود خوانده/نوشته می‌شوند.
 *
 *  ۲) بررسی فیش‌ها — صف فیش‌های کارت‌به‌کارتِ در انتظار + تاریخچهٔ اخیر با فیلتر
 *     وضعیت. تایید/رد از هستهٔ مشترک
 *     MrDastbaaf_Admin_Controller::review_receipt_decision() عبور می‌کند تا
 *     رفتار REST (ادعای اتمیک pending→verifying، grant، ممیزی) عیناً حفظ شود.
 *
 *  ۳) (v1.3.4) محتوای وب‌اپ — متن‌های بنر اصلی (Hero)، سکشن‌های صفحهٔ اصلی و
 *     منوی اپ (داک موبایل/دسکتاپ + میان‌برهای شیت منو) از پیشخوان؛ سرو REST
 *     با GET /app-ui از کنترلر NEW class-app-ui-controller.php. ترتیب سکشن‌ها
 *     و راهنماها با دکمه‌های ↑/↓ (admin_post مخصوص move) جابه‌جا می‌شود.
 *
 *  ۴) (v1.3.4) اتصال خرید سایت — نگاشت پلن‌های عضویت به محصولات ووکامرس؛
 *     منطق پرداخت در کنترلر NEW class-woo-bridge-controller.php است و این
 *     صفحه فقط فرم نگاشت (انتخاب محصول برای هر پلن) را نگه می‌دارد.
 *
 *  ۵) (v1.4.0) محصولات فیزیکی — انتخاب محصولات ووکامرسی «دست‌ساز» با چک‌باکس
 *     (فیلتر دسته) برای بخش «محصولات» وب‌اپ؛ سرو REST با GET
 *     /shop/products از کنترلر NEW class-shop-controller.php و آپشن مشترک
 *     mrdastbaaf_physical_products. ذخیره، تیک‌های همهٔ دسته‌ها را ادغام می‌کند
 *     و برداشتن تیک در دستهٔ جاری واقعاً حذف می‌کند.
 *
 *  ۶) (v1.5.0) همان صفحه، فیلدست دوم «محصولات ویژه (اسلایدر وب‌اپ)» — چک‌باکس
 *     featured[] با همان گرید واکنش‌گرا و ذخیرهٔ مستقل در آپشن
 *     mrdastbaaf_featured_products (اسلایدر افقی بالای فروشگاه اپ).
 *
 * امنیت: همهٔ عملیات نوشتن از admin-post.php با check_admin_referer +
 * current_user_can('manage_options') + sanitize ورودی‌ها؛ خروجی‌ها با
 * esc_html/esc_attr/esc_url. پیام نتیجهٔ هر عملیات در transient کوتاه‌عمر
 * (۹۰ ثانیه، یک‌بارمصرف) نگهداری می‌شود تا هیچ داده‌ای از query string
 * بازتاب داده نشود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Admin_Pages_Controller {

    const PLANS_SLUG = 'mrdastbaaf-plans';
    const RECEIPTS_SLUG = 'mrdastbaaf-receipts';
    const APP_PAGES_SLUG = 'mrdastbaaf-app-pages';
    // v1.3.4: محتوای وب‌اپ + اتصال خرید سایت
    const APP_UI_SLUG = 'mrdastbaaf-app-ui';
    const WOO_MAP_SLUG = 'mrdastbaaf-woo-map';
    // v1.4.0: محصولات فیزیکی (بخش «محصولات» وب‌اپ) + v1.5.0: محصولات ویژه (اسلایدر)
    const PHYSICAL_SLUG = 'mrdastbaaf-physical';

    public function register() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_post_mrdastbaaf_plan_save', array($this, 'handle_plan_save'));
        add_action('admin_post_mrdastbaaf_plan_toggle', array($this, 'handle_plan_toggle'));
        add_action('admin_post_mrdastbaaf_plan_delete', array($this, 'handle_plan_delete'));
        add_action('admin_post_mrdastbaaf_receipt_review', array($this, 'handle_receipt_review'));
        // v1.5.7: حذف تکی تصویر فیش + پاکسازی جمعی (همراه ذخیرهٔ دورهٔ نگهداری)
        add_action('admin_post_mrdastbaaf_receipt_image_delete', array($this, 'handle_receipt_image_delete'));
        add_action('admin_post_mrdastbaaf_receipt_purge_images', array($this, 'handle_receipt_purge_images'));
        // v1.3.3: صفحات وب‌اپ — انتخاب صفحهٔ سایت + سطح دسترسی از پیشخوان
        add_action('admin_post_mrdastbaaf_app_page_save', array($this, 'handle_app_page_save'));
        add_action('admin_post_mrdastbaaf_app_page_toggle', array($this, 'handle_app_page_toggle'));
        add_action('admin_post_mrdastbaaf_app_page_delete', array($this, 'handle_app_page_delete'));
        add_action('admin_post_mrdastbaaf_app_page_move', array($this, 'handle_app_page_move'));
        // v1.3.4: محتوای وب‌اپ (متن‌ها/سکشن‌ها/منو + جابه‌جایی ترتیب)
        add_action('admin_post_mrdastbaaf_app_ui_save_texts', array($this, 'handle_app_ui_save_texts'));
        add_action('admin_post_mrdastbaaf_app_ui_save_sections', array($this, 'handle_app_ui_save_sections'));
        add_action('admin_post_mrdastbaaf_app_ui_save_menu', array($this, 'handle_app_ui_save_menu'));
        add_action('admin_post_mrdastbaaf_app_ui_move', array($this, 'handle_app_ui_move'));
        // v1.3.5: دوره‌های برگزیده صفحهٔ نخست اپ
        add_action('admin_post_mrdastbaaf_app_ui_save_featured', array($this, 'handle_app_ui_save_featured'));
        // v1.5.7: کارت «مدرس دوره‌ها» (نام/عکس/معرفی — عکس با انتخابگر رسانه)
        add_action('admin_post_mrdastbaaf_app_ui_save_instructor', array($this, 'handle_app_ui_save_instructor'));
        // v1.3.4: اتصال خرید سایت (نگاشت پلن → محصول ووکامرس)
        add_action('admin_post_mrdastbaaf_woo_map_save', array($this, 'handle_woo_map_save'));
        // v1.4.0: محصولات فیزیکی (تیک محصولات بخش «محصولات دست‌ساز» وب‌اپ)
        add_action('admin_post_mrdastbaaf_physical_save', array($this, 'handle_physical_save'));
    }

    /**
     * دو زیرصفحهٔ NEW زیر منوی موجود «مستر دستباف»
     * (اسلاگ والد از کنترلر تنظیمات می‌آید تا فقط یک‌جا تعریف شود)
     */
    public function register_admin_menu() {
        add_submenu_page(
            MrDastbaaf_Settings_Controller::MENU_SLUG,
            'پلن‌های عضویت مستر دستباف',
            'پلن‌های عضویت',
            'manage_options',
            self::PLANS_SLUG,
            array($this, 'render_plans_page')
        );

        add_submenu_page(
            MrDastbaaf_Settings_Controller::MENU_SLUG,
            'بررسی فیش‌های مستر دستباف',
            'بررسی فیش‌ها',
            'manage_options',
            self::RECEIPTS_SLUG,
            array($this, 'render_receipts_page')
        );

        // v1.3.3: صفحات وب‌اپ
        add_submenu_page(
            MrDastbaaf_Settings_Controller::MENU_SLUG,
            'صفحات وب‌اپلیکیشن مستر دستباف',
            'صفحات وب‌اپ',
            'manage_options',
            self::APP_PAGES_SLUG,
            array($this, 'render_app_pages_page')
        );

        // v1.3.4: محتوای وب‌اپ
        add_submenu_page(
            MrDastbaaf_Settings_Controller::MENU_SLUG,
            'محتوای وب‌اپلیکیشن مستر دستباف',
            'محتوای وب‌اپ',
            'manage_options',
            self::APP_UI_SLUG,
            array($this, 'render_app_ui_page')
        );

        // v1.3.4: اتصال خرید سایت
        add_submenu_page(
            MrDastbaaf_Settings_Controller::MENU_SLUG,
            'اتصال خرید ووکامرس به پلن‌های عضویت',
            'اتصال خرید سایت',
            'manage_options',
            self::WOO_MAP_SLUG,
            array($this, 'render_woo_map_page')
        );

        // v1.4.0: محصولات فیزیکی
        add_submenu_page(
            MrDastbaaf_Settings_Controller::MENU_SLUG,
            'محصولات فیزیکی مستر دستباف',
            'محصولات فیزیکی',
            'manage_options',
            self::PHYSICAL_SLUG,
            array($this, 'render_physical_page')
        );
    }

    /* ─────────────────────────── هلپرهای مشترک ─────────────────────────── */

    /**
     * برچسب فارسی سطح پلن
     */
    private function tier_label($tier) {
        $labels = array(
            'free'   => 'رایگان',
            'bronze' => 'برنزی',
            'silver' => 'نقره‌ای',
            'gold'   => 'طلایی',
        );
        $tier = (string) $tier;
        return isset($labels[$tier]) ? $labels[$tier] : $tier;
    }

    /**
     * اسکیمای رنگی مجاز — دقیقاً همان لیست سفید نرمال‌ساز فرانت
     * (PLAN_COLOR_SCHEMES در membershipService: slate/amber/stone/yellow؛
     * هر مقدار ناشناخته در اپ به «stone» فال‌بک می‌شود). لیست معتبرِ کد مبنا است.
     */
    private function get_color_schemes() {
        return array(
            'slate'  => 'خاکستری (slate)',
            'amber'  => 'کهربایی (amber)',
            'stone'  => 'ماسه‌ای (stone)',
            'yellow' => 'زرد (yellow)',
        );
    }

    /**
     * برچسب فارسی وضعیت سفارش — رنگ‌بندی با کلاس mrd-pill--{status} در CSS افزونه
     * (assets/admin-ui.css) انجام می‌شود.
     */
    private function status_label($status) {
        $labels = array(
            'pending'   => 'در انتظار بررسی',
            'verifying' => 'در حال پردازش',
            'completed' => 'تاییدشده',
            'rejected'  => 'ردشده',
            'failed'    => 'ناموفق',
            'cancelled' => 'لغوشده',
        );
        $status = (string) $status;
        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * textarea «هر ویژگی در یک خط» → آرایهٔ پاکسازی‌شدهٔ رشته‌ها (خطوط خالی حذف می‌شوند)
     */
    private function parse_feature_lines($raw) {
        $lines = explode("\n", (string) $raw);
        $clean = array();
        foreach ($lines as $line) {
            $feature = sanitize_text_field($line);
            if ('' !== $feature) {
                $clean[] = $feature;
            }
        }
        return $clean;
    }

    /**
     * پارس عدد صحیح نامنفی از ورودی فرم — خالی/غیرعددی = null (نامعتبر)
     */
    private function parse_non_negative_int($raw) {
        $raw = trim((string) $raw);
        if ('' === $raw || !preg_match('/^[0-9]+$/', $raw)) {
            return null;
        }
        return (int) $raw;
    }

    /**
     * ذخیرهٔ پیام نتیجه (یک‌بارمصرف) — transient ۹۰ ثانیه‌ای per-user
     */
    private function flash($type, $text) {
        set_transient(
            'mrdastbaaf_admin_flash_' . get_current_user_id(),
            array('type' => ('error' === $type ? 'error' : 'success'), 'text' => (string) $text),
            90
        );
    }

    /**
     * خواندن و پاک‌کردن پیام نتیجه (یک‌بارمصرف)
     */
    private function pull_flash() {
        $key = 'mrdastbaaf_admin_flash_' . get_current_user_id();
        $flash = get_transient($key);
        if (is_array($flash)) {
            delete_transient($key);
            if (isset($flash['type'], $flash['text'])) {
                return $flash;
            }
        }
        return null;
    }

    /**
     * ریدایرکت به یکی از زیرصفحه‌های همین کنترلر + خاتمه
     */
    private function redirect_to_page($page, $extra = array()) {
        $url = add_query_arg(array_merge(array('page' => $page), $extra), admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * ریدایرکت پس از خطای اعتبارسنجی فرم پلن — در حالت ویرایش به فرم ویرایش
     * برمی‌گردد تا ادمین رکورد را گم نکند (مقادیر تایپ‌شده از دست می‌رود؛ پیام
     * خطا دقیقاً مشکل را نام می‌برد).
     */
    private function redirect_plan_form_error($mode, $plan_id = '') {
        if ('edit' === $mode && '' !== $plan_id) {
            $this->redirect_to_page(self::PLANS_SLUG, array('action' => 'edit', 'plan_id' => $plan_id));
        }
        $this->redirect_to_page(self::PLANS_SLUG);
    }

    /**
     * نوار اعلان نتیجهٔ عملیات (پیام از transient — نه از query string)
     */
    private function render_flash_notice($flash) {
        if (!is_array($flash)) {
            return;
        }
        $class = ('error' === $flash['type']) ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($flash['text']) . '</p></div>';
    }

    /* ─────────────────────── صفحهٔ «پلن‌های عضویت» ─────────────────────── */

    /**
     * فهرست پلن‌ها + فرم افزودن/ویرایش
     */
    public function render_plans_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';

        $flash = $this->pull_flash();
        $rows = $wpdb->get_results("SELECT * FROM $table_plans ORDER BY price_toman ASC, created_at ASC");

        // حالت ویرایش: ?action=edit&plan_id=…
        $editing = null;
        $features_value = '';
        $features_broken = false;
        $edit_action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ('edit' === $edit_action && isset($_GET['plan_id'])) {
            $editing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $table_plans WHERE id = %s",
                sanitize_text_field(wp_unslash($_GET['plan_id']))
            ));
            if ($editing) {
                // features_json → خطوط (json_decode assoc + is_array check؛
                // JSON خراب = آرایهٔ خالی + پیام خطا، با ذخیرهٔ فرم از نو ساخته می‌شود)
                $decoded = json_decode((string) $editing->features_json, true);
                if (is_array($decoded)) {
                    $features_value = implode("\n", array_map('strval', $decoded));
                } elseif ('' !== trim((string) $editing->features_json)) {
                    $features_broken = true;
                }
            }
        }

        $is_edit = ($editing instanceof stdClass);
        $confirm_delete = 'حذف پلن، سفارش‌های قبلی را تحت تأثیر قرار نمی‌دهد اما دیگر قابل خرید نیست. مطمئنید؟';
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">💳</div>
                <div>
                    <h1 class="mrd-hero__title">پلن‌های عضویت</h1>
                    <p class="mrd-hero__desc">قیمت، مدت و ویژگی‌های پلن‌های عضویت را مدیریت کنید. تغییرات پس از ذخیره در اپلیکیشن (حالت Real) اعمال می‌شود — فهرست عمومی اپ حداکثر ۵ دقیقه کش می‌شود و با هر ذخیره در این صفحه کش بلافاصله باطل می‌گردد.</p>
                </div>
            </div>

            <?php $this->render_flash_notice($flash); ?>

            <?php if ($features_broken): ?>
                <div class="notice notice-warning"><p>ویژگی‌های ذخیره‌شدهٔ این پلن قابل خواندن نیست (JSON نامعتبر)؛ با ذخیرهٔ فرم، فهرست ویژگی‌ها از نو ساخته می‌شود.</p></div>
            <?php endif; ?>

            <?php if ('edit' === $edit_action && !$editing): ?>
                <div class="notice notice-error"><p>پلن برای ویرایش یافت نشد؛ شاید پیش از این حذف شده باشد.</p></div>
            <?php endif; ?>

            <div class="mrd-card">
            <div class="mrd-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>عنوان</th>
                        <th>سطح</th>
                        <th>قیمت (تومان)</th>
                        <th>قیمت پیش از تخفیف</th>
                        <th>مدت</th>
                        <th>برچسب مدت</th>
                        <th>وضعیت</th>
                        <th>محبوب‌ترین</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="mrd-empty">هنوز پلنی ثبت نشده است؛ با فرم پایین اولین پلن را اضافه کنید.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <?php $is_free_tier = ('free' === $row->tier); ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($row->title); ?></strong><br />
                                    <code dir="ltr"><?php echo esc_html($row->id); ?></code>
                                </td>
                                <td><span class="mrd-pill mrd-pill--<?php echo esc_attr((string) $row->tier); ?>"><?php echo esc_html($this->tier_label($row->tier)); ?></span></td>
                                <td><?php echo esc_html(number_format_i18n((int) $row->price_toman)); ?></td>
                                <td>
                                    <?php if (!empty($row->original_price_toman)): ?>
                                        <del><?php echo esc_html(number_format_i18n((int) $row->original_price_toman)); ?></del>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(number_format_i18n((int) $row->duration_days)); ?> روز</td>
                                <td><?php echo esc_html($row->duration_label); ?></td>
                                <td>
                                    <span class="mrd-pill <?php echo $row->is_active ? 'mrd-pill--on' : 'mrd-pill--off'; ?>">
                                        <?php echo $row->is_active ? 'فعال' : 'غیرفعال'; ?>
                                    </span>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;">
                                        <input type="hidden" name="action" value="mrdastbaaf_plan_toggle" />
                                        <input type="hidden" name="plan_id" value="<?php echo esc_attr($row->id); ?>" />
                                        <?php wp_nonce_field('mrdastbaaf_plan_toggle', 'mrd_nonce'); ?>
                                        <button type="submit" class="button button-small"><?php echo $row->is_active ? 'غیرفعال کردن' : 'فعال کردن'; ?></button>
                                    </form>
                                </td>
                                <td><?php echo $row->is_popular ? '<span class="mrd-pill mrd-pill--star">★ محبوب‌ترین</span>' : '—'; ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => self::PLANS_SLUG, 'action' => 'edit', 'plan_id' => $row->id), admin_url('admin.php'))); ?>">ویرایش</a>
                                    <?php if (!$is_free_tier): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;" onsubmit="return confirm('<?php echo esc_attr($confirm_delete); ?>');">
                                            <input type="hidden" name="action" value="mrdastbaaf_plan_delete" />
                                            <input type="hidden" name="plan_id" value="<?php echo esc_attr($row->id); ?>" />
                                            <?php wp_nonce_field('mrdastbaaf_plan_delete', 'mrd_nonce'); ?>
                                            <button type="submit" class="button button-small button-link-delete">حذف</button>
                                        </form>
                                    <?php else: ?>
                                        <p class="description" style="margin-top:6px;">پلن رایگان قابل حذف نیست</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            </div>

            <div class="mrd-card">
            <h2 class="mrd-card__title" id="mrd-plan-form-title"><?php echo $is_edit ? 'ویرایش پلن «' . esc_html($editing->title) . '»' : 'افزودن پلن جدید'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_plan_save" />
                <input type="hidden" name="plan_mode" value="<?php echo $is_edit ? 'edit' : 'add'; ?>" />
                <?php wp_nonce_field('mrdastbaaf_plan_save', 'mrd_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-plan-id">شناسهٔ پلن (id)</label></th>
                        <td>
                            <?php if ($is_edit): ?>
                                <input type="text" id="mrd-plan-id" name="plan_id" value="<?php echo esc_attr($editing->id); ?>" class="regular-text code" dir="ltr" readonly />
                                <p class="description">شناسه در حالت ویرایش تغییر نمی‌کند (کلید رکورد و مرجع سفارش‌ها).</p>
                            <?php else: ?>
                                <input type="text" id="mrd-plan-id" name="plan_id" value="" class="regular-text code" dir="ltr" placeholder="مثلاً silver_winter_1404" />
                                <p class="description">فقط حروف کوچک لاتین، عدد، خط تیره و زیرخط — حداکثر ۶۴ کاراکتر. خالی بگذارید تا خودکار ساخته شود (مثلاً silver_48213).</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-tier">سطح پلن</label></th>
                        <td>
                            <select id="mrd-plan-tier" name="tier">
                                <?php foreach (array('free' => 'رایگان', 'bronze' => 'برنزی', 'silver' => 'نقره‌ای', 'gold' => 'طلایی') as $tier_key => $tier_label): ?>
                                    <option value="<?php echo esc_attr($tier_key); ?>" <?php selected($is_edit ? (string) $editing->tier : 'bronze', $tier_key); ?>><?php echo esc_html($tier_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-title">عنوان پلن</label></th>
                        <td>
                            <input type="text" id="mrd-plan-title" name="title" value="<?php echo $is_edit ? esc_attr($editing->title) : ''; ?>" class="regular-text" maxlength="191" />
                            <p class="description">مثلاً «اشتراک نقره‌ای (ویژه هنرجویان)» — حداکثر ۱۹۱ کاراکتر.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-description">توضیح کوتاه</label></th>
                        <td>
                            <textarea id="mrd-plan-description" name="description" class="large-text" rows="2"><?php echo $is_edit ? esc_textarea((string) $editing->description) : ''; ?></textarea>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-price">قیمت (تومان)</label></th>
                        <td>
                            <input type="number" id="mrd-plan-price" name="price_toman" value="<?php echo $is_edit ? esc_attr((string) (int) $editing->price_toman) : ''; ?>" class="regular-text" min="0" step="1" dir="ltr" />
                            <p class="description">عدد صحیح نامنفی؛ پلن رایگان = ۰.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-original-price">قیمت پیش از تخفیف (تومان)</label></th>
                        <td>
                            <input type="number" id="mrd-plan-original-price" name="original_price_toman" value="<?php echo ($is_edit && !empty($editing->original_price_toman)) ? esc_attr((string) (int) $editing->original_price_toman) : ''; ?>" class="regular-text" min="0" step="1" dir="ltr" />
                            <p class="description">اختیاری — در اپ با خط‌خوردگی کنار قیمت اصلی نمایش داده می‌شود. خالی = بدون قیمت پیش از تخفیف.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-duration-days">مدت اشتراک (روز)</label></th>
                        <td>
                            <input type="number" id="mrd-plan-duration-days" name="duration_days" value="<?php echo $is_edit ? esc_attr((string) (int) $editing->duration_days) : '30'; ?>" class="small-text" min="1" step="1" dir="ltr" />
                            <p class="description">عدد صحیح حداقل ۱ (مثلاً ۳۰، ۹۰، ۳۶۵).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-duration-label">برچسب مدت</label></th>
                        <td>
                            <input type="text" id="mrd-plan-duration-label" name="duration_label" value="<?php echo $is_edit ? esc_attr($editing->duration_label) : ''; ?>" class="regular-text" maxlength="50" placeholder="مثلاً ۳ ماهه" />
                            <p class="description">خالی بگذارید تا خودکار «{مدت} روزه» ساخته شود — حداکثر ۵۰ کاراکتر.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-features">ویژگی‌های پلن</label></th>
                        <td>
                            <textarea id="mrd-plan-features" name="features" class="large-text" rows="6"><?php echo esc_textarea($features_value); ?></textarea>
                            <p class="description">هر ویژگی در یک خط؛ خطوط خالی نادیده گرفته می‌شوند. این موارد به‌صورت فهرست در صفحهٔ عضویت اپ نمایش داده می‌شوند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-popular">محبوب‌ترین</label></th>
                        <td>
                            <input type="checkbox" id="mrd-plan-popular" name="is_popular" value="1" <?php checked($is_edit ? (int) $editing->is_popular : 0, 1); ?> />
                            <label for="mrd-plan-popular">این پلن با برچسب «محبوب‌ترین» نمایش داده شود</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-active">وضعیت</label></th>
                        <td>
                            <input type="checkbox" id="mrd-plan-active" name="is_active" value="1" <?php checked($is_edit ? (int) $editing->is_active : 1, 1); ?> />
                            <label for="mrd-plan-active">پلن فعال باشد و در اپ قابل خرید باشد</label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-plan-color">رنگ پلن در اپ</label></th>
                        <td>
                            <select id="mrd-plan-color" name="color_scheme">
                                <?php foreach ($this->get_color_schemes() as $scheme_key => $scheme_label): ?>
                                    <option value="<?php echo esc_attr($scheme_key); ?>" <?php selected($is_edit ? (string) $editing->color_scheme : 'amber', $scheme_key); ?>><?php echo esc_html($scheme_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button($is_edit ? 'ذخیرهٔ تغییرات پلن' : 'افزودن پلن'); ?>
                <?php if ($is_edit): ?>
                    <p><a href="<?php echo esc_url(add_query_arg(array('page' => self::PLANS_SLUG), admin_url('admin.php'))); ?>">انصراف از ویرایش</a></p>
                <?php endif; ?>
            </form>
            </div>
        </div>
        <?php
    }

    /**
     * ذخیرهٔ پلن (افزودن/ویرایش) — فرم self-posting از admin-post.php
     */
    public function handle_plan_save() {
        global $wpdb;
        check_admin_referer('mrdastbaaf_plan_save', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $mode = (isset($_POST['plan_mode']) && 'edit' === $_POST['plan_mode']) ? 'edit' : 'add';

        $original_id = '';
        if ('edit' === $mode) {
            $original_id = sanitize_text_field(wp_unslash(isset($_POST['plan_id']) ? $_POST['plan_id'] : ''));
            $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_plans WHERE id = %s", $original_id));
            if (!$exists) {
                $this->flash('error', 'پلن یافت نشد؛ شاید پیش از این حذف شده باشد.');
                $this->redirect_to_page(self::PLANS_SLUG);
            }
        }

        // سطح پلن — select محدود (free/bronze/silver/gold)
        $tier = sanitize_key(wp_unslash(isset($_POST['tier']) ? $_POST['tier'] : ''));
        if (!in_array($tier, array('free', 'bronze', 'silver', 'gold'), true)) {
            $this->flash('error', 'سطح پلن معتبر نیست.');
            $this->redirect_plan_form_error($mode, $original_id);
        }

        // عنوان — الزامی، هم‌خوان varchar(191)
        $title = sanitize_text_field(wp_unslash(isset($_POST['title']) ? $_POST['title'] : ''));
        if ('' === $title) {
            $this->flash('error', 'عنوان پلن الزامی است.');
            $this->redirect_plan_form_error($mode, $original_id);
        }
        if (mb_strlen($title) > 191) {
            $this->flash('error', 'عنوان پلن حداکثر ۱۹۱ کاراکتر است.');
            $this->redirect_plan_form_error($mode, $original_id);
        }

        $description = sanitize_textarea_field(wp_unslash(isset($_POST['description']) ? $_POST['description'] : ''));

        // قیمت — عدد صحیح نامنفی (>= 0)
        $price = $this->parse_non_negative_int(wp_unslash(isset($_POST['price_toman']) ? $_POST['price_toman'] : ''));
        if (null === $price) {
            $this->flash('error', 'قیمت پلن باید عدد صحیح نامنفی باشد.');
            $this->redirect_plan_form_error($mode, $original_id);
        }

        // قیمت پیش از تخفیف — خالی = NULL
        $original_price = null;
        $original_price_raw = trim((string) wp_unslash(isset($_POST['original_price_toman']) ? $_POST['original_price_toman'] : ''));
        if ('' !== $original_price_raw) {
            $original_price = $this->parse_non_negative_int($original_price_raw);
            if (null === $original_price) {
                $this->flash('error', 'قیمت پیش از تخفیف باید عدد صحیح نامنفی باشد یا خالی بماند.');
                $this->redirect_plan_form_error($mode, $original_id);
            }
        }

        // مدت — عدد صحیح >= 1؛ سقف ۶۵۵۳۵ سقف ستون smallint unsigned است
        $duration_days = $this->parse_non_negative_int(wp_unslash(isset($_POST['duration_days']) ? $_POST['duration_days'] : ''));
        if (null === $duration_days || $duration_days < 1) {
            $this->flash('error', 'مدت اشتراک باید عددی حداقل ۱ روز باشد.');
            $this->redirect_plan_form_error($mode, $original_id);
        }
        $duration_days = min(65535, $duration_days);

        // برچسب مدت — خالی = «{مدت} روزه» خودکار؛ هم‌خوان varchar(50)
        $duration_label = sanitize_text_field(wp_unslash(isset($_POST['duration_label']) ? $_POST['duration_label'] : ''));
        if ('' === $duration_label) {
            $duration_label = $duration_days . ' روزه';
        }
        if (mb_strlen($duration_label) > 50) {
            $this->flash('error', 'برچسب مدت پلن حداکثر ۵۰ کاراکتر است.');
            $this->redirect_plan_form_error($mode, $original_id);
        }

        // ویژگی‌ها — هر خط یک ویژگی → JSON آرایهٔ رشته‌ها (همیشه آرایهٔ معتبر ذخیره می‌شود)
        $features = $this->parse_feature_lines(wp_unslash(isset($_POST['features']) ? $_POST['features'] : ''));
        $features_json = wp_json_encode($features, JSON_UNESCAPED_UNICODE);

        $is_popular = isset($_POST['is_popular']) ? 1 : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        // رنگ — select محدود (لیست سفید هم‌خوان نرمال‌ساز فرانت)
        $color_scheme = sanitize_key(wp_unslash(isset($_POST['color_scheme']) ? $_POST['color_scheme'] : ''));
        $schemes = $this->get_color_schemes();
        if (!isset($schemes[$color_scheme])) {
            $this->flash('error', 'رنگ پلن معتبر نیست.');
            $this->redirect_plan_form_error($mode, $original_id);
        }

        if ('add' === $mode) {
            // شناسه: خالی = {tier}_{عدد تصادفی} با چک برخورد؛ فقط [a-z0-9_-] و <=۶۴ کاراکتر
            $plan_id = strtolower(sanitize_text_field(wp_unslash(isset($_POST['plan_id']) ? $_POST['plan_id'] : '')));
            if ('' === $plan_id) {
                do {
                    $plan_id = $tier . '_' . wp_rand(10000, 99999);
                } while ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_plans WHERE id = %s", $plan_id)) > 0);
            } elseif (!preg_match('/^[a-z0-9_-]{1,64}$/', $plan_id)) {
                $this->flash('error', 'شناسهٔ پلن فقط می‌تواند شامل حروف کوچک لاتین، عدد، خط تیره و زیرخط باشد (حداکثر ۶۴ کاراکتر).');
                $this->redirect_to_page(self::PLANS_SLUG);
            } elseif ($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table_plans WHERE id = %s", $plan_id)) > 0) {
                $this->flash('error', 'شناسهٔ پلن تکراری است؛ مقدار دیگری وارد کنید.');
                $this->redirect_to_page(self::PLANS_SLUG);
            }

            // original_price_toman nullable — NULL با SQL صریح (نه '') درج می‌شود
            if (null === $original_price) {
                $inserted = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table_plans (id, tier, title, description, price_toman, original_price_toman, duration_days, duration_label, features_json, is_popular, is_active, color_scheme)
                     VALUES (%s, %s, %s, %s, %d, NULL, %d, %s, %s, %d, %d, %s)",
                    $plan_id, $tier, $title, $description, $price, $duration_days, $duration_label, $features_json, $is_popular, $is_active, $color_scheme
                ));
            } else {
                $inserted = $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table_plans (id, tier, title, description, price_toman, original_price_toman, duration_days, duration_label, features_json, is_popular, is_active, color_scheme)
                     VALUES (%s, %s, %s, %s, %d, %d, %d, %s, %s, %d, %d, %s)",
                    $plan_id, $tier, $title, $description, $price, $original_price, $duration_days, $duration_label, $features_json, $is_popular, $is_active, $color_scheme
                ));
            }

            if (false === $inserted) {
                error_log('MrDastbaaf: plan insert failed for id ' . $plan_id . ' — ' . $wpdb->last_error);
                $this->flash('error', 'ذخیرهٔ پلن ناموفق بود؛ لطفاً دوباره تلاش کنید.');
                $this->redirect_plan_form_error($mode);
            }

            // کش REST لیست پلن‌ها باطل می‌شود تا اپ فوراً پلن جدید را ببیند
            delete_transient('mrdastbaaf_plans_cache');
            $this->flash('success', 'پلن جدید با موفقیت ثبت شد.');
            $this->redirect_to_page(self::PLANS_SLUG);
        }

        // ─── ویرایش: همهٔ ستون‌ها (به‌جز id که readonly است) بازنویسی می‌شوند ───
        if (null === $original_price) {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table_plans SET tier = %s, title = %s, description = %s, price_toman = %d, original_price_toman = NULL,
                 duration_days = %d, duration_label = %s, features_json = %s, is_popular = %d, is_active = %d, color_scheme = %s
                 WHERE id = %s",
                $tier, $title, $description, $price, $duration_days, $duration_label, $features_json, $is_popular, $is_active, $color_scheme, $original_id
            ));
        } else {
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table_plans SET tier = %s, title = %s, description = %s, price_toman = %d, original_price_toman = %d,
                 duration_days = %d, duration_label = %s, features_json = %s, is_popular = %d, is_active = %d, color_scheme = %s
                 WHERE id = %s",
                $tier, $title, $description, $price, $original_price, $duration_days, $duration_label, $features_json, $is_popular, $is_active, $color_scheme, $original_id
            ));
        }

        if (false === $updated) {
            error_log('MrDastbaaf: plan update failed for id ' . $original_id . ' — ' . $wpdb->last_error);
            $this->flash('error', 'به‌روزرسانی پلن ناموفق بود؛ لطفاً دوباره تلاش کنید.');
            $this->redirect_plan_form_error($mode, $original_id);
        }

        delete_transient('mrdastbaaf_plans_cache');
        $this->flash('success', 'پلن با موفقیت به‌روزرسانی شد.');
        $this->redirect_to_page(self::PLANS_SLUG);
    }

    /**
     * toggle سریع فعال/غیرفعال از جدول — UPDATE اتمیک (بدون رقابت read-then-write)
     */
    public function handle_plan_toggle() {
        global $wpdb;
        check_admin_referer('mrdastbaaf_plan_toggle', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan_id = sanitize_text_field(wp_unslash(isset($_POST['plan_id']) ? $_POST['plan_id'] : ''));

        $toggled = $wpdb->query($wpdb->prepare(
            "UPDATE $table_plans SET is_active = IF(is_active = 0, 1, 0) WHERE id = %s",
            $plan_id
        ));

        if (false === $toggled) {
            error_log('MrDastbaaf: plan toggle failed for id ' . $plan_id . ' — ' . $wpdb->last_error);
            $this->flash('error', 'تغییر وضعیت پلن ناموفق بود؛ لطفاً دوباره تلاش کنید.');
        } elseif (0 === $toggled) {
            $this->flash('error', 'پلن یافت نشد.');
        } else {
            $row = $wpdb->get_row($wpdb->prepare("SELECT title, is_active FROM $table_plans WHERE id = %s", $plan_id));
            $title = ($row && isset($row->title)) ? $row->title : $plan_id;
            if ($row && (int) $row->is_active === 1) {
                $this->flash('success', 'پلن «' . $title . '» فعال شد و در اپ قابل خرید است.');
            } else {
                $this->flash('success', 'پلن «' . $title . '» غیرفعال شد و از اپ حذف می‌شود.');
            }
            delete_transient('mrdastbaaf_plans_cache');
        }

        $this->redirect_to_page(self::PLANS_SLUG);
    }

    /**
     * حذف پلن — پلن‌های سطح رایگان حذف نمی‌شوند (تصمیم کسب‌وکار: پلن free پایهٔ
     * دسترسی رایگان و منطق سطوح tier_rank است؛ حذفش تعریف «رایگان» را از اپ می‌برد
     * و در tier_daily_fallback هم مرجع است. گارد هم در UI (دکمهٔ حذف نشان داده
     * نمی‌شود) و هم اینجا سمت سرور اعمال می‌شود. در صورت نیاز: غیرفعال کنید.)
     */
    public function handle_plan_delete() {
        global $wpdb;
        check_admin_referer('mrdastbaaf_plan_delete', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $plan_id = sanitize_text_field(wp_unslash(isset($_POST['plan_id']) ? $_POST['plan_id'] : ''));

        $row = $wpdb->get_row($wpdb->prepare("SELECT id, tier, title FROM $table_plans WHERE id = %s", $plan_id));
        if (!$row) {
            $this->flash('error', 'پلن یافت نشد.');
            $this->redirect_to_page(self::PLANS_SLUG);
        }

        if ('free' === $row->tier) {
            $this->flash('error', 'پلن‌های سطح رایگان قابل حذف نیستند؛ در صورت نیاز پلن را غیرفعال کنید.');
            $this->redirect_to_page(self::PLANS_SLUG);
        }

        $deleted = $wpdb->delete($table_plans, array('id' => $row->id), array('%s'));
        if (false === $deleted) {
            error_log('MrDastbaaf: plan delete failed for id ' . $row->id . ' — ' . $wpdb->last_error);
            $this->flash('error', 'حذف پلن ناموفق بود؛ لطفاً دوباره تلاش کنید.');
            $this->redirect_to_page(self::PLANS_SLUG);
        }
        if (0 === $deleted) {
            $this->flash('error', 'پلن یافت نشد.');
            $this->redirect_to_page(self::PLANS_SLUG);
        }

        delete_transient('mrdastbaaf_plans_cache');
        $this->flash('success', 'پلن «' . $row->title . '» حذف شد. سفارش‌های قبلی تحت تأثیر قرار نگرفته‌اند.');
        $this->redirect_to_page(self::PLANS_SLUG);
    }

    /* ─────────────────────── صفحهٔ «بررسی فیش‌ها» ─────────────────────── */

    /**
     * صف فیش‌های کارت‌به‌کارت + تاریخچهٔ اخیر با فیلتر وضعیت
     *
     * تایید/رد با هستهٔ مشترک MrDastbaaf_Admin_Controller::review_receipt_decision()
     * انجام می‌شود (admin_post → redirect با پیام transient) تا رفتار REST دقیقاً
     * حفظ شود. نکته: علت رد در قرارداد REST الزامی است (کد missing_reason) — پس
     * فیلد «دلیل رد» در پیشخوان هم الزامی است تا رفتار دو مسیر یکی بماند.
     */
    public function render_receipts_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        $flash = $this->pull_flash();

        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'pending';
        if (!in_array($status_filter, array('pending', 'verifying', 'completed', 'rejected', 'all'), true)) {
            $status_filter = 'pending';
        }

        // همان JOIN صفحهٔ REST (handle_get_pending_receipts) + ستون یادداشت ادمین
        // v1.5.7: ستون receipt_image_name هم برای نمایش تصویر فیش
        $base_sql = "SELECT o.*, t.tracking_code, t.card_last_four, t.deposit_date_jalali, t.sender_notes, t.admin_review_note, t.reviewed_at, t.receipt_image_name
                     FROM $table_orders o
                     INNER JOIN $table_transfers t ON o.id = t.order_id";

        if ('all' === $status_filter) {
            $rows = $wpdb->get_results($base_sql . " WHERE o.gateway = 'card_to_card' ORDER BY o.created_at DESC LIMIT 100");
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                $base_sql . " WHERE o.gateway = 'card_to_card' AND o.status = %s ORDER BY o.created_at DESC LIMIT 100",
                $status_filter
            ));
        }

        $pending_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE gateway = 'card_to_card' AND status = 'pending'");

        // v1.5.7: آمار پاکسازی تصاویر — تعداد فیش‌های بررسی‌شدهٔ دارای تصویر
        // (کوئری سبک روی ردیف‌های بررسی‌شده؛ فقط برای چیپ اطلاعاتی جعبهٔ پاکسازی)
        $purgeable_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_transfers t
             INNER JOIN $table_orders o ON o.id = t.order_id
             WHERE o.status IN ('completed', 'rejected')
               AND t.receipt_image_name IS NOT NULL AND t.receipt_image_name != ''"
        );
        $retention_days = (int) get_option('mrdastbaaf_receipt_image_retention_days', 14);
        $rest_image_base = rest_url(MRDASTBAAF_API_NAMESPACE . '/receipts/');
        $rest_nonce = wp_create_nonce('wp_rest');

        $tabs = array(
            'pending'   => 'در انتظار بررسی',
            'verifying' => 'در حال پردازش',
            'completed' => 'تاییدشده',
            'rejected'  => 'ردشده',
            'all'       => 'همه',
        );

        $approve_confirm = 'با تایید این فیش اشتراک خریدار فعال یا تمدید می‌شود. ادامه می‌دهید؟';
        $reject_confirm = 'این فیش رد می‌شود و علت آن در سفارش ثبت خواهد شد. مطمئنید؟';
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">🧾</div>
                <div>
                    <h1 class="mrd-hero__title">بررسی فیش‌ها</h1>
                    <p class="mrd-hero__desc">فیش‌های واریز کارت‌به‌کارت هنرجویان. «تایید و فعال‌سازی» اشتراک را بلافاصله فعال/تمدید می‌کند؛ «رد» سفارش را ردشده و علت را برای کاربر ثبت می‌کند.</p>
                </div>
            </div>

            <?php $this->render_flash_notice($flash); ?>

            <div class="mrd-chips" style="margin-top:14px;">
                <span class="mrd-chip mrd-chip--amber">در انتظار بررسی: <?php echo esc_html(number_format_i18n($pending_count)); ?> فیش</span>
            </div>

            <?php
            // ─── v1.5.7: جعبهٔ پاکسازی تصاویر فیش ───
            // کارفرما: «خیلی مهمه که بعد چک کردن، این بخش پاکسازی بشه که زیاد حجم
            // نگیره تو دیتابیس». تصاویر روی فایل‌سیستم‌اند (صفر باد DB)؛ این جعبه
            // حذف دستی جمعی + کرون روزانهٔ خودکار را با دورهٔ نگهداری قابل‌تنظیم می‌دهد.
            ?>
            <div class="mrd-card" style="margin-top:14px;">
                <h2 class="mrd-card__title" style="margin-top:0;">پاکسازی تصاویر فیش‌ها</h2>
                <p class="mrd-hint">
                    تصاویر فیش روی دیسک (پوشهٔ محافظت‌شدهٔ <code dir="ltr">uploads/mrdastbaaf-receipts/</code>) نگهداری می‌شوند و در دیتابیس فقط نام فایل است؛
                    پس «حجم دیتابیس» به‌خاطر عکس رشد نمی‌کند. برای منظم‌ماندن دیسک: تصاویر فیش‌های تایید/رد‌شده پس از دورهٔ زیر خودکار حذف می‌شوند
                    (کرون روزانه) و دکمهٔ پاکسازی هم همین الان همه را یک‌جا پاک می‌کند. ردیف اطلاعات فیش (کد پیگیری/مبلغ/تصمیم) همیشه می‌ماند.
                </p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:6px;">
                    <input type="hidden" name="action" value="mrdastbaaf_receipt_purge_images" />
                    <?php wp_nonce_field('mrdastbaaf_receipt_purge_images', 'mrd_nonce'); ?>
                    <label>
                        حذف خودکار پس از
                        <input type="number" name="retention_days" min="0" max="365" value="<?php echo esc_attr((string) $retention_days); ?>" style="width:72px;" />
                        روز (۰ = بدون حذف خودکار)
                    </label>
                    <button type="submit" class="button button-secondary" onclick="return confirm('تصاویر <?php echo esc_attr(number_format_i18n($purgeable_count)); ?> فیش بررسی‌شده حذف می‌شود. ادامه می‌دهید؟');">
                        پاکسازی الان (<?php echo esc_html(number_format_i18n($purgeable_count)); ?> تصویر بررسی‌شده)
                    </button>
                </form>
            </div>

            <ul class="subsubsub">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <li>
                        <a href="<?php echo esc_url(add_query_arg(array('page' => self::RECEIPTS_SLUG, 'status' => $tab_key), admin_url('admin.php'))); ?>" <?php echo $tab_key === $status_filter ? 'class="current"' : ''; ?>>
                            <?php echo esc_html($tab_label); ?><?php echo 'pending' === $tab_key ? ' (' . esc_html(number_format_i18n($pending_count)) . ')' : ''; ?>
                        </a><?php echo 'all' !== $tab_key ? ' | ' : ''; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

            <div class="mrd-card">
            <div class="mrd-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>شماره سفارش</th>
                        <th>کاربر</th>
                        <th>پلن</th>
                        <th>مبلغ (تومان)</th>
                        <th>جزئیات فیش</th>
                        <th>تصویر فیش</th>
                        <th>تاریخ ثبت</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="9" class="mrd-empty">فیشی با این فیلتر یافت نشد.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><code dir="ltr"><?php echo esc_html($r->order_number); ?></code></td>
                                <td>
                                    <?php echo esc_html($r->user_fullname); ?><br />
                                    <span class="description" dir="ltr"><?php echo esc_html($r->user_phone); ?></span>
                                </td>
                                <td><?php echo esc_html($r->item_title); ?></td>
                                <td><?php echo esc_html(number_format_i18n((int) $r->amount_toman)); ?></td>
                                <td>
                                    کد پیگیری: <code dir="ltr"><?php echo esc_html($r->tracking_code); ?></code><br />
                                    ۴ رقم آخر کارت: <code dir="ltr"><?php echo esc_html($r->card_last_four); ?></code><br />
                                    تاریخ واریز (اعلامی): <?php echo esc_html($r->deposit_date_jalali); ?>
                                    <?php if (!empty($r->sender_notes)): ?>
                                        <br /><span class="description">یادداشت خریدار: <?php echo esc_html($r->sender_notes); ?></span>
                                    <?php endif; ?>
                                    <?php if ('rejected' === $r->status && !empty($r->admin_review_note)): ?>
                                        <br /><span style="color:#b32d2e;">علت رد: <?php echo esc_html($r->admin_review_note); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php $img_relative = (string) ($r->receipt_image_name ?? ''); ?>
                                    <?php if ('' !== $img_relative): ?>
                                        <?php
                                        // v1.5.7: تصویر فقط از مسیر REST با احراز پیشخوان (کوکی + nonce)
                                        // لود می‌شود — مسیر مستقیم فایل برای وب بسته است.
                                        $image_url = add_query_arg('_wpnonce', $rest_nonce, $rest_image_base . rawurlencode((string) $r->id) . '/image');
                                        ?>
                                        <a href="<?php echo esc_url($image_url); ?>" target="_blank" rel="noopener" title="مشاهدهٔ تصویر در اندازهٔ کامل">
                                            <img src="<?php echo esc_url($image_url); ?>" alt="تصویر فیش سفارش <?php echo esc_attr($r->order_number); ?>" loading="lazy" style="width:72px;height:72px;object-fit:cover;border-radius:10px;border:1px solid #e7e5e4;display:block;" />
                                        </a>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('تصویر این فیش حذف شود؟ (اطلاعات فیش می‌ماند)');" style="margin-top:4px;">
                                            <input type="hidden" name="action" value="mrdastbaaf_receipt_image_delete" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr($r->id); ?>" />
                                            <?php wp_nonce_field('mrdastbaaf_receipt_image_delete', 'mrd_nonce'); ?>
                                            <button type="submit" class="button-link delete" style="font-size:11px;">حذف تصویر</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="description">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(date_i18n('Y/m/d H:i', strtotime($r->created_at))); ?></td>
                                <td style="white-space:nowrap;">
                                    <span class="mrd-pill mrd-pill--<?php echo esc_attr((string) $r->status); ?>">
                                        <?php echo esc_html($this->status_label($r->status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ('pending' === $r->status): ?>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_attr($approve_confirm); ?>');">
                                            <input type="hidden" name="action" value="mrdastbaaf_receipt_review" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr($r->id); ?>" />
                                            <input type="hidden" name="decision" value="approve" />
                                            <?php wp_nonce_field('mrdastbaaf_receipt_review', 'mrd_nonce'); ?>
                                            <button type="submit" class="button button-primary button-small">تایید و فعال‌سازی</button>
                                        </form>
                                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;" onsubmit="return confirm('<?php echo esc_attr($reject_confirm); ?>');">
                                            <input type="hidden" name="action" value="mrdastbaaf_receipt_review" />
                                            <input type="hidden" name="order_id" value="<?php echo esc_attr($r->id); ?>" />
                                            <input type="hidden" name="decision" value="reject" />
                                            <?php wp_nonce_field('mrdastbaaf_receipt_review', 'mrd_nonce'); ?>
                                            <label class="description" for="mrd-reject-note-<?php echo esc_attr($r->id); ?>">دلیل رد (الزامی):</label>
                                            <textarea id="mrd-reject-note-<?php echo esc_attr($r->id); ?>" name="admin_note" rows="2" style="width:100%;max-width:240px;" placeholder="مثلاً: مبلغ واریزی با فاکتور هم‌خوان نیست"></textarea>
                                            <button type="submit" class="button button-small button-link-delete">رد</button>
                                        </form>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            </div>

            <?php if ('pending' !== $status_filter): ?>
                <p class="description">حداکثر ۱۰۰ رکورد اخیر نمایش داده می‌شود. عملیات تایید/رد فقط از تب «در انتظار بررسی» ممکن است.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * اکشن تایید/رد فیش از پیشخوان — همان هستهٔ مشترک REST
     */
    public function handle_receipt_review() {
        check_admin_referer('mrdastbaaf_receipt_review', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $order_id = isset($_POST['order_id']) ? wp_unslash($_POST['order_id']) : '';
        $decision = isset($_POST['decision']) ? wp_unslash($_POST['decision']) : '';
        $admin_note = isset($_POST['admin_note']) ? wp_unslash($_POST['admin_note']) : '';

        $result = MrDastbaaf_Admin_Controller::review_receipt_decision($order_id, $decision, $admin_note);

        if (is_wp_error($result)) {
            $this->flash('error', $result->get_error_message());
        } else {
            $this->flash('success', $result['message']);
        }

        $this->redirect_to_page(self::RECEIPTS_SLUG);
    }

    /**
     * v1.5.7: حذف تکی تصویر یک فیش — فایل از دیسک حذف و ستون NULL می‌شود؛
     * ردیف اطلاعات فیش (کد پیگیری/مبلغ/تاریخ/تصمیم) دست‌نخورده می‌ماند.
     */
    public function handle_receipt_image_delete() {
        check_admin_referer('mrdastbaaf_receipt_image_delete', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        global $wpdb;
        $order_id = isset($_POST['order_id']) ? (string) wp_unslash($_POST['order_id']) : '';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        $relative = (string) $wpdb->get_var($wpdb->prepare(
            "SELECT receipt_image_name FROM $table_transfers WHERE order_id = %s LIMIT 1",
            $order_id
        ));

        if ('' === $relative) {
            $this->flash('error', 'تصویری برای این فیش ثبت نشده بود.');
        } else {
            $deleted_file = MrDastbaaf_Payment_Controller::delete_receipt_image_file($relative);
            $wpdb->update(
                $table_transfers,
                array('receipt_image_name' => null),
                array('order_id' => $order_id),
                array('%s'),
                array('%s')
            );
            $this->flash('success', $deleted_file
                ? 'تصویر فیش حذف شد؛ اطلاعات فیش باقی ماند.'
                : 'فایل تصویر روی دیسک یافت نشد ولی رکورد به‌روزرسانی شد (بدون تصویر).');
        }

        $this->redirect_to_page(self::RECEIPTS_SLUG);
    }

    /**
     * v1.5.7: پاکسازی جمعی تصاویر فیش‌های بررسی‌شده + ذخیرهٔ دورهٔ نگهداری (روز).
     * ۰ = بدون حذف خودکار (کرون رد می‌شود)؛ حداکثر ۳۶۵ روز.
     */
    public function handle_receipt_purge_images() {
        check_admin_referer('mrdastbaaf_receipt_purge_images', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $retention = isset($_POST['retention_days']) ? absint($_POST['retention_days']) : 14;
        $retention = min(365, $retention);
        update_option('mrdastbaaf_receipt_image_retention_days', $retention, false);

        $deleted = MrDastbaaf_Payment_Controller::purge_decided_receipt_images(0, 1000);

        $this->flash('success', sprintf(
            'پاکسازی انجام شد: %s تصویر حذف شد. حذف خودکار پس از %s روز %s.',
            number_format_i18n($deleted),
            number_format_i18n($retention),
            0 === $retention ? 'غیرفعال است' : 'فعال است (کرون روزانه)'
        ));
        $this->redirect_to_page(self::RECEIPTS_SLUG);
    }

    /* ───────────────────── صفحهٔ «صفحات وب‌اپ» (v1.3.3) ───────────────────── */

    /**
     * برچسب فارسی سطح دسترسی
     */
    private function access_label($access) {
        $labels = array(
            'all'     => 'همه کاربران',
            'members' => 'اعضای فعال',
            'admins'  => 'فقط مدیر کل',
        );
        $access = (string) $access;
        return isset($labels[$access]) ? $labels[$access] : $access;
    }

    /**
     * ذخیرهٔ آپشن صفحات وب‌اپ (پاکسازی کامل هر رکورد)
     *
     * @param array $entries
     * @return bool
     */
    private function save_app_pages_option(array $entries) {
        $clean = array();
        foreach ($entries as $entry) {
            if (!is_array($entry) || !isset($entry['uid'], $entry['page_id'])) {
                continue;
            }
            $access = isset($entry['access']) ? (string) $entry['access'] : 'all';
            $clean[] = array(
                'uid'       => (string) $entry['uid'],
                'page_id'   => (int) $entry['page_id'],
                'title'     => isset($entry['title']) ? sanitize_text_field((string) $entry['title']) : '',
                'access'    => in_array($access, array('all', 'members', 'admins'), true) ? $access : 'all',
                'is_active' => !empty($entry['is_active']),
                'sort'      => isset($entry['sort']) ? (int) $entry['sort'] : 0,
            );
        }
        return update_option('mrdastbaaf_app_pages', $clean, false);
    }

    /**
     * ساخت شناسهٔ یکتای آلفانومریک برای رکورد صفحه
     */
    private function generate_app_page_uid() {
        $existing = wp_list_pluck(MrDastbaaf_App_Pages_Controller::get_pages_option(), 'uid');
        do {
            $uid = 'p' . strtolower(wp_generate_password(10, false, false));
        } while (in_array($uid, $existing, true));
        return $uid;
    }

    /**
     * فهرست صفحات وب‌اپ + فرم افزودن/ویرایش
     */
    public function render_app_pages_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $flash = $this->pull_flash();
        $pages = MrDastbaaf_App_Pages_Controller::get_pages_option();
        usort($pages, function ($a, $b) {
            return ($a['sort'] <=> $b['sort']) ?: strcmp($a['uid'], $b['uid']);
        });

        // حالت ویرایش: ?action=edit&uid=…
        $editing = null;
        $edit_action = isset($_GET['action']) ? sanitize_key(wp_unslash($_GET['action'])) : '';
        if ('edit' === $edit_action && isset($_GET['uid'])) {
            $edit_uid = sanitize_text_field(wp_unslash($_GET['uid']));
            foreach ($pages as $entry) {
                if ($entry['uid'] === $edit_uid) {
                    $editing = $entry;
                    break;
                }
            }
        }

        $is_edit = is_array($editing);
        $current_page_id = $is_edit ? (int) $editing['page_id'] : 0;
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">📄</div>
                <div>
                    <h1 class="mrd-hero__title">صفحات وب‌اپلیکیشن</h1>
                    <p class="mrd-hero__desc">صفحات منتشرشدهٔ سایت را به منوی وب‌اپ اضافه کنید و برای هر صفحه سطح دسترسی تعیین کنید: «همه کاربران» برای مهمان‌ها، «اعضای فعال» فقط برای کاربران با اشتراک فعال و «فقط مدیر کل». محتوای صفحه مستقیماً از ویرایشگر برگهٔ وردپرس خوانده می‌شود.</p>
                </div>
            </div>

            <?php $this->render_flash_notice($flash); ?>

            <?php if ('edit' === $edit_action && !$editing): ?>
                <div class="notice notice-error"><p>صفحهٔ وب‌اپ برای ویرایش یافت نشد؛ شاید پیش از این حذف شده باشد.</p></div>
            <?php endif; ?>

            <div class="mrd-card">
            <div class="mrd-table-wrap">
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>برچسب در منو</th>
                        <th>برگهٔ وردپرس</th>
                        <th>سطح دسترسی</th>
                        <th>وضعیت</th>
                        <th>ترتیب</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pages)): ?>
                        <tr><td colspan="6" class="mrd-empty">هنوز صفحه‌ای به منوی اپ اضافه نشده است؛ با فرم پایین اولین صفحه را اضافه کنید.</td></tr>
                    <?php else: ?>
                        <?php $total = count($pages); foreach ($pages as $index => $entry): ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html('' !== $entry['title'] ? $entry['title'] : $this->app_page_fallback_title((int) $entry['page_id'])); ?></strong><br />
                                    <code dir="ltr"><?php echo esc_html($entry['uid']); ?></code>
                                </td>
                                <td>
                                    <?php
                                    $wp_page = get_post((int) $entry['page_id']);
                                    if ($wp_page && 'page' === $wp_page->post_type) {
                                        echo esc_html($wp_page->post_title) . '<br />';
                                        $view_link = get_permalink($wp_page);
                                        if (is_string($view_link) && '' !== $view_link) {
                                            echo '<a href="' . esc_url($view_link) . '" target="_blank" rel="noopener">مشاهده در سایت ↗</a>';
                                        }
                                        if ('publish' !== $wp_page->post_status) {
                                            echo '<br /><span class="mrd-pill mrd-pill--off">برگه منتشرنشده — در اپ نمی‌آید</span>';
                                        }
                                    } else {
                                        echo '<span class="mrd-pill mrd-pill--off">برگه حذف شده</span>';
                                    }
                                    ?>
                                </td>
                                <td><span class="mrd-pill mrd-pill--<?php echo esc_attr((string) $entry['access']); ?>"><?php echo esc_html($this->access_label($entry['access'])); ?></span></td>
                                <td>
                                    <span class="mrd-pill <?php echo $entry['is_active'] ? 'mrd-pill--on' : 'mrd-pill--off'; ?>">
                                        <?php echo $entry['is_active'] ? 'فعال' : 'غیرفعال'; ?>
                                    </span>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;">
                                        <input type="hidden" name="action" value="mrdastbaaf_app_page_toggle" />
                                        <input type="hidden" name="uid" value="<?php echo esc_attr($entry['uid']); ?>" />
                                        <?php wp_nonce_field('mrdastbaaf_app_page_toggle', 'mrd_nonce'); ?>
                                        <button type="submit" class="button button-small"><?php echo $entry['is_active'] ? 'غیرفعال کردن' : 'فعال کردن'; ?></button>
                                    </form>
                                </td>
                                <td><?php echo esc_html(number_format_i18n($index + 1)); ?></td>
                                <td>
                                    <a class="button button-small" href="<?php echo esc_url(add_query_arg(array('page' => self::APP_PAGES_SLUG, 'action' => 'edit', 'uid' => $entry['uid']), admin_url('admin.php'))); ?>">ویرایش</a>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-top:6px;">
                                        <input type="hidden" name="action" value="mrdastbaaf_app_page_move" />
                                        <input type="hidden" name="uid" value="<?php echo esc_attr($entry['uid']); ?>" />
                                        <input type="hidden" name="dir" value="up" />
                                        <?php wp_nonce_field('mrdastbaaf_app_page_move', 'mrd_nonce'); ?>
                                        <button type="submit" class="button button-small" <?php disabled(0 === $index); ?> aria-label="انتقال به بالا">↑</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-top:6px;">
                                        <input type="hidden" name="action" value="mrdastbaaf_app_page_move" />
                                        <input type="hidden" name="uid" value="<?php echo esc_attr($entry['uid']); ?>" />
                                        <input type="hidden" name="dir" value="down" />
                                        <?php wp_nonce_field('mrdastbaaf_app_page_move', 'mrd_nonce'); ?>
                                        <button type="submit" class="button button-small" <?php disabled($total - 1 === $index); ?> aria-label="انتقال به پایین">↓</button>
                                    </form>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:6px;" onsubmit="return confirm('این صفحه از منوی اپ حذف شود؟ برگهٔ وردپرس حذف نمی‌شود.');">
                                        <input type="hidden" name="action" value="mrdastbaaf_app_page_delete" />
                                        <input type="hidden" name="uid" value="<?php echo esc_attr($entry['uid']); ?>" />
                                        <?php wp_nonce_field('mrdastbaaf_app_page_delete', 'mrd_nonce'); ?>
                                        <button type="submit" class="button button-small button-link-delete">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
            </div>

            <div class="mrd-card">
            <h2 class="mrd-card__title"><?php echo $is_edit ? 'ویرایش صفحهٔ وب‌اپ' : 'افزودن صفحهٔ جدید به منوی اپ'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_app_page_save" />
                <input type="hidden" name="page_mode" value="<?php echo $is_edit ? 'edit' : 'add'; ?>" />
                <?php if ($is_edit): ?>
                    <input type="hidden" name="uid" value="<?php echo esc_attr($editing['uid']); ?>" />
                <?php endif; ?>
                <?php wp_nonce_field('mrdastbaaf_app_page_save', 'mrd_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-app-page-id">برگهٔ وردپرس</label></th>
                        <td>
                            <?php
                            wp_dropdown_pages(array(
                                'post_type'        => 'page',
                                'post_status'      => 'publish,private',
                                'sort_column'      => 'post_title',
                                'sort_order'       => 'ASC',
                                'name'             => 'page_id',
                                'id'               => 'mrd-app-page-id',
                                'show_option_none' => '— انتخاب برگه —',
                                'option_none_value' => '0',
                                'selected'         => $current_page_id,
                            ));
                            ?>
                            <p class="description">فقط برگه‌های منتشرشده در اپ سرو می‌شوند؛ محتوای برگه از ویرایشگر وردپرس می‌آید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-app-page-title">برچسب در منوی اپ (اختیاری)</label></th>
                        <td>
                            <input type="text" id="mrd-app-page-title" name="title" value="<?php echo $is_edit ? esc_attr($editing['title']) : ''; ?>" class="regular-text" maxlength="60" />
                            <p class="description">خالی بگذارید تا عنوان برگه استفاده شود — حداکثر ۶۰ کاراکتر.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-app-page-access">سطح دسترسی</label></th>
                        <td>
                            <select id="mrd-app-page-access" name="access">
                                <?php foreach (array('all' => 'همه کاربران (حتی مهمان)', 'members' => 'اعضای فعال (اشتراک فعال)', 'admins' => 'فقط مدیر کل') as $access_key => $access_label): ?>
                                    <option value="<?php echo esc_attr($access_key); ?>" <?php selected($is_edit ? (string) $editing['access'] : 'all', $access_key); ?>><?php echo esc_html($access_label); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">بررسی سطح دسترسی هم در سرور (REST) و هم در نمایش منو انجام می‌شود؛ مهمان هرگز محتوای «اعضای فعال» را دریافت نمی‌کند.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button($is_edit ? 'ذخیرهٔ تغییرات' : 'افزودن به منوی اپ'); ?>
                <?php if ($is_edit): ?>
                    <p><a href="<?php echo esc_url(add_query_arg(array('page' => self::APP_PAGES_SLUG), admin_url('admin.php'))); ?>">لغو ویرایش و بازگشت به فهرست</a></p>
                <?php endif; ?>
            </form>
            </div>
        </div>
        <?php
    }

    /**
     * عنوان برگه برای نمایش فهرست (fallback وقتی برچسب خالی است)
     */
    private function app_page_fallback_title($page_id) {
        $title = get_the_title((int) $page_id);
        return (is_string($title) && '' !== $title) ? $title : ('برگه #' . (int) $page_id);
    }

    /**
     * ذخیرهٔ فرم افزودن/ویرایش صفحهٔ وب‌اپ
     */
    public function handle_app_page_save() {
        check_admin_referer('mrdastbaaf_app_page_save', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $mode = isset($_POST['page_mode']) ? sanitize_key(wp_unslash($_POST['page_mode'])) : 'add';
        $page_id = isset($_POST['page_id']) ? absint($_POST['page_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $access = isset($_POST['access']) ? sanitize_key(wp_unslash($_POST['access'])) : 'all';

        if (!in_array($access, array('all', 'members', 'admins'), true)) {
            $access = 'all';
        }

        $wp_page = $page_id ? get_post($page_id) : null;
        if (!$wp_page || 'page' !== $wp_page->post_type) {
            $this->flash('error', 'برگهٔ انتخابی معتبر نیست؛ یک برگه از فهرست انتخاب کنید.');
            $this->redirect_to_page(self::APP_PAGES_SLUG);
        }

        $pages = MrDastbaaf_App_Pages_Controller::get_pages_option();

        if ('edit' === $mode) {
            $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
            $found = false;
            foreach ($pages as $index => $entry) {
                if ($entry['uid'] === $uid) {
                    $pages[$index]['page_id'] = (int) $page_id;
                    $pages[$index]['title'] = $title;
                    $pages[$index]['access'] = $access;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $this->flash('error', 'رکورد برای ویرایش یافت نشد؛ شاید حذف شده باشد.');
                $this->redirect_to_page(self::APP_PAGES_SLUG);
            }
            $this->save_app_pages_option($pages);
            $this->flash('success', 'تغییرات صفحهٔ «' . ('' !== $title ? $title : $wp_page->post_title) . '» ذخیره شد.');
        } else {
            if (count($pages) >= 30) {
                $this->flash('error', 'سقف صفحات وب‌اپ (۳۰ صفحه) پر شده است؛ ابتدا موردی را حذف کنید.');
                $this->redirect_to_page(self::APP_PAGES_SLUG);
            }
            $max_sort = 0;
            foreach ($pages as $entry) {
                $max_sort = max($max_sort, (int) $entry['sort']);
            }
            $pages[] = array(
                'uid'       => $this->generate_app_page_uid(),
                'page_id'   => (int) $page_id,
                'title'     => $title,
                'access'    => $access,
                'is_active' => true,
                'sort'      => $max_sort + 1,
            );
            $this->save_app_pages_option($pages);
            $this->flash('success', 'صفحهٔ «' . ('' !== $title ? $title : $wp_page->post_title) . '» به منوی اپ اضافه شد.');
        }

        $this->redirect_to_page(self::APP_PAGES_SLUG);
    }

    /**
     * فعال/غیرفعال کردن نمایش صفحه در اپ
     */
    public function handle_app_page_toggle() {
        check_admin_referer('mrdastbaaf_app_page_toggle', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $pages = MrDastbaaf_App_Pages_Controller::get_pages_option();
        foreach ($pages as $index => $entry) {
            if ($entry['uid'] === $uid) {
                $pages[$index]['is_active'] = empty($entry['is_active']);
                $this->save_app_pages_option($pages);
                $this->flash('success', $pages[$index]['is_active'] ? 'صفحه فعال شد.' : 'صفحه غیرفعال شد.');
                break;
            }
        }

        $this->redirect_to_page(self::APP_PAGES_SLUG);
    }

    /**
     * حذف رکورد صفحهٔ وب‌اپ (برگهٔ وردپرس دست‌نخورده می‌ماند)
     */
    public function handle_app_page_delete() {
        check_admin_referer('mrdastbaaf_app_page_delete', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $pages = MrDastbaaf_App_Pages_Controller::get_pages_option();
        $remaining = array_values(array_filter($pages, function ($entry) use ($uid) {
            return $entry['uid'] !== $uid;
        }));

        if (count($remaining) !== count($pages)) {
            $this->save_app_pages_option($remaining);
            $this->flash('success', 'صفحه از منوی اپ حذف شد (برگهٔ وردپرس حفظ شد).');
        }

        $this->redirect_to_page(self::APP_PAGES_SLUG);
    }

    /**
     * جابه‌جایی ترتیب صفحه (بالا/پایین)
     */
    public function handle_app_page_move() {
        check_admin_referer('mrdastbaaf_app_page_move', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $uid = isset($_POST['uid']) ? sanitize_text_field(wp_unslash($_POST['uid'])) : '';
        $dir = isset($_POST['dir']) ? sanitize_key(wp_unslash($_POST['dir'])) : 'up';

        $pages = MrDastbaaf_App_Pages_Controller::get_pages_option();
        usort($pages, function ($a, $b) {
            return ($a['sort'] <=> $b['sort']) ?: strcmp($a['uid'], $b['uid']);
        });

        $index = null;
        foreach ($pages as $i => $entry) {
            if ($entry['uid'] === $uid) {
                $index = $i;
                break;
            }
        }

        if (null !== $index) {
            $swap_with = ('up' === $dir) ? $index - 1 : $index + 1;
            if ($swap_with >= 0 && $swap_with < count($pages)) {
                $tmp = $pages[$index]['sort'];
                $pages[$index]['sort'] = $pages[$swap_with]['sort'];
                $pages[$swap_with]['sort'] = $tmp;
                $this->save_app_pages_option(array_values($pages));
                $this->flash('success', 'ترتیب صفحه به‌روزرسانی شد.');
            }
        }

        $this->redirect_to_page(self::APP_PAGES_SLUG);
    }

    /* ─────────────────────── صفحهٔ «محتوای وب‌اپ» (v1.3.4) ─────────────────────── */

    /**
     * صفحهٔ «محتوای وب‌اپ» — سه کارت: متن‌های بنر اصلی (Hero)، سکشن‌های صفحهٔ نخست
     * و منوی وب‌اپ (داک + راهنماها). داده از هستهٔ مشترک
     * MrDastbaaf_App_UI_Controller::get_app_ui() (پیش‌فرض + ذخیره‌شده) می‌آید و
     * REST GET /app-ui دقیقاً همین شکل را سرو می‌کند — یعنی هرچه اینجا ذخیره
     * شود همان است که اپ نشان می‌دهد. هیچ جدول/اسکیمای جدیدی در کار نیست.
     */
    public function render_app_ui_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $flash = $this->pull_flash();
        $ui = MrDastbaaf_App_UI_Controller::get_app_ui();
        $updated_at = (int) get_option(MrDastbaaf_App_UI_Controller::UPDATED_AT_OPTION, 0);
        $admin_post = admin_url('admin-post.php');

        // v1.5.7: انتخابگر رسانهٔ وردپرس فقط برای همین صفحه (کارت مدرس) لود می‌شود
        wp_enqueue_media();
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">🎨</div>
                <div>
                    <h1 class="mrd-hero__title">محتوای وب‌اپلیکیشن</h1>
                    <p class="mrd-hero__desc">متن‌های بنر اصلی صفحهٔ نخست، عنوان و توضیح سکشن‌ها و برچسب‌های منوی اپ را از این‌جا مدیریت کنید. تغییرات بلافاصله در اندپوینت <code dir="ltr">GET /app-ui</code> سرو می‌شود و پاسخ‌های آن همیشه بدون کش هستند؛ مقدار هر فیلدِ ذخیره‌شده روی پیش‌فرض سوار می‌شود و فیلدِ جاافتاده از پیش‌فرض می‌آید.</p>
                </div>
            </div>

            <?php $this->render_flash_notice($flash); ?>

            <?php if ($updated_at > 0): ?>
                <p class="mrd-muted">آخرین به‌روزرسانی محتوا: <?php echo esc_html(date_i18n('Y/m/d — H:i', $updated_at)); ?></p>
            <?php endif; ?>

            <div class="mrd-card">
            <h2 class="mrd-card__title">متن‌های بنر اصلی (Hero)</h2>
            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_app_ui_save_texts" />
                <?php wp_nonce_field('mrdastbaaf_app_ui_save_texts', 'mrd_nonce'); ?>
                <table class="form-table" role="presentation">
                    <?php
                    $this->app_ui_text_row('متن نشان بالای تیتر', 'badgeText', $ui['hero']['badgeText']);
                    $this->app_ui_text_row('تیتر اصلی بنر', 'headline', $ui['hero']['headline']);
                    $this->app_ui_text_row('توضیح زیر تیتر', 'subheadline', $ui['hero']['subheadline']);
                    $this->app_ui_text_row('متن دکمهٔ اصلی', 'ctaPrimaryText', $ui['hero']['ctaPrimaryText']);
                    $this->app_ui_text_row('متن دکمهٔ دوم', 'ctaSecondaryText', $ui['hero']['ctaSecondaryText']);
                    $this->app_ui_text_row('آمار ۱ — عدد', 'stat1Value', $ui['hero']['stat1Value'], 'مثلاً «+۱,۴۰۰»');
                    $this->app_ui_text_row('آمار ۱ — توضیح', 'stat1Label', $ui['hero']['stat1Label']);
                    $this->app_ui_text_row('آمار ۲ — عدد', 'stat2Value', $ui['hero']['stat2Value'], 'مثلاً «۶ دوره»');
                    $this->app_ui_text_row('آمار ۲ — توضیح', 'stat2Label', $ui['hero']['stat2Label']);
                    $this->app_ui_text_row('آمار ۳ — عدد', 'stat3Value', $ui['hero']['stat3Value'], 'مثلاً «۱۰۰٪ دستی»');
                    $this->app_ui_text_row('آمار ۳ — توضیح', 'stat3Label', $ui['hero']['stat3Label']);
                    ?>
                </table>
                <?php submit_button('ذخیرهٔ متن‌های بنر'); ?>
            </form>
            </div>

            <?php
            // ─── v1.5.7: کارت «مدرس دوره‌ها» ───
            // نام و عکس مدرس به‌صورت سراسری برای همهٔ کارت‌های دوره (جایی که سرور
            // فیلد مدرس تکی نمی‌فرستد) + معرفی اختیاری. عکس با انتخابگر رسانهٔ وردپرس
            // یا URL دستی؛ خالی بودن عکس = آواتار برند با حرف اول نام در اپ.
            $instructor = isset($ui['instructor']) && is_array($ui['instructor'])
                ? $ui['instructor']
                : array('name' => 'استاد خاموشیان', 'photoUrl' => '', 'bio' => '');
            ?>
            <div class="mrd-card">
            <h2 class="mrd-card__title">مدرس دوره‌ها</h2>
            <p class="mrd-hint">نام و عکسی که در کارت‌های دوره (کنار نام مدرس) و بخش «مدرس دوره» در جزئیات دیده می‌شود. عکس مربعی و حداقل ۲۵۶×۲۵۶ پیکسل پیشنهاد می‌شود؛ اگر عکسی انتخاب نشود، اپ آواتار حرف اول نام را نشان می‌دهد.</p>
            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_app_ui_save_instructor" />
                <?php wp_nonce_field('mrdastbaaf_app_ui_save_instructor', 'mrd_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mrd-instructor-name">نام مدرس</label></th>
                        <td>
                            <input type="text" id="mrd-instructor-name" name="instructor_name" value="<?php echo esc_attr($instructor['name']); ?>" class="regular-text" placeholder="استاد خاموشیان" />
                            <p class="description">در کارت‌های دوره به‌جای نام قدیمی «استاد مستر دستباف» نمایش داده می‌شود.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-instructor-photo">عکس مدرس</label></th>
                        <td>
                            <input type="text" id="mrd-instructor-photo" name="instructor_photo" value="<?php echo esc_attr($instructor['photoUrl']); ?>" class="regular-text" dir="ltr" placeholder="https://…" />
                            <button type="button" class="button" id="mrd-instructor-photo-pick">انتخاب از رسانه</button>
                            <button type="button" class="button-link" id="mrd-instructor-photo-clear" <?php echo '' === (string) $instructor['photoUrl'] ? 'style="display:none;"' : ''; ?>>حذف عکس</button>
                            <p class="description">از کتابخانهٔ رسانهٔ وردپرس انتخاب کنید یا URL مستقیم بدهید. خالی = آواتار حرف اول نام.</p>
                            <div id="mrd-instructor-photo-preview" style="margin-top:8px;<?php echo '' === (string) $instructor['photoUrl'] ? 'display:none;' : ''; ?>">
                                <img src="<?php echo esc_url((string) $instructor['photoUrl']); ?>" alt="پیش‌نمایش عکس مدرس" style="max-width:96px;max-height:96px;border-radius:12px;border:1px solid #e7e5e4;" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mrd-instructor-bio">معرفی مدرس (اختیاری)</label></th>
                        <td>
                            <textarea id="mrd-instructor-bio" name="instructor_bio" rows="3" class="large-text"><?php echo esc_textarea((string) $instructor['bio']); ?></textarea>
                            <p class="description">در بخش «مدرس دوره» صفحهٔ جزئیات دوره زیر نام مدرس نمایش داده می‌شود (فقط وقتی دورهٔ مربوطه معرفی تکی خودش را نداشته باشد).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('ذخیرهٔ مدرس دوره‌ها'); ?>
            </form>
            <script>
            // انتخابگر رسانهٔ وردپرس — سبک، فقط یک فیلد (بدون فریم‌ورک)
            (function () {
                var input = document.getElementById('mrd-instructor-photo');
                var preview = document.getElementById('mrd-instructor-photo-preview');
                var clearBtn = document.getElementById('mrd-instructor-photo-clear');
                if (!input || !preview) return;
                document.getElementById('mrd-instructor-photo-pick').addEventListener('click', function (e) {
                    e.preventDefault();
                    if (!window.wp || !wp.media) return;
                    var frame = wp.media({ title: 'انتخاب عکس مدرس', multiple: false, library: { type: 'image' } });
                    frame.on('select', function () {
                        var attachment = frame.state().get('selection').first().toJSON();
                        var url = (attachment.sizes && attachment.sizes.medium) ? attachment.sizes.medium.url : attachment.url;
                        input.value = url;
                        var img = preview.querySelector('img');
                        if (img) img.src = url;
                        preview.style.display = 'block';
                        if (clearBtn) clearBtn.style.display = 'inline';
                    });
                    frame.open();
                });
                if (clearBtn) {
                    clearBtn.addEventListener('click', function (e) {
                        e.preventDefault();
                        input.value = '';
                        preview.style.display = 'none';
                        clearBtn.style.display = 'none';
                    });
                }
            })();
            </script>
            </div>

            <div class="mrd-card">
            <h2 class="mrd-card__title">سکشن‌های صفحهٔ نخست</h2>
            <p class="mrd-hint">عنوان و توضیح هر سکشن + روشن/خاموش کردن نمایش آن. ترتیب را با دکمه‌های ↑/↓ جابه‌جا کنید؛ ردیف ۱ صفحهٔ نخست برای بنر Hero نگه داشته می‌شود و سکشن‌ها پس از آن می‌آیند.</p>
            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_app_ui_save_sections" />
                <?php wp_nonce_field('mrdastbaaf_app_ui_save_sections', 'mrd_nonce'); ?>
                <div class="mrd-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>عنوان سکشن</th>
                            <th>توضیح سکشن</th>
                            <th>نمایش در اپ</th>
                            <th>ترتیب</th>
                            <th>جابه‌جایی</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ui['sections'] as $section): $section_key = (string) $section['key']; ?>
                            <tr>
                                <td><input type="text" name="sections[<?php echo esc_attr($section_key); ?>][title]" value="<?php echo esc_attr($section['title']); ?>" class="regular-text" /></td>
                                <td><input type="text" name="sections[<?php echo esc_attr($section_key); ?>][subtitle]" value="<?php echo esc_attr($section['subtitle']); ?>" class="regular-text" /></td>
                                <td><input type="checkbox" name="sections[<?php echo esc_attr($section_key); ?>][enabled]" value="1" <?php checked(!empty($section['enabled'])); ?> /></td>
                                <td><?php echo esc_html(number_format_i18n((int) $section['sortOrder'])); ?></td>
                                <td>
                                    <button type="submit" class="button button-small" form="mrd-app-ui-section-<?php echo esc_attr($section_key); ?>-up" aria-label="انتقال به بالا">↑</button>
                                    <button type="submit" class="button button-small" form="mrd-app-ui-section-<?php echo esc_attr($section_key); ?>-down" aria-label="انتقال به پایین">↓</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php submit_button('ذخیرهٔ سکشن‌ها'); ?>
            </form>
            <?php
            // فرم‌های کوچک جابه‌جایی ترتیب (بیرون از فرم اصلی تا تودرتو نشوند؛
            // دکمه‌های ↑/↓ بالا با مشخصهٔ HTML5 form= به همین‌ها وصل می‌شوند)
            foreach ($ui['sections'] as $section):
                $section_key = (string) $section['key'];
                foreach (array('up', 'down') as $move_dir): ?>
                <form method="post" action="<?php echo esc_url($admin_post); ?>" style="display:none;" id="mrd-app-ui-section-<?php echo esc_attr($section_key); ?>-<?php echo esc_attr($move_dir); ?>">
                    <input type="hidden" name="action" value="mrdastbaaf_app_ui_move" />
                    <input type="hidden" name="scope" value="sections" />
                    <input type="hidden" name="key" value="<?php echo esc_attr($section_key); ?>" />
                    <input type="hidden" name="dir" value="<?php echo esc_attr($move_dir); ?>" />
                    <?php wp_nonce_field('mrdastbaaf_app_ui_move', 'mrd_nonce'); ?>
                </form>
            <?php endforeach; endforeach; ?>
            </div>

            <?php
            // ─── v1.3.5: کارت «دوره‌های برگزیده» ───
            // محصولات دورهٔ ووکامرس (منتشرشده در دستهٔ تنظیم‌شده) با چک‌باکس انتخاب؛
            // همین‌ها در GET /app-ui با کلید featuredCourseIds سرو می‌شوند.
            $featured_ids = isset($ui['featuredCourseIds']) && is_array($ui['featuredCourseIds']) ? array_map('intval', $ui['featuredCourseIds']) : array();
            $course_category_slug = (string) get_option('mrdastbaaf_course_category', 'courses');
            $course_products = get_posts(array(
                'post_type'        => 'product',
                'post_status'      => 'publish',
                'posts_per_page'   => 200,
                'orderby'          => 'title',
                'order'            => 'ASC',
                'no_found_rows'    => true,
                'suppress_filters' => false,
                'tax_query'        => ('' !== $course_category_slug) ? array(array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $course_category_slug,
                )) : array(),
            ));
            ?>
            <div class="mrd-card">
            <h2 class="mrd-card__title">دوره‌های برگزیده (سکشن «دوره‌های برگزیده و پیشنهادی اساتید»)</h2>
            <p class="mrd-hint">دوره‌هایی که می‌خواهید در سکشن «برگزیده» صفحهٔ نخست اپ نمایش داده شوند را تیک بزنید و ذخیره کنید. اگر هیچ دوره‌ای انتخاب نشود، آن سکشن در اپ خالی می‌ماند (کارت «هنوز دوره ویژه‌ای منتشر نشده است»).</p>
            <?php if (empty($course_products)): ?>
                <p class="mrd-muted">هنوز محصولی در دستهٔ دوره‌ها (<?php echo esc_html($course_category_slug); ?>) منتشر نشده است. ابتدا محصولات دوره را در ووکامرس منتشر کنید.</p>
            <?php else: ?>
            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_app_ui_save_featured" />
                <?php wp_nonce_field('mrdastbaaf_app_ui_save_featured', 'mrd_nonce'); ?>
                <div class="mrd-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width:48px;">انتخاب</th>
                            <th>دوره</th>
                            <th>شناسهٔ محصول</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($course_products as $course_post): $pid = (int) $course_post->ID; ?>
                            <tr>
                                <td><input type="checkbox" name="featured[]" value="<?php echo esc_attr($pid); ?>" <?php checked(in_array($pid, $featured_ids, true)); ?> /></td>
                                <td><?php echo esc_html($course_post->post_title); ?></td>
                                <td><code dir="ltr"><?php echo esc_html((string) $pid); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php submit_button('ذخیرهٔ دوره‌های برگزیده'); ?>
            </form>
            <?php endif; ?>
            </div>

            <div class="mrd-card">
            <h2 class="mrd-card__title">منوی وب‌اپ</h2>
            <p class="mrd-hint">نوار پایین/بالای اپ (داک): چهار آیتم ثابت با برچسب و کلید نمایش قابل تنظیم — ترتیب داک در اپ ثابت است. میان‌برهای شیت منو (راهنماها): برچسب + کلید نمایش + ترتیبِ قابل جابه‌جایی با ↑/↓.</p>
            <form method="post" action="<?php echo esc_url($admin_post); ?>">
                <input type="hidden" name="action" value="mrdastbaaf_app_ui_save_menu" />
                <?php wp_nonce_field('mrdastbaaf_app_ui_save_menu', 'mrd_nonce'); ?>
                <div class="mrd-table-wrap">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>آیتم داک</th>
                            <th>برچسب</th>
                            <th>نمایش در اپ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ui['menu']['dock'] as $item): $item_key = (string) $item['key']; ?>
                            <tr>
                                <td><code dir="ltr"><?php echo esc_html($item_key); ?></code></td>
                                <td><input type="text" name="dock[<?php echo esc_attr($item_key); ?>][label]" value="<?php echo esc_attr($item['label']); ?>" class="regular-text" /></td>
                                <td><input type="checkbox" name="dock[<?php echo esc_attr($item_key); ?>][enabled]" value="1" <?php checked(!empty($item['enabled'])); ?> /></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <div class="mrd-table-wrap" style="margin-top:18px;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>میان‌بر منو (راهنماها)</th>
                            <th>برچسب</th>
                            <th>نمایش در اپ</th>
                            <th>ترتیب</th>
                            <th>جابه‌جایی</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ui['menu']['guides'] as $item): $item_key = (string) $item['key']; ?>
                            <tr>
                                <td><code dir="ltr"><?php echo esc_html($item_key); ?></code></td>
                                <td><input type="text" name="guides[<?php echo esc_attr($item_key); ?>][label]" value="<?php echo esc_attr($item['label']); ?>" class="regular-text" /></td>
                                <td><input type="checkbox" name="guides[<?php echo esc_attr($item_key); ?>][enabled]" value="1" <?php checked(!empty($item['enabled'])); ?> /></td>
                                <td><?php echo esc_html(number_format_i18n((int) $item['sortOrder'])); ?></td>
                                <td>
                                    <button type="submit" class="button button-small" form="mrd-app-ui-guide-<?php echo esc_attr($item_key); ?>-up" aria-label="انتقال به بالا">↑</button>
                                    <button type="submit" class="button button-small" form="mrd-app-ui-guide-<?php echo esc_attr($item_key); ?>-down" aria-label="انتقال به پایین">↓</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php submit_button('ذخیرهٔ منو'); ?>
            </form>
            <?php
            // فرم‌های کوچک جابه‌جایی راهنماها (همان الگوی سکشن‌ها)
            foreach ($ui['menu']['guides'] as $item):
                $item_key = (string) $item['key'];
                foreach (array('up', 'down') as $move_dir): ?>
                <form method="post" action="<?php echo esc_url($admin_post); ?>" style="display:none;" id="mrd-app-ui-guide-<?php echo esc_attr($item_key); ?>-<?php echo esc_attr($move_dir); ?>">
                    <input type="hidden" name="action" value="mrdastbaaf_app_ui_move" />
                    <input type="hidden" name="scope" value="guides" />
                    <input type="hidden" name="key" value="<?php echo esc_attr($item_key); ?>" />
                    <input type="hidden" name="dir" value="<?php echo esc_attr($move_dir); ?>" />
                    <?php wp_nonce_field('mrdastbaaf_app_ui_move', 'mrd_nonce'); ?>
                </form>
            <?php endforeach; endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * ردیف ورودی متنی فرم‌توان Hero — فقط خروجی HTML؛ پاکسازی در هندلر ذخیره
     */
    private function app_ui_text_row($label, $name, $value, $description = '') {
        ?>
        <tr>
            <th scope="row"><label for="mrd-app-ui-<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input type="text" id="mrd-app-ui-<?php echo esc_attr($name); ?>" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>" class="regular-text" />
                <?php if ('' !== (string) $description): ?>
                    <p class="description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * ذخیرهٔ متن‌های بنر اصلی (Hero) — ۱۱ فیلد متنی؛ فقط بخش hero بازنویسی
     * می‌شود و سکشن‌ها/منوی ذخیره‌شده دست‌نخورده می‌مانند.
     */
    public function handle_app_ui_save_texts() {
        check_admin_referer('mrdastbaaf_app_ui_save_texts', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $fields = array(
            'badgeText', 'headline', 'subheadline', 'ctaPrimaryText', 'ctaSecondaryText',
            'stat1Value', 'stat1Label', 'stat2Value', 'stat2Label', 'stat3Value', 'stat3Label',
        );
        $hero = array();
        foreach ($fields as $field) {
            $hero[$field] = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';
        }

        $stored = MrDastbaaf_App_UI_Controller::get_stored();
        $stored['hero'] = $hero;
        MrDastbaaf_App_UI_Controller::save($stored);

        $this->flash('success', 'متن‌های بنر اصلی ذخیره شد؛ اپ از همین لحظه متن‌های تازه را می‌بیند.');
        $this->redirect_to_page(self::APP_UI_SLUG);
    }

    /**
     * v1.5.7: ذخیرهٔ مدرس سراسری دوره‌ها (نام/عکس/معرفی) — کارت «مدرس دوره‌ها»
     * در صفحهٔ محتوای وب‌اپ. ذخیرهٔ جزئی: فقط بلاک instructor بازنویسی می‌شود.
     */
    public function handle_app_ui_save_instructor() {
        check_admin_referer('mrdastbaaf_app_ui_save_instructor', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $instructor = array(
            'name'     => isset($_POST['instructor_name'])     ? sanitize_text_field(wp_unslash($_POST['instructor_name'])) : '',
            'photoUrl' => isset($_POST['instructor_photo'])    ? esc_url_raw(wp_unslash($_POST['instructor_photo'])) : '',
            'bio'      => isset($_POST['instructor_bio'])      ? sanitize_textarea_field(wp_unslash($_POST['instructor_bio'])) : '',
        );

        $stored = MrDastbaaf_App_UI_Controller::get_stored();
        $stored['instructor'] = $instructor;
        MrDastbaaf_App_UI_Controller::save($stored);

        $this->flash('success', 'مدرس دوره‌ها ذخیره شد؛ نام و عکس تازه تا ۲ دقیقه در اپ همهٔ هنرجویان اعمال می‌شود.');
        $this->redirect_to_page(self::APP_UI_SLUG);
    }

    /**
     * ذخیرهٔ سکشن‌های صفحهٔ نخست — عنوان/توضیح/نمایش از فرم؛ ترتیب (sortOrder)
     * از وضعیت فعلی حفظ می‌شود و فقط با دکمه‌های ↑/↓ تغییر می‌کند.
     */
    public function handle_app_ui_save_sections() {
        check_admin_referer('mrdastbaaf_app_ui_save_sections', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $posted = (isset($_POST['sections']) && is_array($_POST['sections'])) ? wp_unslash($_POST['sections']) : array();
        $current = MrDastbaaf_App_UI_Controller::get_app_ui();

        $sections = array();
        foreach ($current['sections'] as $section) {
            $key = (string) $section['key'];
            $entry = array(
                'key'       => $key,
                'title'     => (string) $section['title'],
                'subtitle'  => (string) $section['subtitle'],
                'enabled'   => !empty($section['enabled']),
                'sortOrder' => (int) $section['sortOrder'],
            );
            if (isset($posted[$key]) && is_array($posted[$key])) {
                if (isset($posted[$key]['title'])) {
                    $entry['title'] = sanitize_text_field($posted[$key]['title']);
                }
                if (isset($posted[$key]['subtitle'])) {
                    $entry['subtitle'] = sanitize_text_field($posted[$key]['subtitle']);
                }
                $entry['enabled'] = !empty($posted[$key]['enabled']);
            }
            $sections[] = $entry;
        }

        $stored = MrDastbaaf_App_UI_Controller::get_stored();
        $stored['sections'] = $sections;
        MrDastbaaf_App_UI_Controller::save($stored);

        $this->flash('success', 'سکشن‌های صفحهٔ نخست ذخیره شد.');
        $this->redirect_to_page(self::APP_UI_SLUG);
    }

    /**
     * v1.3.5: ذخیرهٔ «دوره‌های برگزیده» صفحهٔ نخست اپ — چک‌باکس‌های انتخاب محصول.
     * فقط بخش featuredCourseIds بازنویسی می‌شود (الگوی ذخیرهٔ جزئی؛ خواندن آرایهٔ
     * خام آپشن، سوارکردن بخش خودش، ذخیرهٔ کامل) تا متن‌ها/سکشن‌ها/منو دست نخورند.
     */
    public function handle_app_ui_save_featured() {
        check_admin_referer('mrdastbaaf_app_ui_save_featured', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $posted = (isset($_POST['featured']) && is_array($_POST['featured'])) ? wp_unslash($_POST['featured']) : array();
        $ids = array();
        foreach ($posted as $pid) {
            $pid = absint($pid);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
        $ids = array_values(array_unique($ids));

        $stored = MrDastbaaf_App_UI_Controller::get_stored();
        $stored['featuredCourseIds'] = $ids;
        MrDastbaaf_App_UI_Controller::save($stored);

        $this->flash('success', 'دوره‌های برگزیده ذخیره شد؛ سکشن «برگزیده» در اپ از همین لحظه تازه می‌شود.');
        $this->redirect_to_page(self::APP_UI_SLUG);
    }

    /**
     * ذخیرهٔ منوی وب‌اپ — داک (برچسب + نمایش) و راهنماها (برچسب + نمایش)؛
     * ترتیب راهنماها از وضعیت فعلی حفظ می‌شود.
     */
    public function handle_app_ui_save_menu() {
        check_admin_referer('mrdastbaaf_app_ui_save_menu', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $posted_dock = (isset($_POST['dock']) && is_array($_POST['dock'])) ? wp_unslash($_POST['dock']) : array();
        $posted_guides = (isset($_POST['guides']) && is_array($_POST['guides'])) ? wp_unslash($_POST['guides']) : array();
        $current = MrDastbaaf_App_UI_Controller::get_app_ui();

        $dock = array();
        foreach ($current['menu']['dock'] as $item) {
            $key = (string) $item['key'];
            $entry = array(
                'key'     => $key,
                'label'   => (string) $item['label'],
                'enabled' => !empty($item['enabled']),
            );
            if (isset($posted_dock[$key]) && is_array($posted_dock[$key])) {
                if (isset($posted_dock[$key]['label'])) {
                    $entry['label'] = sanitize_text_field($posted_dock[$key]['label']);
                }
                $entry['enabled'] = !empty($posted_dock[$key]['enabled']);
            }
            $dock[] = $entry;
        }

        $guides = array();
        foreach ($current['menu']['guides'] as $item) {
            $key = (string) $item['key'];
            $entry = array(
                'key'       => $key,
                'label'     => (string) $item['label'],
                'enabled'   => !empty($item['enabled']),
                'sortOrder' => (int) $item['sortOrder'],
            );
            if (isset($posted_guides[$key]) && is_array($posted_guides[$key])) {
                if (isset($posted_guides[$key]['label'])) {
                    $entry['label'] = sanitize_text_field($posted_guides[$key]['label']);
                }
                $entry['enabled'] = !empty($posted_guides[$key]['enabled']);
            }
            $guides[] = $entry;
        }

        $stored = MrDastbaaf_App_UI_Controller::get_stored();
        if (!isset($stored['menu']) || !is_array($stored['menu'])) {
            $stored['menu'] = array();
        }
        $stored['menu']['dock'] = $dock;
        $stored['menu']['guides'] = $guides;
        MrDastbaaf_App_UI_Controller::save($stored);

        $this->flash('success', 'منوی وب‌اپ ذخیره شد.');
        $this->redirect_to_page(self::APP_UI_SLUG);
    }

    /**
     * جابه‌جایی ترتیب سکشن/راهنما (↑/↓) — sortOrder با همسایه در فهرست مرتب‌شده
     * عوض می‌شود (همان الگوی handle_app_page_move)؛ scope = sections|guides.
     */
    public function handle_app_ui_move() {
        check_admin_referer('mrdastbaaf_app_ui_move', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $scope = isset($_POST['scope']) ? sanitize_key(wp_unslash($_POST['scope'])) : '';
        $key = isset($_POST['key']) ? $this->sanitize_item_key(wp_unslash($_POST['key'])) : '';
        $dir = isset($_POST['dir']) ? sanitize_key(wp_unslash($_POST['dir'])) : 'up';

        if ('' === $key || !in_array($scope, array('sections', 'guides'), true) || !in_array($dir, array('up', 'down'), true)) {
            $this->flash('error', 'درخواست جابه‌جایی ترتیب نامعتبر بود.');
            $this->redirect_to_page(self::APP_UI_SLUG);
        }

        $ui = MrDastbaaf_App_UI_Controller::get_app_ui();
        $list = ('guides' === $scope) ? $ui['menu']['guides'] : $ui['sections'];

        $index = null;
        foreach ($list as $i => $entry) {
            if ((string) $entry['key'] === $key) {
                $index = $i;
                break;
            }
        }

        if (null !== $index) {
            $swap_with = ('up' === $dir) ? $index - 1 : $index + 1;
            if ($swap_with >= 0 && $swap_with < count($list)) {
                $tmp = (int) $list[$index]['sortOrder'];
                $list[$index]['sortOrder'] = (int) $list[$swap_with]['sortOrder'];
                $list[$swap_with]['sortOrder'] = $tmp;

                $stored = MrDastbaaf_App_UI_Controller::get_stored();
                if ('guides' === $scope) {
                    if (!isset($stored['menu']) || !is_array($stored['menu'])) {
                        $stored['menu'] = array();
                    }
                    $stored['menu']['guides'] = $list;
                } else {
                    $stored['sections'] = $list;
                }
                MrDastbaaf_App_UI_Controller::save($stored);
                $this->flash('success', 'ترتیب به‌روزرسانی شد.');
            }
        }

        $this->redirect_to_page(self::APP_UI_SLUG);
    }

    /* ─────────────────────── صفحهٔ «اتصال خرید سایت» (v1.3.4) ─────────────────────── */

    /**
     * صفحهٔ «اتصال خرید سایت» — توضیح فارسی قابلیت + جدول همهٔ پلن‌ها با
     * انتخابگر محصول ووکامرس (منتشرشده، حداکثر ۲۰۰ محصول بر اساس عنوان) که
     * مقدار فعلی نگاشت (آپشن mrdastbaaf_woo_map) روی آن پیش‌انتخاب شده است.
     * در نبود ووکامرس فقط اعلان نمایش داده می‌شود.
     */
    public function render_woo_map_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $flash = $this->pull_flash();
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">🛒</div>
                <div>
                    <h1 class="mrd-hero__title">اتصال خرید سایت</h1>
                    <p class="mrd-hero__desc">هر پلنِ عضویت را به یک محصول ووکامرس نگاشت کنید؛ وقتی سفارشِ آن محصول به وضعیت «در حال پردازش» یا «تکمیل‌شده» برسد، خریدار به‌صورت خودکار در وب‌اپ مشترک می‌شود — با همان هستهٔ فعال‌سازی/تمدید فیش‌ها (سطح-آگاه: تمدید، ارتقا یا رد سطح پایین‌تر) و دفتر ایدمپوتنسی؛ پس اجرای دوبارهٔ همان سفارش هرگز تمدید دوم نمی‌سازد. خریدارِ مهمان با شمارهٔ صورتحساب شناسایی می‌شود؛ اگر کاربری با آن شماره پیدا نشود، سفارش بی‌اعمال رها شده و در لاگ سرور ثبت می‌گردد.</p>
                </div>
            </div>

            <?php $this->render_flash_notice($flash); ?>

            <?php if (!class_exists('WooCommerce')): ?>
                <div class="notice notice-error"><p>ووکامرس فعال نیست؛ ابتدا افزونهٔ ووکامرس را نصب و فعال کنید تا فهرست محصول‌ها برای نگاشت نمایش داده شود. تا آن زمان، پرداخت موفق سایت هیچ عضویتی در اپ نمی‌سازد.</p></div>
            <?php else:
                global $wpdb;
                $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
                $plans = $wpdb->get_results("SELECT * FROM $table_plans ORDER BY price_toman ASC, created_at ASC");
                $map = MrDastbaaf_Woo_Bridge_Controller::get_map();

                // فقط محصولات منتشرشده — حداکثر ۲۰۰ مورد بر اساس عنوان (بدون کش
                // اضافه؛ این صفحه فقط برای ادمین و به‌ندرت باز می‌شود)
                $products_query = new WP_Query(array(
                    'post_type'              => 'product',
                    'post_status'            => 'publish',
                    'posts_per_page'         => 200,
                    'orderby'                => 'title',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'update_post_term_cache' => false,
                    'update_post_meta_cache' => false,
                ));
                ?>
                <div class="mrd-card">
                <h2 class="mrd-card__title">نگاشت پلن ← محصول ووکامرس</h2>
                <?php if (empty($plans)): ?>
                    <p class="mrd-empty">هنوز پلنی در صفحهٔ «پلن‌های عضویت» ثبت نشده است؛ ابتدا پلن‌ها را بسازید، سپس این‌جا به محصول وصل کنید.</p>
                <?php elseif (!$products_query->have_posts()): ?>
                    <p class="mrd-note mrd-note--warn">هیچ محصول منتشرشده‌ای در ووکامرس یافت نشد؛ برای هر پلن یک محصول بسازید، منتشر کنید و سپس این‌جا نگاشت را ثبت کنید.</p>
                <?php else: ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="mrdastbaaf_woo_map_save" />
                    <?php wp_nonce_field('mrdastbaaf_woo_map_save', 'mrd_nonce'); ?>
                    <div class="mrd-table-wrap">
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>پلن عضویت</th>
                                <th>سطح</th>
                                <th>قیمت (تومان)</th>
                                <th>مدت</th>
                                <th>محصول ووکامرس</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plans as $plan): $plan_id = (string) $plan->id; $selected = isset($map[$plan_id]) ? (int) $map[$plan_id] : 0; ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($plan->title); ?></strong><br />
                                        <code dir="ltr"><?php echo esc_html($plan_id); ?></code>
                                        <?php if (empty($plan->is_active)): ?><br /><span class="mrd-pill mrd-pill--off">پلن غیرفعال</span><?php endif; ?>
                                    </td>
                                    <td><span class="mrd-pill mrd-pill--<?php echo esc_attr((string) $plan->tier); ?>"><?php echo esc_html($this->tier_label($plan->tier)); ?></span></td>
                                    <td><?php echo esc_html(number_format_i18n((int) $plan->price_toman)); ?></td>
                                    <td><?php echo esc_html((string) $plan->duration_label); ?></td>
                                    <td>
                                        <select name="map[<?php echo esc_attr($plan_id); ?>]">
                                            <option value="0" <?php selected($selected, 0); ?>>— بدون اتصال —</option>
                                            <?php foreach ($products_query->posts as $product): ?>
                                                <option value="<?php echo esc_attr((string) $product->ID); ?>" <?php selected($selected, (int) $product->ID); ?>><?php echo esc_html(get_the_title($product) . ' (#' . (int) $product->ID . ')'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <p class="mrd-hint">تغییر نگاشت روی خریدهای «جدید» اثر می‌گذارد؛ سفارش‌هایی که قبلاً اعمال شده‌اند با دفتر ایدمپوتنسی دوباره اعمال نمی‌شوند.</p>
                    <?php submit_button('ذخیرهٔ نگاشت'); ?>
                </form>
                <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * ذخیرهٔ نگاشت پلن → محصول — هر ورودی absint می‌شود؛ شناسهٔ پلن با الگوی
     * سفید کاراکتری اعتبارسنجی می‌شود؛ انتخاب «بدون اتصال» (۰) یعنی حذف نگاشت.
     */
    public function handle_woo_map_save() {
        check_admin_referer('mrdastbaaf_woo_map_save', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $raw_map = (isset($_POST['map']) && is_array($_POST['map'])) ? wp_unslash($_POST['map']) : array();
        $map = array();
        foreach ($raw_map as $raw_plan_id => $raw_product_id) {
            $plan_id = $this->sanitize_plan_id((string) $raw_plan_id);
            $product_id = absint($raw_product_id);
            if ('' !== $plan_id && $product_id > 0) {
                $map[$plan_id] = $product_id;
            }
        }

        MrDastbaaf_Woo_Bridge_Controller::save_map($map);

        if (empty($map)) {
            $this->flash('success', 'نگاشت ذخیره شد — همهٔ پلن‌ها بدون اتصال هستند و خرید سایت عضویتی نمی‌سازد.');
        } else {
            $this->flash('success', 'نگاشت پلن‌ها به محصولات ذخیره شد؛ خریدهای جدید از همین لحظه عضویت می‌سازند.');
        }
        $this->redirect_to_page(self::WOO_MAP_SLUG);
    }

    /* ───────────────────── صفحهٔ «محصولات فیزیکی» (v1.4.0؛ v1.5.0: ویژه) ───────────────────── */

    /**
     * صفحهٔ «محصولات فیزیکی» — دراپ‌داون دسته (پارامتر GET cat) + گرید واکنش‌گرای
     * چک‌باکس محصولات منتشرشدهٔ همان دسته (تصویر/عنوان/قیمت/دسته‌ها) با پیش‌انتخاب از
     * آپشن‌های مشترک mrdastbaaf_physical_products و mrdastbaaf_featured_products
     * (کنترلر فروشگاه؛ v1.5.0: فیلدست دوم «محصولات ویژه» برای اسلایدر وب‌اپ).
     * تیک‌خورده‌های فیزیکی در بخش «محصولات» وب‌اپ (GET /shop/products) و
     * تیک‌خورده‌های ویژه در اسلایدر افقی «محصولات ویژه» بالای فروشگاه نمایش داده می‌شوند.
     */
    public function render_physical_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $flash = $this->pull_flash();
        $selected_cat = isset($_GET['cat']) ? absint($_GET['cat']) : 0; // GET — فقط شناسهٔ عددی دسته
        $physical_ids = MrDastbaaf_Shop_Controller::get_physical_product_ids();
        // v1.5.0: پیش‌انتخاب محصولات ویژه (اسلایدر وب‌اپ)
        $featured_ids = MrDastbaaf_Shop_Controller::get_featured_product_ids();
        $admin_post = admin_url('admin-post.php');
        ?>
        <div class="wrap mrd-scope">
            <div class="mrd-hero">
                <div class="mrd-hero__icon" aria-hidden="true">🧶</div>
                <div>
                    <h1 class="mrd-hero__title">محصولات فیزیکی</h1>
                    <p class="mrd-hero__desc">محصولات دست‌سازِ آمادهٔ ارسال را انتخاب کنید تا در بخش «محصولات» وب‌اپلیکیشن (اندپوینت <code dir="ltr">GET /shop/products</code>) سرو شوند؛ از همین صفحه فهرست «محصولات ویژه» هم انتخاب می‌شود. <strong>از نسخهٔ ۱.۶.۰ وب‌اپ فقط «محصولات ویژه» را نمایش می‌دهد</strong> (با فیلتر دسته‌بندی و خرید درون‌اپ) — پس حتماً تیک ستون «ویژه» را برای محصولاتی که در اپ دیده می‌شوند بزنید. نام، قیمت و تصویر هر محصول از خود ووکامرس خوانده می‌شود؛ برداشتن تیک و ذخیره، محصول را از اپ حذف می‌کند.</p>
                </div>
            </div>

            <?php $this->render_flash_notice($flash); ?>

            <?php if (!class_exists('WooCommerce')): ?>
                <div class="notice notice-error"><p>ووکامرس فعال نیست؛ ابتدا افزونهٔ ووکامرس را نصب و فعال کنید تا فهرست محصول‌ها نمایش داده شود.</p></div>
            <?php else:
                // محصولات منتشرشده (حداکثر ۲۰۰ بر اساس عنوان — همان قرارداد صفحات دیگر)
                $query_args = array(
                    'post_type'              => 'product',
                    'post_status'            => 'publish',
                    'posts_per_page'         => 200,
                    'orderby'                => 'title',
                    'order'                  => 'ASC',
                    'no_found_rows'          => true,
                    'ignore_sticky_posts'    => true,
                );
                if ($selected_cat > 0) {
                    $query_args['tax_query'] = array(array(
                        'taxonomy' => 'product_cat',
                        'field'    => 'term_id',
                        'terms'    => $selected_cat,
                    ));
                }
                $products_query = new WP_Query($query_args);

                // دسته‌های دارای محصول منتشرشده (از همان فهرست ۲۰۰تایی) — برای دراپ‌داون
                $cat_options = array();
                $product_terms_cache = array();
                foreach ($products_query->posts as $product_post) {
                    $pid = (int) $product_post->ID;
                    $terms = get_the_terms($pid, 'product_cat');
                    $product_terms_cache[$pid] = is_array($terms) ? $terms : array();
                    foreach ($product_terms_cache[$pid] as $term) {
                        $cat_options[(int) $term->term_id] = (string) $term->name;
                    }
                }
                uasort($cat_options, 'strcmp');
                ?>
                <div class="mrd-card">
                <h2 class="mrd-card__title">فیلتر دسته و انتخاب محصولات</h2>
                <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>" style="margin:6px 0 4px;">
                    <input type="hidden" name="page" value="<?php echo esc_attr(self::PHYSICAL_SLUG); ?>" />
                    <select name="cat">
                        <option value="0" <?php selected($selected_cat, 0); ?>>— همهٔ دسته‌ها —</option>
                        <?php foreach ($cat_options as $cat_id => $cat_name): ?>
                            <option value="<?php echo esc_attr((string) $cat_id); ?>" <?php selected($selected_cat, (int) $cat_id); ?>><?php echo esc_html($cat_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="button">نمایش</button>
                </form>
                <?php if (empty($products_query->posts)): ?>
                    <p class="mrd-empty">هیچ محصول منتشرشده‌ای <?php echo $selected_cat > 0 ? 'در این دسته' : 'در ووکامرس'; ?> یافت نشد.</p>
                <?php else: ?>
                <form method="post" action="<?php echo esc_url($admin_post); ?>">
                    <input type="hidden" name="action" value="mrdastbaaf_physical_save" />
                    <input type="hidden" name="cat" value="<?php echo esc_attr((string) $selected_cat); ?>" />
                    <?php wp_nonce_field('mrdastbaaf_physical_save', 'mrd_nonce'); ?>
                    <h3 style="margin:16px 0 8px;">محصولات فیزیکی</h3>
                    <?php $this->render_product_checkbox_grid('physical', $physical_ids, $products_query->posts, $product_terms_cache); ?>
                    <p class="mrd-hint">با هر ذخیره، تیک‌های همین فهرست اعمال و تیک‌های دسته‌های دیگر حفظ می‌شود — برداشتن تیک در دستهٔ جاری و ذخیره، همان محصول را از اپ حذف می‌کند.</p>

                    <hr style="margin:18px 0;border:none;border-top:1px solid #e2e4e7;" />

                    <h3 style="margin:0 0 4px;">محصولات ویژه (اسلایدر وب‌اپ)</h3>
                    <p class="mrd-hint">این محصولات در اسلایدر افقی «محصولات ویژه» بالای فروشگاه وب‌اپ نمایش داده می‌شوند.</p>
                    <?php $this->render_product_checkbox_grid('featured', $featured_ids, $products_query->posts, $product_terms_cache); ?>
                    <p class="mrd-hint">ذخیرهٔ این فهرست هم با همان قاعدهٔ ادغام انجام می‌شود — مستقل از فهرست فیزیکی.</p>
                    <?php submit_button('ذخیرهٔ محصولات'); ?>
                </form>
                <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * v1.5.0: ذخیرهٔ «محصولات فیزیکی» و «محصولات ویژه» (هر دو در یک فرم) —
     * تیک‌های همهٔ دسته‌ها در هر لیست به‌طور مستقل ادغام می‌شوند: شناسه‌های
     * نمایانِ همین فرم (همان پرس‌وجوی صفحه) از انتخاب فعلی کنار گذاشته و به‌جایشان
     * تیک‌های ارسالی می‌نشینند؛ به این ترتیب هم تیکِ دسته‌های دیگر از دست نمی‌رود
     * و هم برداشتن تیک در دستهٔ جاری واقعاً حذف می‌کند. خروجی هر لیست: int[] یکتا در
     * آپشن mrdastbaaf_physical_products / mrdastbaaf_featured_products (بدون autoload).
     */
    public function handle_physical_save() {
        check_admin_referer('mrdastbaaf_physical_save', 'mrd_nonce');
        if (!current_user_can('manage_options')) {
            wp_die('شما مجوز انجام این عملیات را ندارید.');
        }

        $cat = isset($_POST['cat']) ? absint($_POST['cat']) : 0;
        $visible_ids = $this->get_physical_visible_product_ids($cat);

        $existing_physical = MrDastbaaf_Shop_Controller::get_physical_product_ids();
        $physical_merged = array_values(array_unique(array_merge(array_diff($existing_physical, $visible_ids), $this->collect_ticked_product_ids('physical'))));
        update_option(MrDastbaaf_Shop_Controller::PHYSICAL_PRODUCTS_OPTION, $physical_merged, false);

        // v1.5.0: محصولات ویژه — همان الگوی ادغام، کاملاً مستقل از لیست فیزیکی
        $existing_featured = MrDastbaaf_Shop_Controller::get_featured_product_ids();
        $featured_merged = array_values(array_unique(array_merge(array_diff($existing_featured, $visible_ids), $this->collect_ticked_product_ids('featured'))));
        update_option(MrDastbaaf_Shop_Controller::FEATURED_PRODUCTS_OPTION, $featured_merged, false);

        if (empty($physical_merged) && empty($featured_merged)) {
            $this->flash('success', 'ذخیره شد — هیچ محصولی انتخاب نشده است؛ بخش «محصولات» و اسلایدر «محصولات ویژه» در اپ خالی می‌ماند.');
        } else {
            $this->flash('success', 'ذخیره شد — محصولات فیزیکی و ویژه به‌روز شدند؛ فروشگاه و اسلایدر اپ از همین لحظه تازه می‌شوند.');
        }
        $this->redirect_to_page(self::PHYSICAL_SLUG, $cat > 0 ? array('cat' => $cat) : array());
    }

    /**
     * v1.5.0: جمع‌آوری شناسه‌های عددی تیک‌خورده از POST (physical[] یا featured[])
     * — همان پاکسازی قبلی، حالا مشترک برای هر دو لیست.
     *
     * @param string $post_key نام فیلد POST
     * @return int[]
     */
    private function collect_ticked_product_ids($post_key) {
        $ticked = array();
        $posted = (isset($_POST[$post_key]) && is_array($_POST[$post_key])) ? wp_unslash($_POST[$post_key]) : array();
        foreach ($posted as $pid) {
            $pid = absint($pid);
            if ($pid > 0) {
                $ticked[] = $pid;
            }
        }
        return array_values(array_unique($ticked));
    }

    /**
     * v1.5.0: گرید واکنش‌گرای چک‌باکس محصولات (مشترک بین «فیزیکی» و «ویژه») —
     * جای جدول تک‌ردیفی قبلی: کارت هر محصول تصویر/عنوان/قیمت/دسته‌ها دارد و
     * چک‌باکسش با نام POST خواسته‌شده رندر می‌شود (استایل گرید در admin-ui.css: mrd-check-grid).
     *
     * @param string $post_key            نام فیلد POST (physical/featured)
     * @param int[]  $selected_ids        شناسه‌های پیش‌انتخاب (آپشن فعلی)
     * @param array  $posts               ردیف‌های WP_Query (محصولات منتشرشدهٔ نمایان)
     * @param array  $product_terms_cache نگاشت pid → دسته‌ها (محاسبه‌شده در رندر صفحه)
     * @return void
     */
    private function render_product_checkbox_grid($post_key, $selected_ids, $posts, $product_terms_cache) {
        ?>
        <div class="mrd-check-grid">
            <?php foreach ($posts as $product_post): $pid = (int) $product_post->ID;
                $thumb = get_the_post_thumbnail_url($pid, 'thumbnail');
                $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
                $price = ($product && '' !== $product->get_price() && null !== $product->get_price()) ? (int) $product->get_price() : 0;
                $term_names = wp_list_pluck(isset($product_terms_cache[$pid]) ? $product_terms_cache[$pid] : array(), 'name');
                $meta_bits = array();
                if ($price > 0) {
                    $meta_bits[] = esc_html(number_format_i18n($price)) . ' تومان';
                }
                if (!empty($term_names)) {
                    $meta_bits[] = esc_html(implode('، ', $term_names));
                }
            ?>
                <label class="mrd-check-item">
                    <input type="checkbox" name="<?php echo esc_attr($post_key); ?>[]" value="<?php echo esc_attr((string) $pid); ?>" <?php checked(in_array($pid, $selected_ids, true)); ?> />
                    <?php if (is_string($thumb) && '' !== $thumb): ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;flex:none;" />
                    <?php endif; ?>
                    <span style="display:block;min-width:0;">
                        <strong><?php echo esc_html($product_post->post_title); ?></strong> <code dir="ltr">#<?php echo esc_html((string) $pid); ?></code>
                        <?php if (!empty($meta_bits)): ?><br /><span class="mrd-muted"><?php echo implode(' · ', $meta_bits); ?></span><?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * v1.4.0: شناسه‌های محصول نمایان در فرم «محصولات فیزیکی» (همان پرس‌وجوی
     * صفحهٔ رندر با فیلتر دسته) — مبنای ادغام بین‌دسته‌ای در handle_physical_save.
     *
     * @param int $cat شناسهٔ دسته (۰ = همهٔ دسته‌ها)
     * @return int[]
     */
    private function get_physical_visible_product_ids($cat) {
        $query_args = array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => 200,
            'orderby'             => 'title',
            'order'               => 'ASC',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'fields'              => 'ids',
        );
        if ($cat > 0) {
            $query_args['tax_query'] = array(array(
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => (int) $cat,
            ));
        }
        $query = new WP_Query($query_args);

        $ids = array();
        foreach ($query->posts as $pid) {
            $ids[] = (int) $pid;
        }
        return $ids;
    }

    /**
     * پاکسازی کلید آیتم‌های وب‌اپ (sections/guides/dock) — فقط a-z0-9_- مجاز
     * (کلیدهای قراردادی همیشه همین الگو هستند)
     */
    private function sanitize_item_key($raw) {
        $clean = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim((string) $raw)));
        return is_string($clean) ? $clean : '';
    }

    /**
     * اعتبارسنجی شناسهٔ پلن از کلیدهای آرایهٔ POST — الگوی تولید شناسهٔ پلن
     * {tier}_{random} با کاراکترهای [A-Za-z0-9_-] و حداکثر ۶۴ کاراکتر (varchar(64))
     */
    private function sanitize_plan_id($raw) {
        $raw = trim((string) $raw);
        if ('' === $raw || !preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $raw)) {
            return '';
        }
        return $raw;
    }
}
