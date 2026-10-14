<?php
/**
 * کنترلر «صفحات وب‌اپ» (v1.3.3 — NEW)
 *
 * ادمین در پیشخوان وردپرس صفحاتی از سایت را به منوی وب‌اپ اضافه می‌کند و برای
 * هر صفحه سطح دسترسی تعیین می‌کند (همه / اعضای فعال / مدیر کل). این کنترلر
 * تنها خواندنی است؛ نوشتن از admin-post.php در Admin_Pages_Controller انجام
 * می‌شود (همان الگوی پلن‌ها).
 *
 * دسترسی: اندپوینت‌ها عمومی‌اند اما فهرست/محتوا بر اساس سطح جاری فیلتر می‌شود —
 * مهمان فقط «all»، عضو فعال «all + members»، مدیر کل همه. fail-closed: رکورد
 * غیرفعال یا صفحهٔ حذف‌شده/پیش‌نویس هرگز سرو نمی‌شود.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_App_Pages_Controller {

    const ACCESS_ALL = 'all';
    const ACCESS_MEMBERS = 'members';
    const ACCESS_ADMINS = 'admins';

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/app-pages', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_app_pages'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/app-pages/(?P<id>[a-zA-Z0-9_-]+)/content', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_app_page_content'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * سطح دسترسی جاری — v1.3.3
     *
     * Bearer را احراز می‌کند (مهمان هم مجاز است)؛ عضوِ فعال = ردیف
     * active و منقضی‌نشده در جدول عضویت‌ها؛ مدیر کل = manage_options.
     *
     * @return string 'guest' | 'member' | 'admin'
     */
    public static function current_access_level() {
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();
        $user_id = get_current_user_id();

        if ($user_id && user_can($user_id, 'manage_options')) {
            return self::ACCESS_ADMINS;
        }

        if ($user_id) {
            global $wpdb;
            $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';
            $tier = $wpdb->get_var($wpdb->prepare(
                "SELECT tier FROM $table_memberships
                 WHERE user_id = %d AND status = 'active' AND expires_at > %s
                 ORDER BY expires_at DESC LIMIT 1",
                $user_id,
                current_time('mysql')
            ));
            if ($tier) {
                return self::ACCESS_MEMBERS;
            }
        }

        return 'guest';
    }

    /**
     * آیا سطح جاری به سطحِ درخواستیِ رکورد می‌رسد؟
     *
     * @param string $required 'all' | 'members' | 'admins'
     * @param string $current  'guest' | 'member' | 'admin'
     * @return bool
     */
    private static function access_satisfied($required, $current) {
        $required = (string) $required;
        if (self::ACCESS_ALL === $required) {
            return true;
        }
        if (self::ACCESS_MEMBERS === $required) {
            return self::ACCESS_MEMBERS === $current || self::ACCESS_ADMINS === $current;
        }
        if (self::ACCESS_ADMINS === $required) {
            return self::ACCESS_ADMINS === $current;
        }
        return false; // مقدار ناشناخته → fail-closed
    }

    /**
     * خواندن پاکسازی‌شدهٔ آپشن صفحات وب‌اپ
     *
     * @return array
     */
    public static function get_pages_option() {
        $pages = get_option('mrdastbaaf_app_pages', array());
        if (!is_array($pages)) {
            return array();
        }
        $clean = array();
        foreach ($pages as $entry) {
            if (!is_array($entry) || !isset($entry['uid'], $entry['page_id'])) {
                continue;
            }
            $clean[] = array(
                'uid'       => (string) $entry['uid'],
                'page_id'   => (int) $entry['page_id'],
                'title'     => isset($entry['title']) ? (string) $entry['title'] : '',
                'access'    => isset($entry['access']) ? (string) $entry['access'] : self::ACCESS_ALL,
                'is_active' => !empty($entry['is_active']),
                'sort'      => isset($entry['sort']) ? (int) $entry['sort'] : 0,
            );
        }
        return $clean;
    }

    /**
     * GET /app-pages — فهرست صفحات مجازِ سطح جاری (فقط فعال‌ها)
     */
    public function handle_get_app_pages() {
        $level = self::current_access_level();
        $out = array();

        foreach (self::get_pages_option() as $entry) {
            if (empty($entry['is_active'])) {
                continue;
            }
            if (!self::access_satisfied($entry['access'], $level)) {
                continue;
            }
            $out[] = array(
                'id'     => $entry['uid'],
                'title'  => '' !== $entry['title'] ? $entry['title'] : $this->page_title($entry['page_id']),
                'access' => $entry['access'],
                'sortOrder' => $entry['sort'],
            );
        }

        usort($out, function ($a, $b) {
            return $a['sortOrder'] <=> $b['sortOrder'];
        });

        return rest_ensure_response($out);
    }

    /**
     * GET /app-pages/{id}/content — محتوای رندرشدهٔ صفحه (با فیلتر the_content)
     */
    public function handle_get_app_page_content(WP_REST_Request $request) {
        $uid = sanitize_text_field($request->get_param('id'));
        $entry = null;

        foreach (self::get_pages_option() as $candidate) {
            if ($candidate['uid'] === $uid) {
                $entry = $candidate;
                break;
            }
        }

        if (null === $entry || empty($entry['is_active'])) {
            return new WP_Error('not_found', 'صفحهٔ مورد نظر یافت نشد یا غیرفعال است.', array('status' => 404));
        }

        $level = self::current_access_level();
        if (!self::access_satisfied($entry['access'], $level)) {
            return new WP_Error('forbidden', 'دسترسی به این صفحه نیازمند سطح عضویت بالاتری است.', array('status' => 403));
        }

        $page_id = (int) $entry['page_id'];
        $post = get_post($page_id);
        if (!$post || 'page' !== $post->post_type || 'publish' !== $post->post_status) {
            return new WP_Error('not_found', 'صفحهٔ وردپرسی مرتبط یافت نشد یا منتشر نشده است.', array('status' => 404));
        }

        // رندر واقعی صفحه: فیلتر the_content شامل wpautop/do_shortcode/embed است
        $html = apply_filters('the_content', $post->post_content);
        if (!is_string($html)) {
            $html = wpautop(wptexturize((string) $post->post_content));
        }

        $modified = (int) get_post_modified_time('U', true, $post);

        return rest_ensure_response(array(
            'id'     => $entry['uid'],
            'title'  => '' !== $entry['title'] ? $entry['title'] : get_the_title($post),
            'html'   => $html,
            'access' => $entry['access'],
            'updatedAtJalali' => $modified > 0 ? date_i18n('Y/m/d', $modified) : '',
        ));
    }

    /**
     * عنوان صفحهٔ وردپرسی برای fallback برچسب
     */
    private function page_title($page_id) {
        $title = get_the_title((int) $page_id);
        return is_string($title) && '' !== $title ? $title : 'صفحهٔ سایت';
    }
}
