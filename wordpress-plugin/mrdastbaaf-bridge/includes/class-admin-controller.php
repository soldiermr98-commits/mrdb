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
        // v1.2.0: همهٔ روت‌های ادمین با permission مشترک (احراز صریح Bearer + manage_options)
        // — قبلاً فقط current_user_can بود و اگر فیلتر determine_current_user دیر فایر
        // می‌شد، درخواست Bearer ادمین به‌اشتباه 401 می‌شد.
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/analytics', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_analytics'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/receipts', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_pending_receipts'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/receipts/(?P<id>[a-zA-Z0-9_-]+)/review', array(
            'methods' => 'PUT',
            'callback' => array($this, 'handle_review_receipt'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/plans/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'PUT',
            'callback' => array($this, 'handle_update_plan'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/homepage-sections', array(
            'methods' => array('GET', 'PUT'),
            'callback' => array($this, 'handle_homepage_sections'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));

        // v1.1.0: CRUD درس‌ها و ویدیوها از صفحهٔ پیشخوان «درس‌ها و ویدیوها»
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/lessons', array(
            'methods' => array('GET', 'POST'),
            'callback' => array($this, 'handle_admin_lessons'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/admin/lessons/(?P<id>[0-9]+)', array(
            'methods' => array('PUT', 'DELETE'),
            'callback' => array($this, 'handle_admin_lesson_mutation'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_admin'),
        ));
    }

    /**
     * اعتبارسنجی سطح دسترسی مدیر کل وردپرس — v1.2.0: به permission مشترک احراز Bearer
     * واگذار شد (متد برای سازگاری فراخوانی‌های داخلی حفظ شده است).
     */
    public function check_admin_permission() {
        return MrDastbaaf_Auth_Controller::permission_admin();
    }

    /**
     * v1.2.0: ردپای ممیزی — درج رکورد در جدول mrdastbaaf_audit_log (اسکیما ۱.۳.۰).
     * شکست درج هرگز جریان اصلی را قطع نمی‌کند (فقط لاگ سرور) — ممیزی مکمل است،
     * نه پیش‌نیاز عملیات.
     *
     * @param string      $action      کد اقدام (مثلاً receipt_approved)
     * @param string      $object_type نوع موضوع (order/user/...)
     * @param string|int  $object_id   شناسهٔ موضوع
     * @param string|null $details     توضیح اختیاری
     * @param int|null    $actor_id    عامل اقدام (پیش‌فرض: کاربر جاری)
     * @return void
     */
    public static function write_audit($action, $object_type, $object_id, $details = null, $actor_id = null) {
        global $wpdb;
        $table_audit = $wpdb->prefix . 'mrdastbaaf_audit_log';

        if (null === $actor_id) {
            $actor_id = (int) get_current_user_id();
        }

        $inserted = $wpdb->insert(
            $table_audit,
            array(
                'actor_id'    => (int) $actor_id,
                'action'      => substr(sanitize_key((string) $action), 0, 64),
                'object_type' => substr(sanitize_key((string) $object_type), 0, 32),
                'object_id'   => substr(sanitize_text_field((string) $object_id), 0, 64),
                'details'     => (null === $details) ? null : sanitize_textarea_field((string) $details),
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );

        if (false === $inserted) {
            error_log('MrDastbaaf: audit log insert failed for action ' . (string) $action . ' — ' . $wpdb->last_error);
        }
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
     * تایید یا رد فیش واریزی توسط مدیر (اندپوینت REST)
     *
     * v1.2.1: این متد حالا پوستهٔ نازکِ هستهٔ مشترک review_receipt_decision() است —
     * صفحهٔ پیشخوان «بررسی فیش‌ها» هم با دقیقاً همین هسته کار می‌کند تا گذار وضعیت،
     * پیام‌ها و ردپای ممیزی بین REST و پیشخوان هرگز واگرا نشود.
     */
    public function handle_review_receipt(WP_REST_Request $request) {
        $result = self::review_receipt_decision(
            (string) $request->get_param('id'),
            (string) $request->get_param('decision'),
            (string) $request->get_param('admin_note')
        );

        if (is_wp_error($result)) {
            // کد خطا/وضعیت HTTP عیناً مانند نسخه‌های پیشین برگردانده می‌شود
            return $result;
        }

        return rest_ensure_response($result);
    }

    /**
     * v1.2.1: هستهٔ مشترک تایید/رد فیش — هم REST (اندپوینت /admin/receipts/{id}/review)
     * و هم صفحهٔ پیشخوان «بررسی فیش‌ها» از همین متد استفاده می‌کنند.
     *
     * v1.2.0 (یافتهٔ ۱-۱۰): ادعای اتمیک به verifying تغییر کرد (نه completed) تا
     * وضعیت میانی «در حال پردازش» گمراه‌کننده نباشد؛ رد با UPDATE مشروط + بررسی
     * نتیجه انجام می‌شود؛ رکورد انتقال فقط پس از گذار موفق وضعیت به‌روز می‌شود؛
     * و همهٔ اقدامات با ردپای ممیزی ثبت می‌شوند. رفتار نسخهٔ ۱.۲.۰ (همان کدهای خطا،
     * وضعیت‌ها و پیام‌ها) کلمه‌به‌کلمه حفظ شده است.
     *
     * ورودی‌ها اینجا sanitize می‌شوند؛ فراخوانندهٔ پیشخوان فقط مقدار unslash‌شده
     * می‌دهد (REST مقادیر get_param از پیش unslash هستند).
     *
     * @param string   $order_id    شناسه سفارش
     * @param string   $decision    'approve' یا 'reject'
     * @param string   $admin_note  یادداشت ادمین (برای رد الزامی است — قرارداد REST)
     * @param int|null $reviewer_id عامل اقدام (پیش‌فرض: کاربر جاری)
     * @return array|WP_Error در موفقیت آرایهٔ {success: true, message: string}؛
     *                        در خطا WP_Error با کد/وضعیت همان REST
     */
    public static function review_receipt_decision($order_id, $decision, $admin_note, $reviewer_id = null) {
        global $wpdb;
        $order_id = sanitize_text_field((string) $order_id);
        $decision = sanitize_text_field((string) $decision); // 'approve' یا 'reject'
        $admin_note = sanitize_textarea_field((string) $admin_note);

        if (null === $reviewer_id) {
            $reviewer_id = get_current_user_id();
        }

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
        // $reviewer_id پیش‌تر در ابتدای متد تعیین شده است (پارامتر یا کاربر جاری)

        if ($decision === 'reject') {
            if (empty($admin_note)) {
                return new WP_Error('missing_reason', 'درج علت رد فیش الزامی است.', array('status' => 400));
            }

            // v1.2.0: UPDATE مشروط به pending + بررسی نتیجه — قبلاً بدون شرط بود و
            // رقابت دو ادمین بی‌صدا دوبار «رد» ثبت می‌کرد.
            $rejected = $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'rejected', updated_at = %s WHERE id = %s AND status = 'pending'",
                $now, $order_id
            ));
            if (false === $rejected) {
                error_log('MrDastbaaf: receipt reject update failed for order ' . $order_id . ' — ' . $wpdb->last_error);
                return new WP_Error('db_error', 'ثبت رد فیش ناموفق بود؛ لطفاً دوباره تلاش کنید.', array('status' => 500));
            }
            if (0 === $rejected) {
                return new WP_Error('order_already_processed', 'این سفارش هم‌اکنون پردازش شده است', array('status' => 409));
            }

            // رکورد انتقال فقط پس از گذار موفق وضعیت به‌روز می‌شود
            $wpdb->update($table_transfers, array(
                'admin_review_note' => $admin_note,
                'reviewer_admin_id' => $reviewer_id,
                'reviewed_at' => $now,
            ), array('order_id' => $order_id));

            // v1.2.0: ردپای ممیزی
            self::write_audit('receipt_rejected', 'order', $order_id, 'رد فیش: ' . $admin_note, $reviewer_id);

            return array('success' => true, 'message' => 'فیش واریزی با ثبت علت رد گردید.');
        }

        if ($decision === 'approve') {
            // BUG-37 / v1.2.0: ادعای اتمیک سفارش پیش از grant — دو ادمین هم‌زمان (یا
            // درخواست تکراری) نمی‌توانند اشتراک را دوباره فعال کنند. وضعیت میانی
            // «verifying» است (نه completed) تا سفارشِ در-حال-پردازش قابل تفکیک باشد.
            $claimed = $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'verifying', updated_at = %s WHERE id = %s AND status = 'pending'",
                $now, $order_id
            ));
            if (1 !== (int) $claimed) {
                return new WP_Error('order_already_processed', 'این سفارش هم‌اکنون پردازش شده است؛ فهرست فیش‌ها را به‌روزرسانی کنید.', array('status' => 409));
            }

            /**
             * SECURITY FIX (BUG-14 caller): فعال‌سازی اشتراک پیش از نهایی‌سازی انجام
             * می‌شود. اگر grant شکست بخورد (پلن حذف‌شده یا خطای DB)، سفارش به pending
             * برمی‌گردد و در صف باقی می‌ماند — پول کاربر بدون اشتراک گم نمی‌شود.
             */
            $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
            $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_plans WHERE id = %s", $order->plan_id));

            $membership_result = MrDastbaaf_Membership_Helper::grant_or_extend($order->user_id, $plan, $order->id);
            if (false === $membership_result || !is_array($membership_result)) {
                // BUG-37: برگشت وضعیت به pending — فیش دوباره در صف بررسی قرار می‌گیرد
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                    $now, $order_id
                ));
                return new WP_Error('membership_grant_failed', 'تایید انجام شد اما فعال‌سازی اشتراک ناموفق بود؛ سفارش در صف بررسی باقی ماند. لطفاً پلن متناظر سفارش را بررسی کنید.', array('status' => 409));
            }

            if (isset($membership_result['mode']) && 'blocked_lower_tier' === $membership_result['mode']) {
                // v1.2.0: خرید سطح پایین‌تر از اشتراک فعال — سفارش به pending برمی‌گردد
                // (نه completed) تا کاربر ابتدا تصمیمش را با پشتیبانی روشن کند.
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table_orders SET status = 'pending', updated_at = %s WHERE id = %s AND status = 'verifying'",
                    $now, $order_id
                ));
                return new WP_Error('lower_tier_blocked', $membership_result['message'], array('status' => 409));
            }

            // گذار نهایی به completed فقط از verifying — false = خطای DB (500)؛
            // 0 = گذار هم‌زمان رخ داده (409).
            $completed_updated = $wpdb->query($wpdb->prepare(
                "UPDATE $table_orders SET status = 'completed', verified_at = %s, updated_at = %s WHERE id = %s AND status = 'verifying'",
                $now, $now, $order_id
            ));
            if (false === $completed_updated) {
                error_log('MrDastbaaf: receipt final completed update failed for order ' . $order_id . ' — ' . $wpdb->last_error);
                return new WP_Error('completion_update_failed', 'ثبت نهایی تایید ناموفق بود؛ سفارش در وضعیت پردازش باقی ماند و دوباره بررسی می‌شود.', array('status' => 500));
            }
            if (0 === $completed_updated) {
                return new WP_Error('order_already_processed', 'این سفارش هم‌اکنون پردازش شده است؛ فهرست فیش‌ها را به‌روزرسانی کنید.', array('status' => 409));
            }

            // رکورد انتقال فقط پس از گذار موفق وضعیت به‌روز می‌شود
            $wpdb->update($table_transfers, array(
                'admin_review_note' => $admin_note ?: 'فیش با موفقیت تایید شد.',
                'reviewer_admin_id' => $reviewer_id,
                'reviewed_at' => $now,
            ), array('order_id' => $order_id));

            // v1.2.0: ردپای ممیزی
            self::write_audit('receipt_approved', 'order', $order_id, sprintf('تایید فیش — mode=%s expires=%s', $membership_result['mode'], $membership_result['expires_at']), $reviewer_id);

            do_action('mrdastbaaf_membership_granted', $order->user_id, $membership_result);

            $mode_message = ($membership_result['mode'] === 'extended'
                ? 'فیش تایید و اشتراک کاربر با حفظ روزهای باقی‌مانده تمدید شد.'
                : ($membership_result['mode'] === 'upgraded'
                    ? 'فیش تایید و اشتراک کاربر با تبدیل ارزش روزهای باقی‌مانده ارتقا یافت.'
                    : 'فیش تایید و اشتراک کاربر فعال گردید.'));

            return array(
                'success' => true,
                'message' => $mode_message,
            );
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

                                /**
                                 * SECURITY FIX (v1.2.0 — یافتهٔ ۲-۱۸): مقادیر URL-مانند — مقدار
                                 * رشته‌ای که شکل «scheme://…» دارد یا با www. شروع می‌شود یا کلیدش
                                 * url/link/href/src است، با esc_url_raw + لیست سفید scheme
                                 * (http/https/mailto/tel) اعتبارسنجی می‌شود؛ در غیر این صورت کلید
                                 * حذف می‌شود (javascript:/data: و هر scheme غیرمجاز رد می‌شود).
                                 * خروجی فقط از React JSX رندر می‌شود (بدون innerHTML) اما دفاع در
                                 * لایهٔ ذخیره‌سازی الزامی است.
                                 */
                                foreach ($clean_config as $ck => $cv) {
                                    if (is_string($cv) && $this->is_url_like_config($ck, $cv)) {
                                        $validated = esc_url_raw($cv, array('http', 'https', 'mailto', 'tel'));
                                        $scheme = (string) wp_parse_url($validated, PHP_URL_SCHEME);
                                        if ('' === $validated || !in_array($scheme, array('http', 'https', 'mailto', 'tel'), true)) {
                                            unset($clean_config[$ck]); // کلید نامعتبر حذف می‌شود
                                            error_log('MrDastbaaf: configData URL value dropped (invalid scheme/host) — key: ' . $ck);
                                        } else {
                                            $clean_config[$ck] = $validated;
                                        }
                                    }
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

    /**
     * v1.2.0: تشخیص مقدار URL-مانند در configData — شرط‌ها:
     *  ۱) کلید حاوی url/link/href/src باشد (غیرحساس به بزرگی/کوچکی) یا
     *  ۲) مقدار با «scheme://» شروع شود یا با «www.» آغاز شود.
     *
     * @param string $key کلید کانفیگ
     * @param string $value مقدار رشته‌ای
     * @return bool
     */
    private function is_url_like_config($key, $value) {
        $key_lower = strtolower((string) $key);
        if (false !== strpos($key_lower, 'url') || false !== strpos($key_lower, 'link')
            || false !== strpos($key_lower, 'href') || false !== strpos($key_lower, 'src')) {
            return true;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+\-.]*:\/\//', (string) $value)) {
            return true; // شکل scheme://…
        }
        return (0 === strpos(strtolower((string) $value), 'www.'));
    }

    /**
     * ===================== v1.1.0: CRUD درس‌ها و ویدیوها =====================
     *
     * نکتهٔ امنیتی: فیلد videoUrl فقط در همین CRUD ادمین برگردانده می‌شود؛
     * اندپوینت‌های عمومی دوره‌ها هرگز آدرس ویدیو را افشا نمی‌کنند.
     */

    public function handle_admin_lessons(WP_REST_Request $request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست؛ مدیریت درس‌ها در دسترس نیست.', array('status' => 503));
        }

        if ('POST' === $request->get_method()) {
            return $this->admin_lesson_create($request);
        }

        return $this->admin_lessons_list($request);
    }

    public function handle_admin_lesson_mutation(WP_REST_Request $request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست؛ مدیریت درس‌ها در دسترس نیست.', array('status' => 503));
        }

        if ('DELETE' === $request->get_method()) {
            return $this->admin_lesson_delete($request);
        }

        return $this->admin_lesson_update($request);
    }

    /**
     * اعتبارسنجی محصول ووکامرس مقصد
     *
     * @param int $product_id
     * @return true|WP_Error
     */
    private function validate_woocommerce_product($product_id) {
        if ($product_id <= 0) {
            return new WP_Error('invalid_product', 'شناسهٔ محصول معتبر نیست.', array('status' => 400));
        }

        $post = get_post($product_id);
        if (!$post || 'product' !== $post->post_type) {
            return new WP_Error('invalid_product', 'محصول ووکامرس متناظر یافت نشد.', array('status' => 400));
        }

        return true;
    }

    /**
     * فهرست درس‌های محصول — مرتب با sort_order
     */
    private function admin_lessons_list(WP_REST_Request $request) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        $product_id = absint($request->get_param('product_id'));
        $product_check = $this->validate_woocommerce_product($product_id);
        if (is_wp_error($product_check)) {
            return $product_check;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_lessons WHERE product_id = %d ORDER BY sort_order ASC, id ASC",
            $product_id
        ));

        $lessons = array();
        foreach ($rows as $row) {
            $lessons[] = $this->format_admin_lesson($row);
        }

        return rest_ensure_response(array('lessons' => $lessons));
    }

    /**
     * ساخت درس جدید برای محصول
     */
    private function admin_lesson_create(WP_REST_Request $request) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        $product_id = absint($request->get_param('product_id'));
        $product_check = $this->validate_woocommerce_product($product_id);
        if (is_wp_error($product_check)) {
            return $product_check;
        }

        $fields = $this->build_lesson_fields($request, false);
        if (is_wp_error($fields)) {
            return $fields;
        }

        $fields['product_id'] = $product_id;

        $inserted = $wpdb->insert($table_lessons, $fields);
        if (false === $inserted) {
            error_log('MrDastbaaf: lesson insert failed — ' . $wpdb->last_error);
            return new WP_Error('db_error', 'ثبت درس ناموفق بود؛ لطفاً دوباره تلاش کنید.', array('status' => 500));
        }

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_lessons WHERE id = %d", $wpdb->insert_id));

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'درس جدید ثبت شد.',
            'lesson' => $row ? $this->format_admin_lesson($row) : null,
        ));
    }

    /**
     * ویرایش درس — فیلدهای ارسالی به‌روزرسانی می‌شوند؛ فیلدهای خالی حفظ می‌شوند
     */
    private function admin_lesson_update(WP_REST_Request $request) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        $lesson_id = absint($request->get_param('id'));

        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_lessons WHERE id = %d", $lesson_id));
        if (!$existing) {
            return new WP_Error('not_found', 'درس مورد نظر یافت نشد.', array('status' => 404));
        }

        $fields = $this->build_lesson_fields($request, true);
        if (is_wp_error($fields)) {
            return $fields;
        }
        if (empty($fields)) {
            return new WP_Error('no_data', 'داده‌ای برای به‌روزرسانی ارسال نشد.', array('status' => 400));
        }

        $updated = $wpdb->update($table_lessons, $fields, array('id' => $lesson_id));
        if (false === $updated) {
            error_log('MrDastbaaf: lesson update failed — ' . $wpdb->last_error);
            return new WP_Error('db_error', 'به‌روزرسانی درس ناموفق بود؛ لطفاً دوباره تلاش کنید.', array('status' => 500));
        }

        $row = (0 === $updated) ? $existing : $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_lessons WHERE id = %d", $lesson_id));

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'درس به‌روزرسانی شد.',
            'lesson' => $row ? $this->format_admin_lesson($row) : null,
        ));
    }

    /**
     * حذف درس
     */
    private function admin_lesson_delete(WP_REST_Request $request) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        $lesson_id = absint($request->get_param('id'));

        $deleted = $wpdb->delete($table_lessons, array('id' => $lesson_id), array('%d'));
        if (false === $deleted) {
            error_log('MrDastbaaf: lesson delete failed — ' . $wpdb->last_error);
            return new WP_Error('db_error', 'حذف درس ناموفق بود؛ لطفاً دوباره تلاش کنید.', array('status' => 500));
        }
        if (0 === $deleted) {
            return new WP_Error('not_found', 'درس مورد نظر یافت نشد.', array('status' => 404));
        }

        return rest_ensure_response(array('success' => true, 'message' => 'درس حذف شد.'));
    }

    /**
     * پاکسازی و اعتبارسنجی ورودی درس (create/update مشترک)
     *
     * video_url فقط https:// پذیرفته می‌شود (هاست دانلود dll.mrdastbaaf.com)؛
     * در ویرایش، فیلدهای ارسال‌نشده/خالی دست‌نخورده می‌مانند.
     *
     * @return array|WP_Error فیلدهای مجاز برای ذخیره
     */
    private function build_lesson_fields(WP_REST_Request $request, $is_update) {
        $title = sanitize_text_field((string) $request->get_param('title'));
        $video_url = trim((string) $request->get_param('video_url'));
        $is_free_param = $request->get_param('is_free');
        $tier = sanitize_key((string) $request->get_param('required_tier'));
        $sort_order = $request->get_param('sort_order');

        $fields = array();

        if ('' !== $title) {
            if (mb_strlen($title) > 191) {
                return new WP_Error('invalid_title', 'عنوان درس حداکثر ۱۹۱ کاراکتر است.', array('status' => 400));
            }
            $fields['title'] = $title;
        } elseif (!$is_update) {
            return new WP_Error('invalid_title', 'عنوان درس الزامی است.', array('status' => 400));
        }

        if ('' !== $video_url) {
            // امنیت: فقط نشانی‌های https — هاست دانلود dll.mrdastbaaf.com
            $validated_url = esc_url_raw($video_url);
            if ('' === $validated_url || 0 !== strpos($validated_url, 'https://')) {
                return new WP_Error('invalid_video_url', 'آدرس ویدیو باید یک نشانی معتبر با https:// باشد (هاست دانلود dll.mrdastbaaf.com).', array('status' => 400));
            }

            // v1.2.0 (یافتهٔ ۱-۸): لیست سفید هاست ویدیو — ویدیوی MP4 خام روی هر هاستی
            // قابل لینک‌دادن/سوخت‌سوزی است؛ فقط هاست‌های مجاز پذیرفته می‌شوند. لیست در
            // تنظیمات (mrdastbaaf_video_hosts — یکی در هر خط) قابل گسترش است.
            $host = strtolower((string) wp_parse_url($validated_url, PHP_URL_HOST));
            $allowed_hosts = $this->get_allowed_video_hosts();
            if ('' === $host || !in_array($host, $allowed_hosts, true)) {
                return new WP_Error('video_host_not_allowed', 'فقط ویدیوهای هاست مجاز (dll.mrdastbaaf.com) پذیرفته می‌شوند.', array('status' => 400));
            }

            $fields['video_url'] = $validated_url;
        } elseif (!$is_update) {
            return new WP_Error('invalid_video_url', 'آدرس ویدیوی درس الزامی است و باید با https:// شروع شود.', array('status' => 400));
        }

        if (null !== $is_free_param) {
            $fields['is_free'] = (int) (bool) rest_sanitize_boolean($is_free_param);
        } elseif (!$is_update) {
            $fields['is_free'] = 0;
        }

        if ('' !== $tier) {
            $allowed_tiers = array('bronze', 'silver', 'gold');
            if (!in_array($tier, $allowed_tiers, true)) {
                return new WP_Error('invalid_tier', 'سطح دسترسی درس باید برنزی، نقره‌ای یا طلایی باشد.', array('status' => 400));
            }
            $fields['required_tier'] = $tier;
        } elseif (!$is_update) {
            $fields['required_tier'] = 'bronze';
        }

        if (null !== $sort_order && '' !== $sort_order) {
            if (!is_numeric($sort_order)) {
                return new WP_Error('invalid_sort_order', 'ترتیب نمایش باید عددی باشد.', array('status' => 400));
            }
            $fields['sort_order'] = max(0, min(9999, (int) $sort_order));
        } elseif (!$is_update) {
            $fields['sort_order'] = 0;
        }

        return $fields;
    }

    /**
     * v1.2.0: لیست سفید هاست‌های ویدیو (حروف کوچک) — از آپشن mrdastbaaf_video_hosts
     * (یکی در هر خط) خوانده می‌شود؛ پیش‌فرض فقط dll.mrdastbaaf.com.
     *
     * @return array
     */
    private function get_allowed_video_hosts() {
        $raw = (string) get_option('mrdastbaaf_video_hosts', 'dll.mrdastbaaf.com');
        $hosts = array_filter(array_map('trim', explode("\n", $raw)));
        $hosts = array_map('strtolower', $hosts);
        return array_values(array_filter($hosts));
    }

    /**
     * شکل خروجی درس برای پیشخوان (شامل videoUrl — فقط ادمین)
     */
    private function format_admin_lesson($row) {
        return array(
            'id'            => (int) $row->id,
            'productId'     => (int) $row->product_id,
            'title'         => (string) $row->title,
            'videoUrl'      => (string) $row->video_url,
            'isFree'        => (bool) $row->is_free,
            'requiredTier'  => (string) $row->required_tier,
            'sortOrder'     => (int) $row->sort_order,
            'createdAt'     => (string) $row->created_at,
        );
    }
}
