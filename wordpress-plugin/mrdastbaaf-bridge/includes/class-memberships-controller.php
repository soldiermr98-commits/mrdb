<?php
/**
 * کنترلر پلن‌های عضویت، استعلام وضعیت اشتراک دستی و داده‌های کاربر (/me/*)
 *
 * v1.2.0 (Task 18-a2): اندپوینت‌های NEW قرارداد فرانت —
 *   GET/PUT /me/progress  (پیشرفت و بوکمارک درس‌ها — usermeta mrdastbaaf_progress)
 *   GET /me/orders        (تاریخچه سفارش‌های کاربر از جدول اختصاصی)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Memberships_Controller {

    /** سقف تعداد شناسه درس در هر آرایهٔ پیشرفت */
    const PROGRESS_MAX_ITEMS = 500;
    /** حداکثر طول هر شناسه درس (هم‌خوان با ستون‌های varchar جداول) */
    const PROGRESS_MAX_ITEM_LENGTH = 191;

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/memberships/plans', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_plans'),
            'permission_callback' => '__return_true',
        ));

        // v1.2.0: permission مشترک — احراز صریح Bearer پیش از چک لاگین (یافتهٔ ۰-۲)
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/memberships/my-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_my_status'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));

        // v1.2.0: پیشرفت درس‌ها — GET خواندن، PUT جایگزینی (قرارداد replace فرانت ۱۸-b)
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/me/progress', array(
            'methods' => array('GET', 'PUT'),
            'callback' => array($this, 'handle_user_progress'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));

        // v1.2.0: تاریخچه سفارش‌های کاربر — داشبورد کاربر در حالت Real از همین می‌خواند
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/me/orders', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_orders'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
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

    /**
     * v1.2.0: پیشرفت درس‌های کاربر — GET خواندن / PUT جایگزینی
     *
     * قرارداد فرانت (progressService در 18-b): PUT هر پارامترِ ارسال‌شده را «کامل
     * جایگزین» می‌کند (نه ادغام)؛ پارامترِ غایب مقدار فعلی را حفظ می‌کند. ذخیره در
     * usermeta mrdastbaaf_progress به شکل JSON. اگر هیچ پارامتری ارسال نشده باشد
     * وضعیت فعلی بدون نوشتن برگردانده می‌شود.
     *
     * @return WP_REST_Response|WP_Error
     */
    public function handle_user_progress(WP_REST_Request $request) {
        if ('PUT' === $request->get_method()) {
            return $this->update_user_progress($request);
        }
        return rest_ensure_response($this->get_user_progress());
    }

    /**
     * خواندن وضعیت پیشرفت از usermeta — هر دو آرایه همیشه حاضرند
     * (غایب = آرایهٔ خالی) تا نرمال‌ساز فرانت به کلیدهای ناموجود برخورد نکند.
     *
     * @return array
     */
    private function get_user_progress() {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return array('completedLessonIds' => array(), 'bookmarkedLessonIds' => array());
        }

        $raw = get_user_meta($user_id, 'mrdastbaaf_progress', true);
        $data = (is_string($raw) && '' !== $raw) ? json_decode($raw, true) : array();
        if (!is_array($data)) {
            $data = array(); // JSON خراب/دستکاری‌شده = صفرِ تمیز (fail-safe)
        }

        return array(
            'completedLessonIds'  => $this->clean_progress_ids(isset($data['completedLessonIds']) ? $data['completedLessonIds'] : array()),
            'bookmarkedLessonIds' => $this->clean_progress_ids(isset($data['bookmarkedLessonIds']) ? $data['bookmarkedLessonIds'] : array()),
        );
    }

    /**
     * ذخیرهٔ پیشرفت — replace semantics + اعتبارسنجی سخت
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    private function update_user_progress(WP_REST_Request $request) {
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('unauthorized', 'برای ثبت پیشرفت ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
        }

        $current = $this->get_user_progress();

        // get_param برای پارامتر غایب null برمی‌گرداند (JSON null هم همین‌جا خوانده می‌شود)
        $completed_param = $request->get_param('completedLessonIds');
        $bookmarked_param = $request->get_param('bookmarkedLessonIds');

        if (null === $completed_param && null === $bookmarked_param) {
            // هیچ پارامتری ارسال نشده — وضعیت فعلی بدون نوشتن برگردانده می‌شود
            return rest_ensure_response($current);
        }

        $stored = array(
            'completedLessonIds'  => $current['completedLessonIds'],
            'bookmarkedLessonIds' => $current['bookmarkedLessonIds'],
        );

        if (null !== $completed_param) {
            $validated = $this->validate_progress_array($completed_param, 'completedLessonIds');
            if (is_wp_error($validated)) {
                return $validated;
            }
            $stored['completedLessonIds'] = $validated;
        }

        if (null !== $bookmarked_param) {
            $validated = $this->validate_progress_array($bookmarked_param, 'bookmarkedLessonIds');
            if (is_wp_error($validated)) {
                return $validated;
            }
            $stored['bookmarkedLessonIds'] = $validated;
        }

        // ذخیرهٔ JSON با یونیکد فارسی بدون escape
        update_user_meta($user_id, 'mrdastbaaf_progress', wp_json_encode($stored, JSON_UNESCAPED_UNICODE));

        return rest_ensure_response($stored);
    }

    /**
     * اعتبارسنجی آرایهٔ پیشرفت — فقط رشته‌های ≤۱۹۱ کاراکتر، حداکثر ۵۰۰ مورد؛
     * نقض → WP_Error invalid_progress با پیام فارسی.
     *
     * @param mixed  $value مقدار پارامتر
     * @param string $label نام پارامتر برای پیام خطا
     * @return array|WP_Error
     */
    private function validate_progress_array($value, $label) {
        if (!is_array($value)) {
            return new WP_Error('invalid_progress', sprintf('پارامتر %s باید آرایه‌ای از شناسه‌های درس باشد.', $label), array('status' => 400));
        }
        if (count($value) > self::PROGRESS_MAX_ITEMS) {
            return new WP_Error('invalid_progress', sprintf('پارامتر %s حداکثر می‌تواند ۵۰۰ شناسه داشته باشد.', $label), array('status' => 400));
        }
        $clean = array();
        foreach ($value as $item) {
            if (!is_string($item) || mb_strlen($item) > self::PROGRESS_MAX_ITEM_LENGTH) {
                return new WP_Error('invalid_progress', sprintf('هر شناسهٔ درس در %s باید رشته‌ای با حداکثر ۱۹۱ کاراکتر باشد.', $label), array('status' => 400));
            }
            $item = sanitize_text_field($item);
            if ('' === $item) {
                continue; // رشتهٔ خالی پس از پالایش حذف می‌شود
            }
            $clean[] = $item;
        }
        return array_values(array_unique($clean));
    }

    /**
     * پالایش خواندنی آرایهٔ پیشرفت (GET و فال‌بک ذخیرهٔ قدیمی) — فقط رشته،
     * پاکسازی sanitize_text_field، سقف ۵۰۰ مورد، بازچینی کلیدها.
     *
     * @param mixed $value
     * @return array آرایهٔ رشته‌ای
     */
    private function clean_progress_ids($value) {
        if (!is_array($value)) {
            return array();
        }
        $clean = array();
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue; // فقط رشته‌ها
            }
            $item = sanitize_text_field($item);
            if ('' === $item || mb_strlen($item) > self::PROGRESS_MAX_ITEM_LENGTH) {
                continue;
            }
            $clean[] = $item;
            if (count($clean) >= self::PROGRESS_MAX_ITEMS) {
                break;
            }
        }
        return array_values($clean);
    }

    /**
     * v1.2.0: تاریخچه سفارش‌های کاربر جاری — داشبورد کاربر در حالت Real از این
     * اندپوینت می‌خواند (localStorage هرگز ledger پروداکشن نیست).
     *
     * خروجی: حداکثر ۵۰ سفارش آخر (جدیدترین اول). یادداشت ادمین (adminNote) فقط
     * برای فیشِ کارت‌به‌کارتِ «ردشده»ِ خود کاربر برمی‌گردد (شفافیت دلیل رد) و
     * در هیچ وضعیت دیگری افشا نمی‌شود.
     *
     * @return WP_REST_Response
     */
    public function handle_get_orders() {
        global $wpdb;
        $user_id = get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error('unauthorized', 'برای مشاهده سفارش‌ها ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
        }

        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';

        // LEFT JOIN فیش‌ها فقط برای آوردن admin_review_note — شناسهٔ کاربر prepare شده
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id, o.order_number, o.item_title, o.amount_toman, o.gateway, o.status,
                    o.created_at, o.verified_at, o.zibal_ref_number, t.admin_review_note
             FROM $table_orders o
             LEFT JOIN $table_transfers t ON t.order_id = o.id
             WHERE o.user_id = %d
             ORDER BY o.created_at DESC
             LIMIT 50",
            $user_id
        ));

        $list = array();
        foreach ($rows as $row) {
            $item = array(
                'id' => (string) $row->id,
                'orderNumber' => (string) $row->order_number,
                'itemTitle' => (string) $row->item_title,
                'amountToman' => (int) $row->amount_toman,
                'gateway' => (string) $row->gateway,
                'status' => (string) $row->status,
                'createdAt' => (string) $row->created_at,
                'verifiedAt' => !empty($row->verified_at) ? (string) $row->verified_at : null,
                'refNumber' => !empty($row->zibal_ref_number) ? (string) $row->zibal_ref_number : null,
            );

            // یادداشت ادمین فقط در وضعیت rejected فیش کارت‌به‌کارت — کلید در سایر وضعیت‌ها حذف است
            if ('card_to_card' === $row->gateway && 'rejected' === $row->status && !empty($row->admin_review_note)) {
                $item['adminNote'] = (string) $row->admin_review_note;
            }

            $list[] = $item;
        }

        return rest_ensure_response(array('orders' => $list));
    }
}
