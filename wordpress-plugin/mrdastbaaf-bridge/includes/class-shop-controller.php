<?php
/**
 * کنترلر فروشگاه وب‌اپ (v1.4.0 — فایل NEW)
 *
 * محصولات «فیزیکی/دست‌ساز» که ادمین در پیشخوان (زیرصفحهٔ NEW «محصولات فیزیکی»
 * در کنترلر صفحات ادمین) تیک می‌زند، از همین‌جا با REST عمومی سرو می‌شوند تا
 * بخش «محصولات دست‌ساز» وب‌اپ (سکشن NEW صفحهٔ نخست با کلید physical_products)
 * از آن‌ها تغذیه شود.
 *
 * ذخیره‌سازی: فقط یک آپشن «mrdastbaaf_physical_products» (آرایهٔ int[] از
 * شناسهٔ محصول ووکامرس؛ update_option با autoload=false). هیچ جدول/تغییری در
 * اسکیمای دیتابیس — همان الگوی mrdastbaaf_app_ui و mrdastbaaf_woo_map.
 *
 * امنیت/خوانایی: فقط محصولات «منتشرشده» لیست می‌شوند (محصول پیش‌نویس/حذف‌شده
 * حتی اگر تیک خورده باشد هرگز بیرون نمی‌آید)؛ قیمت به تومان (واحد پول فروشگاه)
 * به‌صورت عددی؛ پاسخ از سربرگ سراسری no-store namespace (v1.3.1) عبور می‌کند.
 *
 * قرارداد پاسخ (PWA دقیقاً با همین شکل کدنویسی می‌شود — کلیدهای camelCase):
 *   { success: true, categories: [
 *       { id: int, slug: string, name: string,
 *         products: [ { id: int, title: string, price: int,
 *                       imageUrl: string|null, url: string }, … ] }, … ] }
 * هر محصول تیک‌خورده زیر «هر یک» از دسته‌های product_cat خودش می‌آید؛ محصول
 * بی‌دسته زیر گروه جعلی {id:0, slug:'', name:'سایر'}؛ انتخاب خالی → categories: [].
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Shop_Controller {

    const PHYSICAL_PRODUCTS_OPTION = 'mrdastbaaf_physical_products';

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/shop/products', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_shop_products'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * شناسه‌های تیک‌خوردهٔ «محصولات فیزیکی» پیشخوان — فهرست int[] پاکسازی‌شده.
     * هم REST و هم صفحهٔ پیشخوان (خواندن انتخاب‌های فعلی) از همین متد استفاده
     * می‌کنند تا شکل داده هرگز واگرا نشود.
     *
     * @return int[]
     */
    public static function get_physical_product_ids() {
        $stored = get_option(self::PHYSICAL_PRODUCTS_OPTION, array());
        if (!is_array($stored)) {
            return array();
        }
        $ids = array();
        foreach ($stored as $pid) {
            $pid = absint($pid);
            if ($pid > 0) {
                $ids[] = $pid;
            }
        }
        return array_values(array_unique($ids));
    }

    /**
     * GET /shop/products — محصولات فیزیکی تیک‌خوردهٔ پیشخوان، گروه‌بندی‌شده بر
     * اساس دسته‌های product_cat (مرتب بر اساس نام دسته؛ محصولات مرتب بر اساس
     * عنوان). عمومی است و هیچ دادهٔ کاربری ندارد.
     */
    public function handle_get_shop_products() {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست؛ امکان نمایش فروشگاه وجود ندارد.', array('status' => 503));
        }

        $physical_ids = self::get_physical_product_ids();
        if (empty($physical_ids)) {
            return rest_ensure_response(array(
                'success'    => true,
                'categories' => array(),
            ));
        }

        // فقط تیک‌خورده‌های «منتشرشده» — محصول پیش‌نویس/حذف‌شده حذف می‌شود
        $query = new WP_Query(array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'post__in'            => $physical_ids,
            'posts_per_page'      => count($physical_ids),
            'orderby'             => 'title',
            'order'               => 'ASC',
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
        ));

        if (empty($query->posts)) {
            return rest_ensure_response(array(
                'success'    => true,
                'categories' => array(),
            ));
        }

        // نگاشت محصول → آیتم قراردادی (قیمت عددی تومان + تصویر/لینک)
        $items = array();
        foreach ($query->posts as $product_post) {
            $pid = (int) $product_post->ID;
            $product = function_exists('wc_get_product') ? wc_get_product($pid) : null;
            $price = 0;
            if ($product && '' !== $product->get_price() && null !== $product->get_price()) {
                $price = (int) $product->get_price();
            }
            $thumbnail = get_the_post_thumbnail_url($pid, 'medium');
            $items[$pid] = array(
                'id'       => $pid,
                'title'    => (string) $product_post->post_title,
                'price'    => $price,
                'imageUrl' => (is_string($thumbnail) && '' !== $thumbnail) ? $thumbnail : null,
                'url'      => (string) get_permalink($pid),
            );
        }

        // گروه‌بندی بر اساس دسته‌ها — محصول بی‌دسته → «سایر» (id=0)
        $buckets = array();
        foreach ($query->posts as $product_post) {
            $pid = (int) $product_post->ID;
            $terms = get_the_terms($pid, 'product_cat');
            if (is_array($terms) && !empty($terms)) {
                foreach ($terms as $term) {
                    $term_id = (int) $term->term_id;
                    if (!isset($buckets[$term_id])) {
                        $buckets[$term_id] = array('term' => $term, 'ids' => array());
                    }
                    $buckets[$term_id]['ids'][] = $pid;
                }
            } else {
                if (!isset($buckets[0])) {
                    $buckets[0] = array('term' => null, 'ids' => array());
                }
                $buckets[0]['ids'][] = $pid;
            }
        }

        $categories = array();
        foreach ($buckets as $bucket) {
            $product_list = array();
            foreach ($bucket['ids'] as $pid) {
                if (isset($items[$pid])) {
                    $product_list[] = $items[$pid];
                }
            }
            if (empty($product_list)) {
                continue;
            }
            // قطعیت ترتیب محصولات داخل هر دسته: عنوان صعودی
            usort($product_list, array(__CLASS__, 'sort_products_by_title'));

            if ($bucket['term']) {
                $categories[] = array(
                    'id'       => (int) $bucket['term']->term_id,
                    'slug'     => (string) $bucket['term']->slug,
                    'name'     => (string) $bucket['term']->name,
                    'products' => $product_list,
                );
            } else {
                $categories[] = array(
                    'id'       => 0,
                    'slug'     => '',
                    'name'     => 'سایر',
                    'products' => $product_list,
                );
            }
        }

        // دسته‌ها مرتب بر اساس نام (گروه «سایر» هم در همین ترتیب الفبایی می‌نشیند)
        usort($categories, array(__CLASS__, 'sort_categories_by_name'));

        return rest_ensure_response(array(
            'success'    => true,
            'categories' => $categories,
        ));
    }

    /**
     * مقایسه‌گر مرتب‌سازی محصولات بر اساس عنوان
     */
    private static function sort_products_by_title($a, $b) {
        return strcmp((string) $a['title'], (string) $b['title']);
    }

    /**
     * مقایسه‌گر مرتب‌سازی دسته‌ها بر اساس نام
     */
    private static function sort_categories_by_name($a, $b) {
        return strcmp((string) $a['name'], (string) $b['name']);
    }
}
