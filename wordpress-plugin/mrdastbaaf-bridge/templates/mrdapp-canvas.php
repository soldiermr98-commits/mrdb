<?php
/**
 * قالب مینیمال «رندر وب‌اپ» (v1.3.5 — NEW)
 *
 * وقتی صفحه‌ای از سایت با پارامتر mrdapp=1 درخواست می‌شود (iframe وب‌اپ) و
 * المنتور فعال نیست (یا قالب canvas.php آن در دسترس نیست)، همین قالب به‌جای
 * قالب کامل سایت رندر می‌شود: بدون هدر/فوتر/سایدبار — فقط محتوای برگه.
 *
 * نکتهٔ امنیتی: رسیدن به این قالب فقط از مسیر maybe_render_app_page (فایل اصلی
 * افزونه) ممکن است — آن مسیر ثبت‌بودن صفحه در «صفحات وب‌اپ» و سطح دسترسی
 * (all/members/admins) را با هستهٔ مشترک REST، fail-closed اعمال می‌کند.
 *
 * استایل: wp_head همهٔ استایل‌های صف‌بندی‌شدهٔ قالب/افزونه‌ها (شامل CSS برگهٔ
 * المنتور وقتی فعال باشد) را چاپ می‌کند؛ the_content هم فیلترهای کامل محتوا.
 *
 * @package MrDastbaaf_Bridge
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <?php wp_head(); ?>
</head>
<body <?php body_class('mrdapp-canvas'); ?>>
<?php
while (have_posts()) :
    the_post();
    ?>
    <main id="mrdapp-content" class="mrdapp-canvas__content">
        <?php the_content(); ?>
    </main>
    <?php
endwhile;
?>
<?php wp_footer(); ?>
</body>
</html>
