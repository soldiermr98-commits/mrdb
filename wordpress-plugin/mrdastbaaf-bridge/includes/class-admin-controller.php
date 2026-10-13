<?php
/**
 * کنترلر دسترسی‌ها و پنل مدیریت مستر دستباف (Admin Control Center Controller)
 * 
 * تمام اندپوینت‌های این کلاس منحصراً برای کاربرانی با مجوز 'manage_options' در دسترس است.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Admin_Controller {

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/analytics', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_analytics'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/receipts', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_pending_receipts'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/receipts/(?P<id>[a-zA-Z0-9_-]+)/review', array(
            'methods' => 'PUT',
            'callback' => array($this, 'handle_review_receipt'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/plans/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'handle_update_plan'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/homepage-sections', array(
            'methods' => array('GET', 'PUT'),
            'callback' => array($this, 'handle_homepage_sections'),
            'permission_callback' => array($this, 'check_admin_permission'),
        ));
    }

    /**
     * اعتبارسنجی سطح دسترسی مدیر کل وردپرس
     */
    public function check_admin_permission() {
        return current_user_can('manage_options');
    }

    /**
     * آمار و گزارش‌های مالی تجمیعی
     */
    public function handle_get_analytics() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';
        $now = current_time('mysql');

        $total_revenue = $wpdb->get_var("SELECT COALESCE(SUM(amount_toman), 0) FROM $table_orders WHERE status = 'completed'");
        $pending_receipts = $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE gateway = 'card_to_card' AND status = 'pending'");
        $completed_orders = $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE status = 'completed'");
        $active_members = $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT user_id) FROM $table_memberships WHERE status = 'active' AND expires_at > %s", $now));

        return rest_ensure_response(array(
            'totalRevenueToman' => (int) $total_revenue,
            'pendingReceiptsCount' => (int) $pending_receipts,
            'completedOrdersCount' => (int) $completed_orders,
            'activeMembersCount' => (int) $active_members,
        ));
    }

    /**
     * صف بررسی فیش‌های واریزی کارت به کارت
     */
    public function handle_get_pending_receipts() {
        global $wpdb;
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        $query = "SELECT o.*, t.tracking_code, t.card_last_four, t.deposit_date_jalali, t.sender_notes 
                  FROM $table_orders o 
                  INNER JOIN $table_transfers t ON o.id = t.order_id 
                  WHERE o.gateway = 'card_to_card' AND o.status = 'pending' 
                  ORDER BY o.created_at DESC";

        $rows = $wpdb->get_results($query);
        $receipts = array();

        foreach ($rows as $r) {
            $receipts[] = array(
                'id' => $r->id,
                'orderNumber' => $r->order_number,
                'userId' => (string) $r->user_id,
                'userPhone' => $r->user_phone,
                'userFullName' => $r->user_fullname,
                'itemTitle' => $r->item_title,
                'amountToman' => (int) $r->amount_toman,
                'gateway' => $r->gateway,
                'status' => $r->status,
                'createdAtJalali' => date_i18n('Y/m/d H:i', strtotime($r->created_at)),
                'manualTransferDetails' => array(
                    'trackingCode' => $r->tracking_code,
                    'sourceCardLastFour' => $r->card_last_four,
                    'depositDateJalali' => $r->deposit_date_jalali,
                    'senderNotes' => $r->sender_notes,
                ),
            );
        }

        return rest_ensure_response($receipts);
    }

    /**
     * تایید یا رد فیش واریزی توسط مدیر
     */
    public function handle_review_receipt(WP_REST_Request $request) {
        global $wpdb;
        $order_id = sanitize_text_field($request->get_param('id'));
        $decision = sanitize_text_field($request->get_param('decision')); // 'approve' یا 'reject'
        $admin_note = sanitize_textarea_field($request->get_param('admin_note'));

        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_orders WHERE id = %s", $order_id));
        if (!$order) {
            return new WP_Error('not_found', 'سفارش یافت نشد.', array('status' => 404));
        }

        $now = current_time('mysql');
        $reviewer_id = get_current_user_id();

        if ($decision === 'reject') {
            if (empty($admin_note)) {
                return new WP_Error('missing_reason', 'درج علت رد فیش الزامی است.', array('status' => 400));
            }

            $wpdb->update($table_orders, array('status' => 'rejected'), array('id' => $order_id));
            $wpdb->update($table_transfers, array(
                'admin_review_note' => $admin_note,
                'reviewer_admin_id' => $reviewer_id,
                'reviewed_at' => $now,
            ), array('order_id' => $order_id));

            return rest_ensure_response(array('success' => true, 'message' => 'فیش واریزی با ثبت علت رد گردید.'));
        }

        if ($decision === 'approve') {
            /**
             * SECURITY FIX (BUG-14 caller): فعال‌سازی اشتراک «قبل از» completed شدن
             * سفارش انجام می‌شود. اگر grant_or_extend شکست بخورد (پلن حذف‌شده یا
             * خطای DB)، سفارش در صف باقی می‌ماند تا مشکل رفع شود — پول کاربر بدون
             * اشتراک گم نمی‌شود و مدیر خطا را می‌بیند.
             */
            $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
            $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $order->plan_id));

            $membership_result = MrDastbaaf_Membership_Helper::grant_or_extend($order->user_id, $plan, $order->id);
            if (false === $membership_result) {
                return new WP_Error('membership_grant_failed', 'تایید انجام شد اما فعال‌سازی اشتراک ناموفق بود؛ سفارش در صف بررسی باقی ماند. لطفاً پلن متناظر سفارش را بررسی کنید.', array('status' => 409));
            }

            $wpdb->update($table_orders, array(
                'status' => 'completed',
                'verified_at' => $now,
            ), array('id' => $order_id));

            $wpdb->update($table_transfers, array(
                'admin_review_note' => $admin_note ?: 'فیش با موفقیت تایید شد.',
                'reviewer_admin_id' => $reviewer_id,
                'reviewed_at' => $now,
            ), array('order_id' => $order_id));

            do_action('mrdastbaaf_membership_granted', $order->user_id, $membership_result);

            return rest_ensure_response(array(
                'success' => true,
                'message' => ($membership_result['mode'] === 'extended'
                    ? 'فیش تایید و اشتراک کاربر با حفظ روزهای باقی‌مانده تمدید شد.'
                    : 'فیش تایید و اشتراک کاربر فعال گردید.'),
            ));
        }

        return new WP_Error('invalid_decision', 'تصمیم نامعتبر است.', array('status' => 400));
    }

    /**
     * ویرایش پلن عضویت توسط مدیر
     */
    public function handle_update_plan(WP_REST_Request $request) {
        global $wpdb;
        $plan_id = sanitize_text_field($request->get_param('id'));
        $title = sanitize_text_field($request->get_param('title'));
        $duration_label = sanitize_text_field($request->get_param('durationLabel'));
        $description = sanitize_textarea_field($request->get_param('description'));
        $is_active = $request->get_param('isActive') !== null ? (int)(bool)$request->get_param('isActive') : null;

        /**
         * SECURITY FIX (BUG-13): قبلاً intval(null) === 0 و چون شرط ($price >= 0)
         * برقرار بود، هر درخواستی که priceToman را ارسال نمی‌کرد، قیمت پلن را
         * بی‌صدا صفر می‌کرد (فروش رایگان!). اکنون قیمت فقط وقتی به‌روزرسانی می‌شود
         * که صریحاً ارسال شده، عدد معتبر و نامنفی باشد.
         */
        $updates = array();
        if ($title) $updates['title'] = $title;

        $price_param = $request->get_param('priceToman');
        if ($price_param !== null && $price_param !== '') {
            if (!is_numeric($price_param) || (int) $price_param < 0) {
                return new WP_Error('invalid_price', 'قیمت پلن باید عددی و نامنفی باشد.', array('status' => 400));
            }
            $updates['price_toman'] = (int) $price_param;
        }

        if ($duration_label) $updates['duration_label'] = $duration_label;
        if ($description) $updates['description'] = $description;
        if ($is_active !== null) $updates['is_active'] = $is_active;

        if (empty($updates)) {
            return new WP_Error('no_data', 'داده‌ای برای به‌روزرسانی ارسال نشد.', array('status' => 400));
        }

        $wpdb->update($table_plans, $updates, array('id' => $plan_id));
        return rest_ensure_response(array('success' => true, 'message' => 'پلن با موفقیت به‌روزرسانی شد.'));
    }

    /**
     * خواندن و ذخیره چیدمان سکشن‌های صفحه اصلی
     *
     * SECURITY FIX (BUG-05): قبلاً JSON خام بدون هیچ پاکسازی‌ای در option ذخیره
     * می‌شد؛ هر ادمین/مهاجم می‌توانست اسکریپت تزریق کند که در صفحه اصلی رندر و
     * Stored XSS می‌شد. اکنون فقط کلیدهای مجاز با مقادیر پاکسازی‌شده ذخیره می‌شوند.
     */
    public function handle_homepage_sections(WP_REST_Request $request) {
        $option_key = 'mrdastbaaf_homepage_sections';

        if ($request->get_method() === 'GET') {
            $sections = get_option($option_key, array());
            return rest_ensure_response($sections);
        }

        if ($request->get_method() === 'PUT') {
            $raw_sections = $request->get_json_params();
            if (!is_array($raw_sections)) {
                return new WP_Error('invalid_payload', 'ساختار داده ارسالی معتبر نیست.', array('status' => 400));
            }

            $allowed_keys = array('id', 'key', 'title', 'subtitle', 'isEnabled', 'sortOrder', 'configData');
            $valid_section_keys = array('hero', 'categories', 'featured_courses', 'membership_plans', 'artisan_story', 'faq');
            $clean_sections = array();

            foreach ($raw_sections as $section) {
                if (!is_array($section)) {
                    continue;
                }
                $clean = array();

                foreach ($allowed_keys as $key) {
                    if (!array_key_exists($key, $section)) {
                        continue;
                    }
                    $value = $section[$key];

                    switch ($key) {
                        case 'title':
                        case 'subtitle':
                            $clean[$key] = sanitize_text_field((string) $value); // no HTML/JS survives
                            break;
                        case 'isEnabled':
                            $clean[$key] = (bool) $value;
                            break;
                        case 'sortOrder':
                            $clean[$key] = (int) $value;
                            break;
                        case 'key':
                            $key_val = sanitize_key((string) $value);
                            if (in_array($key_val, $valid_section_keys, true)) {
                                $clean[$key] = $key_val;
                            }
                            break;
                        case 'id':
                            $clean[$key] = sanitize_key((string) $value);
                            break;
                        case 'configData':
                            // فقط مقادیر اسکالر رشته‌ای/عددی پاکسازی‌شده پذیرفته می‌شود
                            if (is_array($value)) {
                                $clean_config = array();
                                foreach ($value as $ck => $cv) {
                                    $config_key = sanitize_key((string) $ck);
                                    if ($config_key === '' || is_array($cv) || is_object($cv)) {
                                        continue;
                                    }
                                    $clean_config[$config_key] = is_bool($cv) ? $cv : (is_numeric($cv) ? $cv : sanitize_text_field((string) $cv));
                                }
                                $clean[$key] = (object) $clean_config;
                            }
                            break;
                    }
                }

                if (!empty($clean)) {
                    $clean_sections[] = $clean;
                }
            }

            update_option($option_key, $clean_sections, false); // autoload = false برای عملکرد بهتر
            return rest_ensure_response(array('success' => true, 'message' => 'چیدمان صفحه اصلی ذخیره شد.'));
        }
    }
}
