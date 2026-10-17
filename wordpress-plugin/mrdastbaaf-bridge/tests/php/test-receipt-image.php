<?php
/**
 * تست مستقل تصویر فیش کارت‌به‌کارت — v1.5.7 (گزارش کارفرما: «تصویر تو پیشخوان باشد» +
 * «حجم دیتابیس/بهینگی اولویت بالاست»)
 *
 * اجرا: php tests/php/test-receipt-image.php
 *
 * چه چیزی آزموده می‌شود (کلاس واقعی افزونه — نه کپی دستی):
 *   ۱) resolve_receipt_image_path: دفاع مسیر — ردِ رشتهٔ خالی، ../، مسیر مطلق،
 *      پسوند ناشناخته، الگوی ناسازگار؛ پذیرش فقط Y/m/نام‌فایل معتبر.
 *   ۲) delete_receipt_image_file: حذف امن فایل واقعی زیر پوشه؛ false برای فایل غایب.
 *   ۳) purge_decided_receipt_images (با wpdb شبیه‌سازی‌شده): حذف فایلِ معتبر +
 *      NULL‌کردن ستون برای همه (حتی فایل غایب) + شمارش درست + بچِ LIMIT.
 *   ۴) run_daily_receipt_image_purge با گزینهٔ نگهداری ۰ → صفر کوئری (تعهد بهینگی).
 *
 * شبیه‌ساز وردپرس: فقط توابع موردنیاز این کلاس (wp_upload_dir/trailingslashit/
 * wp_mkdir_p/current_time) + wpdb کاغذی با شمارندهٔ کوئری.
 */

error_reporting(E_ALL & ~E_DEPRECATED);
define('ABSPATH', '/tmp/wp/');
// ثابت‌های هستهٔ وردپرس که کلاس استفاده می‌کند
define('DAY_IN_SECONDS', 86400);

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

/* ------------------------- شبیه‌ساز مینیمال وردپرس ------------------------- */

$GLOBALS['__base_dir'] = sys_get_temp_dir() . '/mrd-test-receipts-' . getmypid() . '/';

function wp_upload_dir() {
    return array('basedir' => rtrim($GLOBALS['__base_dir'], '/'));
}
function trailingslashit($s) { return rtrim($s, '/\\') . '/'; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0755, true); }
function current_time($type) {
    return 'timestamp' === $type ? time() : date('Y-m-d H:i:s');
}
function get_option($name, $default = false) {
    return isset($GLOBALS['__options'][$name]) ? $GLOBALS['__options'][$name] : $default;
}
function update_option($name, $value, $autoload = null) {
    $GLOBALS['__options'][$name] = $value;
    return true;
}
function error_log_stub($msg) { /* بی‌صدا در تست */ }

/** wpdb کاغذی — فقط رفتارهای لازم purge */
class FakeWpdb {
    public $prefix = 'wp_';
    public $queries = array();
    public $rows = array();      // نتیجهٔ get_results بعدی
    public $updates = array();   // رکورد updateها
    public function prepare($sql, ...$args) {
        // فقط برای عبور prepare — الگوی %s/%d با vsprintf جایگزین می‌شود
        return vsprintf(str_replace(array('%s', '%d'), array("'%s'", '%d'), $sql), $args);
    }
    public function get_results($sql) {
        $this->queries[] = $sql;
        return $this->rows;
    }
    public function update($table, $data, $where) {
        $this->updates[] = array($table, $data, $where);
        return 1;
    }
    public function get_var($sql) { $this->queries[] = $sql; return null; }
}

require_once __DIR__ . '/../../includes/class-payment-controller.php';

/* ------------------------------- تست‌ها ------------------------------- */

$cls = 'MrDastbaaf_Payment_Controller';

// ۱) دفاع مسیر — هیچ راه‌فراری بیرون از پوشه نباید جواب بدهد
mrd_assert('resolve: رشتهٔ خالی → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), ''), '');
mrd_assert('resolve: ../ تراوریسال → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), '2026/10/../../secret.jpg'), '');
mrd_assert('resolve: مسیر مطلق → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), '/etc/passwd'), '');
mrd_assert('resolve: بدون تاریخ → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), 'secret.jpg'), '');
mrd_assert('resolve: پسوند php → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), '2026/10/shell.php'), '');
mrd_assert('resolve: پسوند html → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), '2026/10/page.html'), '');
mrd_assert('resolve: نام نقطه‌دار مخفی → ""', call_user_func(array($cls, 'resolve_receipt_image_path'), '2026/10/.hidden.jpg'), '');

// ۲) مسیر معتبر — زیر پوشهٔ پایه است + حذف واقعی کار می‌کند
// (نکته: resolve با realpath کار می‌کند؛ برای مسیرِ هنوز‌ساخته‌نشده '' می‌دهد —
//  در جریان واقعی فایل «بعد» از ذخیره resolve می‌شود، پس ترتیب تست هم همین است)
$base = call_user_func(array($cls, 'receipts_base_dir'));
$valid_rel = date('Y') . '/' . date('m') . '/abc123test.jpg';
$expected_full = $base . $valid_rel;
if (!is_dir(dirname($expected_full))) { mkdir(dirname($expected_full), 0755, true); }
file_put_contents($expected_full, 'JPEGDATA');

$valid_full = call_user_func(array($cls, 'resolve_receipt_image_path'), $valid_rel);
mrd_assert('resolve: مسیر معتبر زیر پایه', (bool) $valid_full && 0 === strpos($valid_full, realpath($base)), true);

mrd_assert('delete: فایل موجود حذف می‌شود', call_user_func(array($cls, 'delete_receipt_image_file'), $valid_rel), true);
mrd_assert('delete: فایل غایب false', call_user_func(array($cls, 'delete_receipt_image_file'), $valid_rel), false);

// ۳) purge — با wpdb شبیه‌سازی‌شده
$fake = new FakeWpdb();
$GLOBALS['wpdb'] = $fake;
$base2 = call_user_func(array($cls, 'receipts_base_dir'));

// سه ردیف: فایل معتبر موجود / فایل معتبر غایب / مسیر نامعتبر (دفاع)
$file1_rel = date('Y') . '/' . date('m') . '/purge-a.jpg';
$file1_full = $base2 . $file1_rel;
if (!is_dir(dirname($file1_full))) { mkdir(dirname($file1_full), 0755, true); }
file_put_contents($file1_full, 'JPEGDATA-A');

$fake->rows = array(
    (object) array('order_id' => 'ord_ok', 'receipt_image_name' => $file1_rel),
    (object) array('order_id' => 'ord_gone', 'receipt_image_name' => '2025/01/missing.jpg'),
    (object) array('order_id' => 'ord_evil', 'receipt_image_name' => '2025/01/../../evil.jpg'),
);
$deleted = call_user_func(array($cls, 'purge_decided_receipt_images'), 14, 300);
mrd_assert('purge: فقط فایل معتبر حذف شد (شمارش)', $deleted, 1);
mrd_assert('purge: فایل واقعاً حذف شده', !is_file($file1_full), true);
mrd_assert('purge: هر ۳ ردیف ستون‌شان NULL شد', count($fake->updates), 3);

// ۴) تعهد بهینگی — نگهداری ۰ = صفر کوئری
$GLOBALS['__options']['mrdastbaaf_receipt_image_retention_days'] = 0;
$fake->queries = array();
call_user_func(array($cls, 'run_daily_receipt_image_purge'));
mrd_assert('purge کرون: نگهداری ۰ → صفر کوئری', count($fake->queries), 0);

// ۵) پسوند معتبرهای دیگر (png/webp/jpeg) — با فایل واقعی (resolve مبتنی بر realpath است)
foreach (array('png', 'webp', 'jpeg') as $ok_ext) {
    $rel = date('Y') . '/' . date('m') . '/multi.' . $ok_ext;
    $full = $base . $rel;
    if (!is_dir(dirname($full))) { mkdir(dirname($full), 0755, true); }
    file_put_contents($full, 'X');
    mrd_assert("resolve: {$ok_ext} مجاز", (bool) call_user_func(array($cls, 'resolve_receipt_image_path'), $rel), true);
    mrd_assert("delete: {$ok_ext} حذف واقعی", call_user_func(array($cls, 'delete_receipt_image_file'), $rel), true);
}

// پاک‌سازی محیط تست
function rrmdir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $item) {
        if ('.' === $item || '..' === $item) continue;
        $p = $dir . '/' . $item;
        is_dir($p) ? rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}
rrmdir($GLOBALS['__base_dir']);

echo "\n================ نتیجه: {$pass} پاس / {$fail} شکست ================\n";
exit($fail > 0 ? 1 : 0);
