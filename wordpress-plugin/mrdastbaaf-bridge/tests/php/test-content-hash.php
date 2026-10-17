<?php
/**
 * تست مستقل content_hash — ریشه‌یابی و اثبات فیکس WSOD پیشخوان (v1.7.14)
 *
 * اجرا: php tests/php/test-content-hash.php
 *
 * چه چیزی آزموده می‌شود (کلاس واقعی افزونه — نه کپی دستی):
 *   ۰) سناریوی قدیمی (کدِ عیناً 1.7.13 به‌صورت fixture): نوشتنِ خودِ
 *      mrdastbaaf_content_hash دوباره هوک updated_option را روشن می‌کرد ⇒
 *      بازگشت بی‌نهایت ⇒ Fatal memory/timeout ⇒ صفحهٔ سفید پس از ذخیرهٔ پست.
 *      اینجا با سقف عمق ۵۰۰ اثبات می‌شود که حلقه «هرگز» خودش متوقف نمی‌شود.
 *   ۱) ذخیرهٔ پست محصول (product) → دقیقاً «یک» نوشتن هش (حتی با روشن‌شدن
 *      updated_optionِ خودِ نوشتن — گارد ضدبازگشت).
 *   ۲) ذخیرهٔ post بلاگ / revision / autosave / auto-draft → صفر نوشتن.
 *   ۳) ذخیرهٔ page → یک نوشتن.
 *   ۴) آپشن‌های عملیاتی (sms_last_log، last_reconcile_log، cron_token،
 *      db_version، pwa_version، options_norm_174) → صفر نوشتن.
 *   ۵) آپشن محتوای اپ (mrdastbaaf_app_ui / mrdastbaaf_plans) → یک نوشتن.
 *   ۶) مسیر added_option هم همان قاعده را دارد (محتوا → یک نوشتن، لاگ → صفر).
 *   ۷) چند bump در یک درخواست (پست + آپشن هم‌زمان) → فقط یک نوشتن.
 *   ۸) خودِ mrdastbaaf_content_hash هرگز نباید دوباره bump کند (ریشهٔ حلقه).
 *   ۹) فیلترهای mrdastbaaf_hash_post_types و mrdastbaaf_hash_exempt_options.
 *
 * شبیه‌ساز وردپرس: معناشناسی دقیق هسته — update_option فقط وقتی «مقدار عوض
 * شده باشد» هوک updated_option را می‌دهد (همان رفتاری که حلقهٔ قدیمی را
 * بی‌پایان می‌کرد چون هش جدید تصادفی همیشه با قبلی فرق داشت).
 */

define('ABSPATH', '/tmp/wp/');

$pass = 0;
$fail = 0;

function mrd_assert($label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "PASS: {$label}\n";
    } else {
        $fail++;
        echo "FAIL: {$label} — انتظار: " . var_export($expected, true) . ' | واقعی: ' . var_export($actual, true) . "\n";
    }
}

/* -------------------------------------------------------------------------
 * شبیه‌ساز مینیمال وردپرس — فقط معناشناسی هوک/آپشن هسته
 * ---------------------------------------------------------------------- */

$GLOBALS['__wp_options']    = array();
$GLOBALS['__wp_hooks']      = array();
$GLOBALS['__wp_filters']    = array();
$GLOBALS['__write_count']   = 0;   // شمارش نوشتن‌های mrdastbaaf_content_hash
$GLOBALS['__revision_ids']  = array();
$GLOBALS['__autosave_ids']  = array();
$GLOBALS['__post_types']    = array();
$GLOBALS['__hook_depth']    = 0;

class WP_Post {
    public $ID;
    public $post_type;
    public $post_status;
    public function __construct($id, $type, $status = 'publish') {
        $this->ID = $id;
        $this->post_type = $type;
        $this->post_status = $status;
    }
}

function add_action($tag, $cb, $priority = 10, $accepted = 1) {
    $GLOBALS['__wp_hooks'][$tag][] = array($cb, $priority, $accepted);
}
function add_filter($tag, $cb, $priority = 10, $accepted = 1) {
    $GLOBALS['__wp_filters'][$tag][] = array($cb, $priority, $accepted);
}
function apply_filters($tag, $value) {
    $args = array_slice(func_get_args(), 1);
    if (!empty($GLOBALS['__wp_filters'][$tag])) {
        $list = $GLOBALS['__wp_filters'][$tag];
        usort($list, function ($a, $b) { return $a[1] <=> $b[1]; });
        foreach ($list as $entry) {
            list($cb, , $accepted) = $entry;
            $value = call_user_func_array($cb, array_slice($args, 0, $accepted));
            $args[0] = $value;
        }
    }
    return $value;
}
function do_action($tag) {
    $args = array_slice(func_get_args(), 1);
    if (empty($GLOBALS['__wp_hooks'][$tag])) {
        return;
    }
    $list = $GLOBALS['__wp_hooks'][$tag];
    usort($list, function ($a, $b) { return $a[1] <=> $b[1]; });
    foreach ($list as $entry) {
        list($cb, , $accepted) = $entry;

        // سقف عمق هوک — فقط برای اثبات بازگشت بی‌پایانِ کد قدیمی (جلوگیری از هنگ تست)
        if (++$GLOBALS['__hook_depth'] > 500) {
            $GLOBALS['__hook_depth']--;
            throw new RuntimeException('HOOK_DEPTH_OVERFLOW (بازگشت بی‌نهایت اثبات شد)');
        }
        try {
            call_user_func_array($cb, array_slice($args, 0, $accepted));
        } finally {
            $GLOBALS['__hook_depth']--;
        }
    }
}
function get_option($option, $default = false) {
    return array_key_exists($option, $GLOBALS['__wp_options']) ? $GLOBALS['__wp_options'][$option] : $default;
}
function add_option($option, $value, $deprecated = '', $autoload = null) {
    if (array_key_exists($option, $GLOBALS['__wp_options'])) {
        return false;
    }
    $GLOBALS['__wp_options'][$option] = $value;
    if ('mrdastbaaf_content_hash' === $option) {
        $GLOBALS['__write_count']++; // نوشتن اول هم «تغییر هش» است
    }
    do_action('added_option', $option, $value, $autoload);
    return true;
}
function update_option($option, $value, $autoload = null) {
    if (!array_key_exists($option, $GLOBALS['__wp_options'])) {
        return add_option($option, $value, '', $autoload);
    }
    $old = $GLOBALS['__wp_options'][$option];
    if ($value === $old) {
        return false; // هستهٔ وردپرس: مقدار تغیری نکرده — هیچ هوکی
    }
    $GLOBALS['__wp_options'][$option] = $value;
    if ('mrdastbaaf_content_hash' === $option) {
        $GLOBALS['__write_count']++;
    }
    do_action('update_option_' . $option, $old, $value, $option);
    do_action('updated_option', $option, $old, $value);
    return true;
}
function wp_generate_password($length = 12, $special = true, $extra = false) {
    return substr(bin2hex(random_bytes(max(1, (int) ceil($length / 2)))), 0, $length);
}
function wp_is_post_revision($id)  { return in_array($id, $GLOBALS['__revision_ids'], true) ? 123 : false; }
function wp_is_post_autosave($id)  { return in_array($id, $GLOBALS['__autosave_ids'], true) ? 456 : false; }
function get_post_type($id)        { return isset($GLOBALS['__post_types'][$id]) ? $GLOBALS['__post_types'][$id] : ''; }
function plugin_dir_path($f)       { return rtrim(dirname($f), '/\\') . '/'; }
function plugin_dir_url($f)        { return 'file://' . rtrim(dirname($f), '/\\') . '/'; }
function register_activation_hook($f, $cb) {}
function register_deactivation_hook($f, $cb) {}

function mrd_reset_request_state($plugin) {
    $r = new ReflectionClass($plugin);
    $r->setStaticPropertyValue('regenerating_hash', false);
    $r->setStaticPropertyValue('content_hash_written', false);
    $GLOBALS['__write_count'] = 0;
}

/* -------------------------------------------------------------------------
 * بارگذاری افزونهٔ واقعی (کل فایل اصلی + کنترلرها)
 * ---------------------------------------------------------------------- */

require __DIR__ . '/../../mrdastbaaf-bridge.php';

$plugin = (new ReflectionClass('MrDastbaaf_Bridge_Plugin'))->newInstanceWithoutConstructor();

// ثبت هوک‌ها دقیقاً مثل خود افزونه (سازنده صدا زده نشده چون منوی پیشخوان/REST لازم نیست)
add_action('save_post', array($plugin, 'bump_content_hash'), 10, 3);
add_action('updated_option', array($plugin, 'bump_content_hash_on_option_update'), 10, 3);
add_action('added_option', array($plugin, 'bump_content_hash_on_option_add'), 10, 2);

echo "— نسخهٔ افزونهٔ بارگذاری‌شده: " . MRDASTBAAF_BRIDGE_VERSION . " —\n\n";

/* -------------------------------------------------------------------------
 * ۰) اثبات باگ قدیمی — کدِ عیناً 1.7.13 (fixture) باید تا سقف ۵۰۰ بازگشت برود
 * ---------------------------------------------------------------------- */

class Legacy_Hash_173 {
    // عیناً بدنهٔ 1.7.13 (ثابت‌شده در گیت: 70a04b1) — برای اثبات ریشهٔ WSOD
    public function bump_content_hash($post_id, $post, $update) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        $this->regenerate_content_hash();
    }
    public function bump_content_hash_on_option_update($option_name, $old_value, $value) {
        if (strpos($option_name, 'mrdastbaaf_') === 0) {
            $this->regenerate_content_hash();
        }
    }
    public function bump_content_hash_on_option_add($option_name, $value) {
        if (strpos($option_name, 'mrdastbaaf_') === 0) {
            $this->regenerate_content_hash();
        }
    }
    private function regenerate_content_hash() {
        $hash = wp_generate_password(12, false) . '_' . time();
        update_option('mrdastbaaf_content_hash', $hash, false);
    }
}

$GLOBALS['__wp_hooks'] = array();
$legacy = new Legacy_Hash_173();
add_action('updated_option', array($legacy, 'bump_content_hash_on_option_update'), 10, 3);
add_action('added_option', array($legacy, 'bump_content_hash_on_option_add'), 10, 2);

$overflowed = false;
try {
    $legacy->bump_content_hash(14820, new WP_Post(14820, 'product'), true);
} catch (RuntimeException $e) {
    $overflowed = (strpos($e->getMessage(), 'HOOK_DEPTH_OVERFLOW') === 0);
}
mrd_assert('کد قدیمی 1.7.13: ذخیرهٔ محصول → بازگشت بی‌نهایت (سقف ۵۰۰ لایه شکست)', $overflowed, true);
mrd_assert('کد قدیمی 1.7.13: در همان حلقه صدها نوشتن هش رخ داده (نه یک نوشتن)', $GLOBALS['__write_count'] >= 100, true);

/* -------------------------------------------------------------------------
 * ۱) ذخیرهٔ محصول — دقیقاً یک نوشتن، با وجود updated_optionِ خودِ نوشتن
 * ---------------------------------------------------------------------- */

$GLOBALS['__wp_options'] = array();
$GLOBALS['__wp_hooks']   = array();
add_action('updated_option', array($plugin, 'bump_content_hash_on_option_update'), 10, 3);
add_action('added_option', array($plugin, 'bump_content_hash_on_option_add'), 10, 2);
mrd_reset_request_state($plugin);

$plugin->bump_content_hash(14820, new WP_Post(14820, 'product'), true);
mrd_assert('ذخیرهٔ محصول → دقیقاً یک نوشتن هش (نه حلقهٔ بازگشتی)', $GLOBALS['__write_count'], 1);
mrd_assert('هشِ نوشته‌شده خالی نیست', strlen((string) get_option('mrdastbaaf_content_hash', '')) > 0, true);

// صدا زدن دوبارهٔ همان صحنه (مثل دو هوک پشت‌سرهم در یک درخواست) → نوشتن اضافه ندارد
$plugin->bump_content_hash(14820, new WP_Post(14820, 'product'), true);
mrd_assert('bump دومِ همان درخواست → هنوز یک نوشتن (یک‌بارمصرف per-request)', $GLOBALS['__write_count'], 1);

/* -------------------------------------------------------------------------
 * ۲) انواع پستِ غیرمحتوایی — صفر نوشتن
 * ---------------------------------------------------------------------- */

mrd_reset_request_state($plugin);
$GLOBALS['__post_types']   = array(200 => 'post', 300 => 'page', 400 => 'nav_menu_item', 500 => 'auto-draft', 600 => 'product');
$GLOBALS['__revision_ids'] = array(210);
$GLOBALS['__autosave_ids'] = array(220);

$plugin->bump_content_hash(200, new WP_Post(200, 'post'), true);           // پست بلاگ
$plugin->bump_content_hash(400, new WP_Post(400, 'nav_menu_item'), true);  // آیتم منو
$plugin->bump_content_hash(500, new WP_Post(500, 'auto-draft'), true);     // پیش‌نویس خودکار (باز شدن «افزودن»)
$plugin->bump_content_hash(210, new WP_Post(210, 'product', 'inherit'), true); // revision
$plugin->bump_content_hash(220, new WP_Post(220, 'product', 'inherit'), true); // autosave
mrd_assert('post بلاگ / nav_menu_item / auto-draft / revision / autosave → صفر نوشتن', $GLOBALS['__write_count'], 0);

/* -------------------------------------------------------------------------
 * ۳) ذخیرهٔ page — یک نوشتن
 * ---------------------------------------------------------------------- */

mrd_reset_request_state($plugin);
$plugin->bump_content_hash(300, new WP_Post(300, 'page'), true);
mrd_assert('ذخیرهٔ page → یک نوشتن', $GLOBALS['__write_count'], 1);

/* -------------------------------------------------------------------------
 * ۴) آپشن‌های عملیاتی/لاگ — صفر نوشتن
 * ---------------------------------------------------------------------- */

mrd_reset_request_state($plugin);
foreach (array(
    'mrdastbaaf_sms_last_log'       => '[1405/07/24 10:00:00] موفق — کد تایید ارسال شد',
    'mrdastbaaf_last_reconcile_log' => '[10:00] processed=3 recovered=1',
    'mrdastbaaf_cron_token'         => 'tok_' . wp_generate_password(16, false),
    'mrdastbaaf_db_version'         => '1.5.1',
    'mrdastbaaf_pwa_version'        => '1.5.6',
    'mrdastbaaf_options_norm_174'   => 'yes',
    'mrdastbaaf_content_hash'       => 'abcdef123456_' . time(), // خودِ هش — ریشهٔ حلقه
) as $opt => $val) {
    $plugin->bump_content_hash_on_option_update($opt, 'old', $val);
}
mrd_assert('۷ آپشن عملیاتی/لاگ/خودِ هش → صفر نوشتن', $GLOBALS['__write_count'], 0);

// مسیر added_option هم برای آپشن عملیاتی بی‌اثر
$plugin->bump_content_hash_on_option_add('mrdastbaaf_sms_last_log', 'x');
mrd_assert('added_option آپشن لاگ → صفر نوشتن', $GLOBALS['__write_count'], 0);

// گزینهٔ خارج از افزونه
$plugin->bump_content_hash_on_option_update('blogname', 'old', 'new');
mrd_assert('آپشن خارج از mrdastbaaf_* → صفر نوشتن', $GLOBALS['__write_count'], 0);

/* -------------------------------------------------------------------------
 * ۵) آپشن محتوای اپ — یک نوشتن (از طریق زنجیرهٔ کامل do_action مثل وردپرس)
 * ---------------------------------------------------------------------- */

mrd_reset_request_state($plugin);
update_option('mrdastbaaf_app_ui', array('hero' => 'متن جدید')); // → updated_option → bump
mrd_assert('آپشن محتوای اپ (app_ui) از مسیر زنجیرهٔ هوک → یک نوشتن', $GLOBALS['__write_count'], 1);

mrd_reset_request_state($plugin);
add_option('mrdastbaaf_plans', array('plan-a')); // → added_option → bump
mrd_assert('آپشن محتوای اپ (plans) از مسیر added_option → یک نوشتن', $GLOBALS['__write_count'], 1);

/* -------------------------------------------------------------------------
 * ۶) چند bump در یک درخواست — فقط یک نوشتن
 * ---------------------------------------------------------------------- */

mrd_reset_request_state($plugin);
update_option('mrdastbaaf_plans', array('plan-a', 'plan-b')); // bump اول
$plugin->bump_content_hash(14820, new WP_Post(14820, 'product'), true); // bump دوم در همان درخواست
$plugin->bump_content_hash(300, new WP_Post(300, 'page'), true);        // bump سوم در همان درخواست
mrd_assert('سه bump در یک درخواست → فقط یک نوشتن هش', $GLOBALS['__write_count'], 1);

/* -------------------------------------------------------------------------
 * ۷) فیلترهای توسعه — mrdastbaaf_hash_post_types و mrdastbaaf_hash_exempt_options
 * ---------------------------------------------------------------------- */

mrd_reset_request_state($plugin);
add_filter('mrdastbaaf_hash_post_types', function ($types) {
    return array('course'); // فقط course محتوای اپ است
});
$plugin->bump_content_hash(14820, new WP_Post(14820, 'product'), true); // دیگر مجاز نیست
mrd_assert('فیلتر post_types: product بعد از فیلتر → صفر نوشتن', $GLOBALS['__write_count'], 0);
mrd_reset_request_state($plugin);
$GLOBALS['__post_types'][700] = 'course';
$plugin->bump_content_hash(700, new WP_Post(700, 'course'), true);
mrd_assert('فیلتر post_types: course بعد از فیلتر → یک نوشتن', $GLOBALS['__write_count'], 1);

mrd_reset_request_state($plugin);
add_filter('mrdastbaaf_hash_exempt_options', function ($exempt) {
    $exempt[] = 'mrdastbaaf_app_ui';
    return $exempt;
});
$plugin->bump_content_hash_on_option_update('mrdastbaaf_app_ui', 'old', 'new');
mrd_assert('فیلتر exempt_options: app_ui مستثنی شد → صفر نوشتن', $GLOBALS['__write_count'], 0);

/* -------------------------------------------------------------------------
 * نتیجه
 * ---------------------------------------------------------------------- */

echo "\n=== نتیجه: {$pass} پاس / {$fail} خطا ===\n";
exit($fail > 0 ? 1 : 0);
