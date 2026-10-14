<?php
/**
 * کنترلر «محتوای وب‌اپ» (v1.3.4 — فایل NEW)
 *
 * متن‌های بنر اصلی (Hero)، سکشن‌های صفحهٔ اصلی و آیتم‌های منوی وب‌اپلیکیشن از
 * پیشخوان وردپرس مدیریت می‌شوند (صفحهٔ NEW «محتوای وب‌اپ» در کنترلر صفحات ادمین)
 * و از همین‌جا با REST سرو می‌شوند. نوشتن فقط از admin-post.php انجام می‌شود
 * (همان الگوی پلن‌ها/صفحات وب‌اپ)؛ این کنترلر فقط‌خواندنی است.
 *
 * ذخیره‌سازی: یک آپشن «mrdastbaaf_app_ui» (آرایهٔ PHP) + آپشن زمان‌سنج
 * «mrdastbaaf_app_ui_updated_at» فقط برای نمایش «آخرین به‌روزرسانی» در پیشخوان.
 * هیچ تغییری در اسکیمای دیتابیس — اسکیما ۱.۳.۰ ثابت می‌ماند.
 *
 * کش: پاسخ‌های namespace mrdastbaaf/v1 از v1.3.1 همیشه Cache-Control: no-store
 * می‌گیرند (فیلتر rest_pre_serve_request در فایل اصلی افزونه)؛ بنابراین هیچ
 * باطل‌سازی کش اضافه‌ای در این کنترلر لازم نیست و انجام هم نمی‌شود.
 *
 * قرارداد پاسخ (PWA دقیقاً با همین شکل کدنویسی می‌شود — کلیدهای camelCase):
 *   { hero: {...}, sections: [ {key,title,subtitle,enabled,sortOrder}, … ],
 *     menu: { dock: [ {key,label,enabled}, … ], guides: [ {key,label,enabled,sortOrder}, … ] } }
 *
 * ادغام (Merge): مقدار ذخیره‌شده روی پیش‌فرض‌ها سوار می‌شود — کلیدِ جاافتاده از
 * پیش‌فرض می‌آید (سازگار رو به جلو: افزودن فیلد/سکشن/آیتم منو در نسخه‌های بعدی
 * بدون مهاجرت داده کار می‌کند)؛ کلیدهای ناشناختهٔ ذخیره‌شده هم حفظ می‌شوند.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_App_UI_Controller {

    const OPTION_KEY = 'mrdastbaaf_app_ui';
    const UPDATED_AT_OPTION = 'mrdastbaaf_app_ui_updated_at';

    /**
     * آرایهٔ خام ذخیره‌شده در آپشن — برای «ذخیرهٔ جزئی» از پیشخوان (مثلاً فقط
     * متن‌های Hero بدون دست‌زدن به سکشن‌ها/منو) لازم است؛ هندلرهای admin_post
     * این آرایه را می‌خوانند، بخش خودشان را روی آن سوار می‌کنند و با save()
     * کامل برمی‌گردانند تا هیچ بخشی از دادهٔ ذخیره‌شده گم نشود.
     *
     * @return array
     */
    public static function get_stored() {
        $stored = get_option(self::OPTION_KEY, array());
        return is_array($stored) ? $stored : array();
    }

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/app-ui', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_app_ui'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * GET /app-ui — محتوای کامل UI وب‌اپ (عمومی؛ بدون هیچ دادهٔ کاربری)
     */
    public function handle_get_app_ui() {
        return rest_ensure_response(self::get_app_ui());
    }

    /**
     * پیش‌فرض‌های کامل محتوای وب‌اپ — کلیدها دقیقاً همان قرارداد REST (camelCase).
     *
     * نکتهٔ sortOrder سکشن‌ها: صفحهٔ اصلی اپ ردیف ۱ را برای بنر Hero نگه می‌دارد؛
     * سکشن‌های محتوایی از ترتیب ۲ شروع می‌شوند (مطابق قرارداد PWA).
     *
     * @return array
     */
    public static function get_defaults() {
        return array(
            'hero' => array(
                'badgeText'        => 'پلتفرم تخصصی آموزش صنایع دستی',
                'headline'         => 'هنر بافندگی و اصالت ایرانی را با دستان خود زنده کنید',
                'subheadline'      => 'مجموعه مسترکلاس‌های تصویری قالی‌بافی، گلیم، پته و منسوجات دستباف، همراه با هدایت گام‌به‌گام استادکاران نام‌آور',
                'ctaPrimaryText'   => 'مشاهده دوره‌ها و شروع یادگیری',
                'ctaSecondaryText' => 'پلن‌های عضویت و اشتراک',
                'stat1Value'       => '+۱,۴۰۰',
                'stat1Label'       => 'هنرجوی فعال در سراسر ایران',
                'stat2Value'       => '۶ دوره',
                'stat2Label'       => 'مسترکلاس ویدیویی گام‌به‌گام',
                'stat3Value'       => '۱۰۰٪ دستی',
                'stat3Label'       => 'تمدید اشتراک بدون کسر خودکار',
            ),
            'sections' => array(
                array(
                    'key'       => 'categories',
                    'title'     => 'دسته‌بندی‌های آموزشی',
                    'subtitle'  => 'رشته‌های متنوع صنایع دستی از دار قالی تا مکرمه مدرن',
                    'enabled'   => true,
                    'sortOrder' => 2,
                ),
                array(
                    'key'       => 'featured_courses',
                    'title'     => 'دوره‌های برگزیده و پیشنهادی اساتید',
                    'subtitle'  => 'دوره‌های پرطرفدار با بالاترین رضایت هنرجویان در ماه‌های اخیر',
                    'enabled'   => true,
                    'sortOrder' => 3,
                ),
                array(
                    'key'       => 'artisan_story',
                    'title'     => 'داستان مستر دستباف: هنر کهن در عصر نو',
                    'subtitle'  => 'روایتی از تار و پود، عشق به هویت و انتقال سینه به سینه دانش اساتید',
                    'enabled'   => true,
                    'sortOrder' => 4,
                ),
                array(
                    'key'       => 'membership_plans',
                    'title'     => 'پلن‌های عضویت و اشتراک دانلودی',
                    'subtitle'  => 'دسترسی نامحدود به ویدیوها با پرداخت ماهانه و سالانه مقرون‌به‌صرفه',
                    'enabled'   => true,
                    'sortOrder' => 5,
                ),
                array(
                    'key'       => 'faq',
                    'title'     => 'سوالات متداول هنرجویان',
                    'subtitle'  => 'پاسخ به سوالات پرتکرار پیرامون شیوه آموزش، خرید اشتراک و ابزار کار',
                    'enabled'   => true,
                    'sortOrder' => 6,
                ),
            ),
            'menu' => array(
                'dock' => array(
                    array('key' => 'home',       'label' => 'خانه',    'enabled' => true),
                    array('key' => 'catalogue',  'label' => 'دوره‌ها', 'enabled' => true),
                    array('key' => 'membership', 'label' => 'عضویت',   'enabled' => true),
                    array('key' => 'dashboard',  'label' => 'داشبورد', 'enabled' => true),
                ),
                'guides' => array(
                    array('key' => 'about-us',         'label' => 'درباره ما',                    'enabled' => true, 'sortOrder' => 1),
                    array('key' => 'guide-membership', 'label' => 'راهنمای عضویت و تمدید دستی',   'enabled' => true, 'sortOrder' => 2),
                    array('key' => 'faq',              'label' => 'سوالات متداول',                'enabled' => true, 'sortOrder' => 3),
                    array('key' => 'membership',       'label' => 'پلن‌های اشتراک',               'enabled' => true, 'sortOrder' => 4),
                ),
            ),
        );
    }

    /**
     * محتوای نهایی وب‌اپ — پیش‌فرض‌ها + مقادیر ذخیره‌شدهٔ پیشخوان (merge پیش‌فروند).
     *
     * hero: هر فیلدِ موجود در آپشن (حتی رشتهٔ خالی — پاک‌کردن عمدی متن) مقدار
     * پیش‌فرض را بازنویسی می‌کند؛ فیلد جاافتاده از پیش‌فرض می‌آید.
     * sections/menu: ادغام بر اساس کلید «key»؛ ترتیب خروجی با sortOrder مرتب می‌شود
     * (سکشن‌ها و راهنماها) یا ترتیب کانونی ثابت (داک). آیتم‌های ناشناختهٔ
     * ذخیره‌شده در انتها اضافه می‌شوند (سازگاری رو به جلو با نسخه‌های بعدی).
     *
     * @return array
     */
    public static function get_app_ui() {
        $defaults = self::get_defaults();
        $stored = get_option(self::OPTION_KEY, array());
        if (!is_array($stored)) {
            $stored = array();
        }

        // ─── hero ───
        $hero = $defaults['hero'];
        if (isset($stored['hero']) && is_array($stored['hero'])) {
            foreach ($hero as $field => $default_value) {
                if (array_key_exists($field, $stored['hero'])) {
                    $hero[$field] = (string) $stored['hero'][$field];
                }
            }
        }

        // ─── sections ───
        $stored_sections = array();
        if (isset($stored['sections']) && is_array($stored['sections'])) {
            foreach ($stored['sections'] as $entry) {
                if (is_array($entry) && isset($entry['key'])) {
                    $stored_sections[(string) $entry['key']] = $entry;
                }
            }
        }
        $sections = array();
        foreach ($defaults['sections'] as $default_section) {
            $key = (string) $default_section['key'];
            if (isset($stored_sections[$key])) {
                $s = $stored_sections[$key];
                $sections[] = array(
                    'key'       => $key,
                    'title'     => isset($s['title']) ? (string) $s['title'] : $default_section['title'],
                    'subtitle'  => isset($s['subtitle']) ? (string) $s['subtitle'] : $default_section['subtitle'],
                    // کلیدِ جاافتاده (حتی داخل یک ردیفِ ذخیره‌شده) از پیش‌فرض می‌آید
                    'enabled'   => isset($s['enabled']) ? !empty($s['enabled']) : $default_section['enabled'],
                    'sortOrder' => isset($s['sortOrder']) ? (int) $s['sortOrder'] : (int) $default_section['sortOrder'],
                );
                unset($stored_sections[$key]);
            } else {
                $sections[] = $default_section;
            }
        }
        // سازگاری رو به جلو: سکشن‌های ذخیره‌شده‌ای که در پیش‌فرض‌ها نیستند حفظ می‌شوند
        foreach ($stored_sections as $key => $s) {
            $sections[] = array(
                'key'       => $key,
                'title'     => isset($s['title']) ? (string) $s['title'] : $key,
                'subtitle'  => isset($s['subtitle']) ? (string) $s['subtitle'] : '',
                'enabled'   => !empty($s['enabled']),
                'sortOrder' => isset($s['sortOrder']) ? (int) $s['sortOrder'] : 99,
            );
        }
        usort($sections, array(__CLASS__, 'sort_by_sort_order'));

        // ─── menu.dock ───
        $stored_dock = array();
        if (isset($stored['menu']['dock']) && is_array($stored['menu']['dock'])) {
            foreach ($stored['menu']['dock'] as $entry) {
                if (is_array($entry) && isset($entry['key'])) {
                    $stored_dock[(string) $entry['key']] = $entry;
                }
            }
        }
        $dock = array();
        foreach ($defaults['menu']['dock'] as $default_item) {
            $key = (string) $default_item['key'];
            if (isset($stored_dock[$key])) {
                $item = $stored_dock[$key];
                $dock[] = array(
                    'key'     => $key,
                    'label'   => isset($item['label']) ? (string) $item['label'] : $default_item['label'],
                    // کلیدِ جاافتاده از پیش‌فرض می‌آید (merge per-key)
                    'enabled' => isset($item['enabled']) ? !empty($item['enabled']) : $default_item['enabled'],
                );
                unset($stored_dock[$key]);
            } else {
                $dock[] = $default_item;
            }
        }
        foreach ($stored_dock as $key => $item) {
            $dock[] = array(
                'key'     => $key,
                'label'   => isset($item['label']) ? (string) $item['label'] : $key,
                'enabled' => !empty($item['enabled']),
            );
        }

        // ─── menu.guides ───
        $stored_guides = array();
        if (isset($stored['menu']['guides']) && is_array($stored['menu']['guides'])) {
            foreach ($stored['menu']['guides'] as $entry) {
                if (is_array($entry) && isset($entry['key'])) {
                    $stored_guides[(string) $entry['key']] = $entry;
                }
            }
        }
        $guides = array();
        foreach ($defaults['menu']['guides'] as $default_item) {
            $key = (string) $default_item['key'];
            if (isset($stored_guides[$key])) {
                $item = $stored_guides[$key];
                $guides[] = array(
                    'key'       => $key,
                    'label'     => isset($item['label']) ? (string) $item['label'] : $default_item['label'],
                    // کلیدِ جاافتاده از پیش‌فرض می‌آید (merge per-key)
                    'enabled'   => isset($item['enabled']) ? !empty($item['enabled']) : $default_item['enabled'],
                    'sortOrder' => isset($item['sortOrder']) ? (int) $item['sortOrder'] : (int) $default_item['sortOrder'],
                );
                unset($stored_guides[$key]);
            } else {
                $guides[] = $default_item;
            }
        }
        foreach ($stored_guides as $key => $item) {
            $guides[] = array(
                'key'       => $key,
                'label'     => isset($item['label']) ? (string) $item['label'] : $key,
                'enabled'   => !empty($item['enabled']),
                'sortOrder' => isset($item['sortOrder']) ? (int) $item['sortOrder'] : 99,
            );
        }
        usort($guides, array(__CLASS__, 'sort_by_sort_order'));

        return array(
            'hero'     => $hero,
            'sections' => $sections,
            'menu'     => array(
                'dock'   => $dock,
                'guides' => $guides,
            ),
        );
    }

    /**
     * مقایسه‌گر ترتیب برای usort
     */
    private static function sort_by_sort_order($a, $b) {
        return ((int) $a['sortOrder'] <=> (int) $b['sortOrder']) ?: strcmp((string) $a['key'], (string) $b['key']);
    }

    /**
     * ذخیرهٔ کامل محتوای وب‌اپ (ورودی از پیشخوان پاکسازی شده است) + مهرِ
     * «آخرین به‌روزرسانی». پاسخ REST ضدکش (no-store) است؛ پس فقط این مهر
     * به‌روز می‌شود و هیچ باطل‌سازی کش دیگری لازم نیست.
     *
     * @param array $ui آرایهٔ کامل (hero/sections/menu)
     * @return bool
     */
    public static function save(array $ui) {
        $clean = array(
            'hero'     => isset($ui['hero']) && is_array($ui['hero']) ? array_map('strval', $ui['hero']) : array(),
            'sections' => array(),
            'menu'     => array(
                'dock'   => array(),
                'guides' => array(),
            ),
        );

        if (isset($ui['sections']) && is_array($ui['sections'])) {
            foreach ($ui['sections'] as $entry) {
                if (!is_array($entry) || !isset($entry['key'])) {
                    continue;
                }
                $clean['sections'][] = array(
                    'key'       => (string) $entry['key'],
                    'title'     => isset($entry['title']) ? (string) $entry['title'] : '',
                    'subtitle'  => isset($entry['subtitle']) ? (string) $entry['subtitle'] : '',
                    'enabled'   => !empty($entry['enabled']),
                    'sortOrder' => isset($entry['sortOrder']) ? (int) $entry['sortOrder'] : 0,
                );
            }
        }

        if (isset($ui['menu']['dock']) && is_array($ui['menu']['dock'])) {
            foreach ($ui['menu']['dock'] as $entry) {
                if (!is_array($entry) || !isset($entry['key'])) {
                    continue;
                }
                $clean['menu']['dock'][] = array(
                    'key'     => (string) $entry['key'],
                    'label'   => isset($entry['label']) ? (string) $entry['label'] : '',
                    'enabled' => !empty($entry['enabled']),
                );
            }
        }

        if (isset($ui['menu']['guides']) && is_array($ui['menu']['guides'])) {
            foreach ($ui['menu']['guides'] as $entry) {
                if (!is_array($entry) || !isset($entry['key'])) {
                    continue;
                }
                $clean['menu']['guides'][] = array(
                    'key'       => (string) $entry['key'],
                    'label'     => isset($entry['label']) ? (string) $entry['label'] : '',
                    'enabled'   => !empty($entry['enabled']),
                    'sortOrder' => isset($entry['sortOrder']) ? (int) $entry['sortOrder'] : 0,
                );
            }
        }

        $saved = update_option(self::OPTION_KEY, $clean, false);
        update_option(self::UPDATED_AT_OPTION, time(), false);
        return $saved;
    }
}
