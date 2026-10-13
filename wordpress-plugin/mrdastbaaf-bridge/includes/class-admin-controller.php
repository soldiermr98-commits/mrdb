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

        // BUG-37: صف بدون LIMIT می‌توانست در حجم بالا مصرف حافظه را منفجر کند
        $rows = $wpdb->get_results($query . ' LIMIT 100');
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

        // BUG-37: تعداد کل فیش‌های در انتظار (برای هدر صف بررسی)
        $total_pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_orders WHERE gateway = 'card_to_card' AND status = 'pending'");

        return rest_ensure_response(array(
            'total' => $total_pending,
            'receipts' => $receipts,
        ));
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

        // BUG-37: فقط فیش‌های کارت‌به‌کارتِ «در انتظار» قابل تایید/رد هستند —
        // سفارش زیبال یا پردازش‌شده نباید از مسیر بررسی دستی دستکاری شود.
        if ($order->gateway !== 'card_to_card' || $order->status !== 'pending') {
            return new WP_Error('not_reviewable', 'این سفارش قابل بررسی نیست؛ فقط فیش‌های کارت‌به‌کارتِ در انتظار مجاز هستند.', array('status' => 409));
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
            // BUG-37: ادعای اتمیک سفارش پیش از grant — دو ادمین هم‌زمان (یا درخواست
            // تکراری) نمی‌توانند اشتراک را دوباره فعال کنند؛ UPDATE مشروط به pending.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'completed', verified_at = %s WHERE id = %s AND status = 'pending'",
                $now, $order_id
            ));
            if (1 !== (int) $claimed) {
                return new WP_Error('order_already_processed', 'این سفارش هم‌اکنون پردازش شده است؛ فهرست فیش‌ها را به‌روزرسانی کنید.', array('status' => 409));
            }

            /**
             * SECURITY FIX (BUG-14 caller): فعال‌سازی اشتراک پیش از نهایی‌سازی انجام
             * می‌شود. اگر grant_or_extend شکست بخورد (پلن حذف‌شده یا خطای DB)،
             * سفارش به pending برمی‌گردد و در صف باقی می‌ماند — پول کاربر بدون
             * اشتراک گم نمی‌شود و مدیر خطا را می‌بیند.
             */
            $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
            $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $order->plan_id));

            $membership_result = MrDastbaaf_Membership_Helper::grant_or_extend($order->user_id, $plan, $order->id);
            if (false === $membership_result) {
                // BUG-37: برگشت وضعیت به pending — فیش دوباره در صف بررسی قرار می‌گیرد
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'pending' WHERE id = %s AND status = 'completed'",
                    $order_id
                ));
                return new WP_Error('membership_grant_failed', 'تایید انجام شد اما فعال‌سازی اشتراک ناموفق بود؛ سفارش در صف بررسی باقی ماند. لطفاً پلن متناظر سفارش را بررسی کنید.', array('status' => 409));
            }

            // BUG-37: وضعیت سفارش در ادعای اتمیک به completed تغییر کرده است؛
            // در اینجا فقط یادداشت بررسی ثبت می‌شود.
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
        // BUG-37: تعریف $table_plans — قبلاً این متغیر فقط داخل handle_review_receipt
        // تعریف شده بود و $wpdb->update با متغیر تعریف‌نشده بی‌صدا no-op می‌شد
        // (پاسخ success جعلی برمی‌گشت و هیچ پلنی ذخیره نمی‌شد).
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';

        $updates = array();
        if ($title) {
            // BUG-37: طول عنوان با ستون varchar(191) هم‌خوان می‌شود
            if (mb_strlen($title) > 191) {
                return new WP_Error('title_too_long', 'عنوان پلن حداکثر ۱۹۱ کاراکتر است.', array('status' => 400));
            }
            $updates['title'] = $title;
        }

        $price_param = $request->get_param('priceToman');
        if ($price_param !== null && $price_param !== '') {
            if (!is_numeric($price_param) || (int) $price_param < 0) {
                return new WP_Error('invalid_price', 'قیمت پلن باید عددی و نامنفی باشد.', array('status' => 400));
            }
            $updates['price_toman'] = (int) $price_param;
        }

        if ($duration_label) {
            // BUG-37: طول برچسب با ستون varchar(50) هم‌خوان می‌شود
            if (mb_strlen($duration_label) > 50) {
                return new WP_Error('duration_label_too_long', 'برچسب مدت پلن حداکثر ۵۰ کاراکتر است.', array('status' => 400));
            }
            $updates['duration_label'] = $duration_label;
        }
        if ($description) $updates['description'] = $description;
        if ($is_active !== null) $updates['is_active'] = $is_active;

        if (empty($updates)) {
            return new WP_Error('no_data', 'داده‌ای برای به‌روزرسانی ارسال نشد.', array('status' => 400));
        }

        // BUG-37: بررسی نتیجه update — false خطای DB (500) و 0 ردیف یعنی پلن وجود ندارد (404)
        $updated = $wpdb->update($table_plans, $updates, array('id' => $plan_id));
        if (false === $updated) {
            error_log('MrDastbaaf: plan update failed for plan ' . $plan_id . ' — ' . $wpdb->last_error);
            return new WP_Error('plan_update_failed', 'به‌روزرسانی پلن ناموفق بود؛ لطفاً دوباره تلاش کنید.', array('status' => 500));
        }
        if (0 === $updated) {
            return new WP_Error('plan_not_found', 'پلن یافت نشد.', array('status' => 404));
        }

        // کش لیست پلن‌های عمومی پس از ویرایش باطل می‌شود
        delete_transient('mrdastbaaf_plans_cache');

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
            $seen_section_keys = array();

            foreach ($raw_sections as $section) {
                // BUG-37: حداکثر ۱۰ سکشن برای صفحه اصلی مجاز است
                if (count($clean_sections) >= 10) {
                    return new WP_Error('too_many_sections', 'حداکثر ۱۰ سکشن برای صفحه اصلی مجاز است.', array('status' => 400));
                }

                if (!is_array($section)) {
                    return new WP_Error('invalid_section', 'ساختار سکشن ارسالی معتبر نیست.', array('status' => 400));
                }

                // BUG-37: سکشن با کلید نامعلوم یا خارج از لیست سفید به‌جای نادیده
                // گرفته‌شدن بی‌صدا، کل درخواست را با 400 رد می‌کند.
                $section_key = isset($section['key']) ? sanitize_key((string) $section['key']) : '';
                if ($section_key === '' || !in_array($section_key, $valid_section_keys, true)) {
                    return new WP_Error('unknown_section_key', 'کلید سکشن ارسالی معتبر نیست و در لیست سکشن‌های مجاز نیست.', array('status' => 400));
                }

                // BUG-37: کلید تکراری رد می‌شود
                if (in_array($section_key, $seen_section_keys, true)) {
                    return new WP_Error('duplicate_section_key', 'سکشن تکراری با کلید «' . $section_key . '» ارسال شده است.', array('status' => 400));
                }
                $seen_section_keys[] = $section_key;

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
                            // BUG-37: بازه sortOrder به ۰ تا ۹۹۹ محدود می‌شود
                            $clean[$key] = max(0, min(999, (int) $value));
                            break;
                        case 'key':
                            $clean[$key] = $section_key; // BUG-37: پیش‌تر اعتبارسنجی شده است
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
