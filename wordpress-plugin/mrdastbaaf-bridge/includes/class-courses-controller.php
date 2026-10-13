<?php
/**
 * کنترلر دوره‌ها و درس‌های نیاس کورس (Nias Course API)
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Courses_Controller {

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/courses', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_courses'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/courses/(?P<id>[a-zA-Z0-9_-]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_single_course'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/lessons/(?P<id>[a-zA-Z0-9_-]+)/playback-ticket', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_get_playback_ticket'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * استعلام لیست دوره‌ها
     */
    public function handle_get_courses(WP_REST_Request $request) {
        /**
         * TODO: اتصال به Custom Post Type اختصاصی افزونه نیاس کورس
         * در صورت فعال بودن نیاس، دوره‌ها معمولاً در post_type='course' یا 'nias_course' ذخیره می‌شوند.
         */
        $category = sanitize_text_field($request->get_param('category'));
        $search = sanitize_text_field($request->get_param('search'));

        // فیلتر و بازگردانی ساختار همگام با تایپ‌های کاتالوگ PWA
        $courses = apply_filters('mrdastbaaf_get_courses', array(), $category, $search);

        return rest_ensure_response($courses);
    }

    /**
     * استعلام جزئیات یک دوره به همراه سرفصل‌ها
     */
    public function handle_get_single_course(WP_REST_Request $request) {
        $course_id = sanitize_text_field($request->get_param('id'));

        $course = apply_filters('mrdastbaaf_get_course_by_id', null, $course_id);
        if (!$course) {
            return new WP_Error('not_found', 'دوره مورد نظر یافت نشد.', array('status' => 404));
        }

        return rest_ensure_response($course);
    }

    /**
     * صدور بلیط امن موقت استریم ویدیو (Signed Playback Ticket)
     */
    public function handle_get_playback_ticket(WP_REST_Request $request) {
        $lesson_id = sanitize_text_field($request->get_param('id'));
        $user_id = get_current_user_id();

        // بررسی اینکه آیا درس پیش‌نمایش رایگان است
        $is_free_preview = apply_filters('mrdastbaaf_is_lesson_free_preview', false, $lesson_id);

        if (!$is_free_preview) {
            if (!$user_id) {
                return new WP_Error('unauthorized', 'برای مشاهده این جلسه ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
            }

            // استعلام سطح اشتراک فعال کاربر
            $has_access = $this->user_has_lesson_access($user_id, $lesson_id);
            if (!$has_access) {
                return new WP_Error('forbidden', 'مشاهده این جلسه نیازمند خرید یا تمدید اشتراک معتبر است.', array('status' => 403));
            }
        }

        $user = get_user_by('ID', $user_id);
        $watermark_text = $user ? ($user->user_login . ' | ID: ' . $user->ID) : 'مهمان | مستر دستباف';

        /**
         * TODO: اتصال به کلید خصوصی سرور ویدیو (ابرآروان / ویدیو سی‌دی‌ان) جهت تولید Signed URL
         */
        $ticket_id = 'tkt_' . wp_generate_password(16, false);

        return rest_ensure_response(array(
            'ticketId' => $ticket_id,
            'lessonId' => $lesson_id,
            'expiresInSeconds' => 900, // ۱۵ دقیقه اعتبار
            'watermarkText' => $watermark_text,
            'watermarkUserId' => $user_id ? (string)$user_id : 'guest',
            'streamType' => 'hls',
        ));
    }

    private function user_has_lesson_access($user_id, $lesson_id) {
        if (user_can($user_id, 'manage_options')) {
            return true; // ادمین به تمام جلسات دسترسی دارد
        }

        global $wpdb;
        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';
        $now = current_time('mysql');

        $active_tier = $wpdb->get_var($wpdb->prepare(
            "SELECT tier FROM $table_memberships 
             WHERE user_id = %d AND status = 'active' AND expires_at > %s 
             ORDER BY expires_at DESC LIMIT 1",
            $user_id, $now
        ));

        if (!$active_tier) {
            return false;
        }

        // بررسی سلسله‌مراتب دسترسی: طلایی > نقره‌ای > برنزی
        $required_tier = apply_filters('mrdastbaaf_get_lesson_required_tier', 'bronze', $lesson_id);
        $ranks = array('bronze' => 1, 'silver' => 2, 'gold' => 3);

        $user_rank = isset($ranks[$active_tier]) ? $ranks[$active_tier] : 0;
        $req_rank = isset($ranks[$required_tier]) ? $ranks[$required_tier] : 1;

        return $user_rank >= $req_rank;
    }
}
