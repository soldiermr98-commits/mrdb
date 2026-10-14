<?php
/**
 * تست مستقل ریاضی تبدیل سطح اشتراک (test-tier-math) — بدون وردپرس
 *
 * اجرای روی سرور: php tests/php/test-tier-math.php
 *
 * فقط متدهای «خالص» کلاس MrDastbaaf_Membership_Helper آزموده می‌شوند:
 *   - convert_remaining_days()  تبدیل ارزش روزهای باقی‌مانده پلن قدیم به روز پلن جدید
 *   - tier_rank()               رتبه‌بندی سلسله‌مراتب سطوح (free < bronze < silver < gold)
 *
 * تعریف ABSPATH فقط گارد ابتدای فایل کلاس را خنثی می‌کند؛ تعریف کلاس هیچ فراخوانی
 * وردپرسی انجام نمی‌دهد (متدهای DB-محور در این تست صدا زده نمی‌شوند).
 */

define('ABSPATH', '/tmp/');

require_once __DIR__ . '/../../includes/class-membership-helper.php';

$pass = 0;
$fail = 0;

/**
 * اجرای یک assertion و ثبت نتیجه
 */
function mrd_assert($label, $actual, $expected) {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "PASS: {$label}\n";
    } else {
        $fail++;
        echo "FAIL: {$label} — expected: " . var_export($expected, true) . " | got: " . var_export($actual, true) . "\n";
    }
}

// ─── ۱) تبدیل روزهای باقی‌مانده ───
// سناریوی بریف: ۳۰ روز باقی‌ماندهٔ برنز (99000/30=3300 تومان/روز) → خرید طلایی
// (690000/365≈1890.41 تومان/روز) ⇒ floor(30×3300/1890.41) = floor(52.36) = 52
mrd_assert(
    '30d bronze -> gold = 52 bonus days',
    MrDastbaaf_Membership_Helper::convert_remaining_days(30, 99000, 30, 690000, 365),
    52
);

// صفر روز باقی‌مانده ⇒ بدون بونوس
mrd_assert(
    '0 remaining days = 0 bonus',
    MrDastbaaf_Membership_Helper::convert_remaining_days(0, 99000, 30, 690000, 365),
    0
);

// سناریوی بریف: ۹۰ روز نقره (249000/90≈2766.67) → برنز (99000/30=3300)
// ⇒ floor(90×2766.67/3300) = floor(75.45) = 75
mrd_assert(
    '90d silver -> bronze = 75 bonus days',
    MrDastbaaf_Membership_Helper::convert_remaining_days(90, 249000, 90, 99000, 30),
    75
);

// منفی به‌عنوان ورودی (دفاعی) ⇒ ۰
mrd_assert(
    'negative remaining clamps to 0',
    MrDastbaaf_Membership_Helper::convert_remaining_days(-5, 99000, 30, 690000, 365),
    0
);

// مدت صفر پلن قدیم (تقسیم بر صفر) ⇒ max(1,…) محافظ ⇒ 0 (پلن رایگان قدیم ارزشی ندارد)
mrd_assert(
    'zero old duration guarded (free plan = 0 toman) => 0',
    MrDastbaaf_Membership_Helper::convert_remaining_days(30, 0, 0, 99000, 30),
    0
);

// ─── ۲) رتبه‌بندی سطوح ───
mrd_assert('tier_rank(free)=0',   MrDastbaaf_Membership_Helper::tier_rank('free'),   0);
mrd_assert('tier_rank(bronze)=1', MrDastbaaf_Membership_Helper::tier_rank('bronze'), 1);
mrd_assert('tier_rank(silver)=2', MrDastbaaf_Membership_Helper::tier_rank('silver'), 2);
mrd_assert('tier_rank(gold)=3',   MrDastbaaf_Membership_Helper::tier_rank('gold'),   3);

$satisfies_order = (
    MrDastbaaf_Membership_Helper::tier_rank('free') < MrDastbaaf_Membership_Helper::tier_rank('bronze')
    && MrDastbaaf_Membership_Helper::tier_rank('bronze') < MrDastbaaf_Membership_Helper::tier_rank('silver')
    && MrDastbaaf_Membership_Helper::tier_rank('silver') < MrDastbaaf_Membership_Helper::tier_rank('gold')
);
mrd_assert('tier rank order free<bronze<silver<gold', $satisfies_order, true);

// سطح ناشناخته ⇒ ۰ (رفتار ایمن)
mrd_assert('unknown tier rank = 0', MrDastbaaf_Membership_Helper::tier_rank('platinum'), 0);

echo "\n---- result ----\n";
echo "PASS: {$pass}\n";
echo "FAIL: {$fail}\n";

exit($fail > 0 ? 1 : 0);
