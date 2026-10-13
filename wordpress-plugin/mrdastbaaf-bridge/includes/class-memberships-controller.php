<?php
/**
 * کنترلر پلن‌های عضویت و استعلام وضعیت اشتراک دستی
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Memberships_Controller {

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/memberships/plans', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_plans'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/memberships/my-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_my_status'),
            'permission_callback' => 'is_user_logged_in',
        ));
    }

    /**
     * استعلام لیست پلن‌های فعال عضویت برای خرید دستی
     */
    public function handle_get_plans() {
        global $wpdb;

        // PERF (فاز ۳): کش ۵ دقیقه‌ای لیست پلن‌ها — باطل‌سازی در ویرایش ادمین (BUG-37) و پس از seed
        $cached_plans = get_transient('mrdastbaaf_plans_cache');
        if (is_array($cached_plans)) {
            return rest_ensure_response($cached_plans);
        }

        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';

        $rows = $wpdb->get_results("SELECT * FROM $table_plans WHERE is_active = 1 ORDER BY price_toman ASC");
        $plans = array();

        foreach ($rows as $r) {
            $plans[] = array(
                'id' => $r->id,
                'tier' => $r->tier,
                'title' => $r->title,
                'description' => $r->description,
                'priceToman' => (int) $r->price_toman,
                'originalPriceToman' => $r->original_price_toman ? (int) $r->original_price_toman : null,
                'duration' => $r->duration_days . '_days',
                'durationLabel' => $r->duration_label,
                'features' => json_decode($r->features_json, true) ?: array(),
                'isPopular' => (bool) $r->is_popular,
                'isActive' => (bool) $r->is_active,
                'colorScheme' => $r->color_scheme,
            );
        }

        set_transient('mrdastbaaf_plans_cache', $plans, 5 * MINUTE_IN_SECONDS);

        return rest_ensure_response($plans);
    }

    /**
     * استعلام وضعیت اشتراک جاری کاربر واردشده
     */
    public function handle_get_my_status() {
        global $wpdb;
        $user_id = get_current_user_id();
        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';
        $now = current_time('mysql');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_memberships 
             WHERE user_id = %d AND status = 'active' AND expires_at > %s 
             ORDER BY expires_at DESC LIMIT 1",
            $user_id, $now
        ));

        if (!$row) {
            return rest_ensure_response(array(
                'hasActiveMembership' => false,
                'membership' => null,
            ));
        }

        // TIMEZONE FIX (فاز ۳): مقایسه با مرجع زمان محلی (current_time) به‌جای time() واقعی UTC
        $diff = strtotime($row->expires_at) - current_time('timestamp');
        $days_remaining = max(0, ceil($diff / (60 * 60 * 24)));

        return rest_ensure_response(array(
            'hasActiveMembership' => true,
            'membership' => array(
                'tier' => $row->tier,
                'planId' => $row->plan_id,
                'status' => 'active',
                'startedAt' => $row->started_at,
                'expiresAt' => $row->expires_at,
                'formattedExpireDateJalali' => date_i18n('Y/m/d', strtotime($row->expires_at)),
                'daysRemaining' => $days_remaining,
            ),
        ));
    }
}
