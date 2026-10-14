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

    public function register() {
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_post_mrdastbaaf_plan_save', array($this, 'handle_plan_save'));
        add_action('admin_post_mrdastbaaf_plan_toggle', array($this, 'handle_plan_toggle'));
        add_action('admin_post_mrdastbaaf_plan_delete', array($this, 'handle_plan_delete'));
        add_action('admin_post_mrdastbaaf_receipt_review', array($this, 'handle_receipt_review'));
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
        $base_sql = "SELECT o.*, t.tracking_code, t.card_last_four, t.deposit_date_jalali, t.sender_notes, t.admin_review_note
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
                        <th>تاریخ ثبت</th>
                        <th>وضعیت</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" class="mrd-empty">فیشی با این فیلتر یافت نشد.</td></tr>
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
}
