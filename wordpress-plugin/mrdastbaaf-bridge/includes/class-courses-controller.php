<?php
/**
 * کنترلر دوره‌های واقعی ووکامرسی و درس‌های جدول اختصاصی (v1.1.0 — بازنویسی داخلی)
 *
 * تا ۱.۰.۳ لیست دوره‌ها فقط یک placeholder با فیلترهای apply_filters بود. از ۱.۱.۰
 * دوره‌ها از محصولات ووکامرس (post_type=product در دستهٔ تنظیم‌شده) خوانده می‌شوند
 * و درس‌ها از جدول اختصاصی wp_mrdastbaaf_lessons.
 *
 * امنیت: آدرس ویدیو (video_url) هرگز در اندپوینت‌های لیست/جزئیات دوره‌ها
 * برنمی‌گردد؛ فقط اندپوینت POST /lessons/{id}/playback با بلیط معتبر (transient
 * ۱۵ دقیقه‌ای مقید به کاربر و درس) آن را تحویل می‌دهد — fail-closed.
 * شکل اندپوینت‌های عمومی نسبت به ۱.۰.۳ ثابت مانده است.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Courses_Controller {

    const TICKET_TTL = 900; // ۱۵ دقیقه اعتبار بلیط پخش

    public function register_routes() {
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/courses', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_courses'),
            'permission_callback' => '__return_true',
        ));

        // v1.3.3: جزئیات با شناسهٔ عددی محصول (bulletproof) — کلاینت productId را
        // از پاسخ لیست می‌گیرد و مستقیم با عدد می‌پرسد؛ هیچ وابستگی به شکل اسلاگ نیست.
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/courses/by-id/(?P<id>[0-9]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_single_course_by_id'),
            'permission_callback' => '__return_true',
        ));

        // v1.4.0: دوره‌های من — فقط محصولاتِ دستهٔ دوره‌ها که کاربر جاری در سفارش
        // پرداخت‌شدهٔ ووکامرس دارد (همان استراتژی‌های خرید تک‌دوره‌ای ۱.۳.۵)
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/my/courses', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_my_courses'),
            'permission_callback' => array('MrDastbaaf_Auth_Controller', 'permission_logged_in'),
        ));

        // v1.3.2: الگوی id از [a-zA-Z0-9_-] به [^/]+ گسترش یافت — وردپرس اسلاگ‌های
        // فارسی را با utf8_uri_encode به شکل «%d8%af...» ذخیره می‌کند و کاراکتر «%»
        // (و خود حروف فارسی) در الگوی قبلی جور نمی‌شد → همهٔ درخواست‌های جزئیات دوره
        // با «rest_no_route» ۴۰۴ می‌شدند (دوره‌ها در لیست دیده می‌شدند ولی بازشان نمی‌شد).
        // v1.3.3: جستجوی اسلاگ از get_posts (با واسطهٔ WP_Query) به کوئری مستقیم
        // $wpdb منتقل شد تا هیچ فیلتر/سانیتایز لایهٔ پرس‌وجو نتواند نتیجه را تغییر دهد.
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/courses/(?P<id>[^/]+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_single_course'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/lessons/(?P<id>[a-zA-Z0-9_-]+)/playback-ticket', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_get_playback_ticket'),
            'permission_callback' => '__return_true',
        ));

        // v1.1.0: اندپوینت پخش — بلیط موقت را به آدرس ویدیو تبدیل می‌کند (fail-closed)
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/lessons/(?P<id>[a-zA-Z0-9_-]+)/playback', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_lesson_playback'),
            'permission_callback' => '__return_true',
        ));

        // v1.7.0: استریم پروکسی — URL واقعی ویدیو هرگز به کلاینت نمی‌رسد.
        // <video src="/wp-json/.../stream?token=..."> به جای URL مستقیم CDN.
        // PHP ویدیو را از CDN می‌خواند و byte-by-byte به کلاینت stream می‌کند.
        // پشتیبانی از Range requests برای seek. توکن کوتاه‌عمر (۶۰ ثانیه).
        register_rest_route(MRDASTBAAF_API_NAMESPACE, '/lessons/(?P<id>[a-zA-Z0-9_-]+)/stream', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_lesson_stream_proxy'),
            'permission_callback' => '__return_true',
        ));
    }

    /**
     * استعلام لیست دوره‌ها از محصولات ووکامرس
     */
    public function handle_get_courses(WP_REST_Request $request) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست؛ امکان نمایش دوره‌ها وجود ندارد.', array('status' => 503));
        }

        $category = sanitize_text_field($request->get_param('category'));
        $search = sanitize_text_field($request->get_param('search'));

        // دستهٔ دوره‌ها: پارامتر درخواست مقدم است، سپس slug تنظیم‌شده در تنظیمات افزونه
        $category_slug = ('' !== $category) ? $category : (string) get_option('mrdastbaaf_course_category', 'courses');

        $query_args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        );

        if ('' !== $search) {
            $query_args['s'] = $search;
        }

        if ('' !== $category_slug) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'slug',
                    'terms' => $category_slug,
                ),
            );
        }

        $query = new WP_Query($query_args);

        // شمارش درس‌های همهٔ محصولات در یک پرس‌وجو (به‌جای N پرس‌وجو)
        $product_ids = array();
        foreach ($query->posts as $product_post) {
            $product_ids[] = (int) $product_post->ID;
        }
        $lesson_counts = $this->get_lesson_counts($product_ids);

        // v1.4.0: جمع دقیقه‌های هر دوره — یک پرس‌وجوی batched (SUM ... GROUP BY)
        $lesson_durations = $this->sum_lesson_durations($product_ids);

        // v1.4.0: پرچم owned در لیست — مجموعهٔ محصولاتِ خریداری‌شدهٔ کاربر یک‌بار
        // محاسبه می‌شود (بدون کش؛ خریدِ همین لحظه بلافاصله دیده می‌شود) و برای
        // کل لیست فقط مقایسهٔ in_array است — O(1) کوئری سفارش برای همهٔ دوره‌ها.
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();
        $viewer_id = get_current_user_id();
        $owned_product_ids = $viewer_id ? $this->get_paid_product_ids_for_user($viewer_id) : array();

        $courses = array();
        foreach ($query->posts as $product_post) {
            $course_product_id = (int) $product_post->ID;
            $course = $this->map_product_to_course(
                $product_post,
                isset($lesson_durations[$course_product_id]) ? (int) $lesson_durations[$course_product_id] : 0
            );
            $counts = isset($lesson_counts[$course_product_id]) ? $lesson_counts[$course_product_id] : array('total' => 0, 'free' => 0);
            $course['lessonsCount'] = (int) $counts['total'];
            // v1.3.3: تعداد درس‌های پیش‌نمایش رایگان — کارت دوره در اپ نشان «پیش‌نمایش رایگان» می‌گیرد
            $course['freePreviewCount'] = (int) $counts['free'];
            // v1.4.0: این دوره را کاربر جاری خریده است؟
            $course['owned'] = in_array($course_product_id, $owned_product_ids, true);
            $courses[] = $course;
        }

        return rest_ensure_response($courses);
    }

    /**
     * v1.4.0: دوره‌های من — همان شکل آیتم‌های لیست دوره‌ها (با owned:true) فقط برای
     * محصولاتی از دستهٔ دوره‌ها که کاربر جاری در سفارش پرداخت‌شدهٔ ووکامرس دارد.
     * مرتب‌سازی بر اساس عنوان صعودی؛ سقف ۵۰ دوره.
     */
    public function handle_get_my_courses() {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست؛ امکان نمایش دوره‌ها وجود ندارد.', array('status' => 503));
        }

        $user_id = get_current_user_id();
        $owned_product_ids = $this->get_paid_product_ids_for_user($user_id);
        if (empty($owned_product_ids)) {
            return rest_ensure_response(array(
                'success' => true,
                'courses' => array(),
                'count'   => 0,
            ));
        }

        // فقط محصولات دستهٔ دوره‌ها — همان آپشن GET /courses؛ مرتب بر اساس عنوان
        $category_slug = (string) get_option('mrdastbaaf_course_category', 'courses');
        $query_args = array(
            'post_type'           => 'product',
            'post_status'         => 'publish',
            'posts_per_page'      => 200,
            'no_found_rows'       => true,
            'ignore_sticky_posts' => true,
            'orderby'             => 'title',
            'order'               => 'ASC',
        );
        if ('' !== $category_slug) {
            $query_args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $category_slug,
                ),
            );
        }
        $query = new WP_Query($query_args);

        $product_ids = array();
        foreach ($query->posts as $product_post) {
            $product_ids[] = (int) $product_post->ID;
        }
        $lesson_counts = $this->get_lesson_counts($product_ids);
        $lesson_durations = $this->sum_lesson_durations($product_ids);

        $courses = array();
        foreach ($query->posts as $product_post) {
            $course_product_id = (int) $product_post->ID;
            if (!in_array($course_product_id, $owned_product_ids, true)) {
                continue;
            }
            $course = $this->map_product_to_course(
                $product_post,
                isset($lesson_durations[$course_product_id]) ? (int) $lesson_durations[$course_product_id] : 0
            );
            $counts = isset($lesson_counts[$course_product_id]) ? $lesson_counts[$course_product_id] : array('total' => 0, 'free' => 0);
            $course['lessonsCount'] = (int) $counts['total'];
            $course['freePreviewCount'] = (int) $counts['free'];
            $course['owned'] = true;
            $courses[] = $course;
            if (count($courses) >= 50) {
                break; // سقف قراردادی ۵۰ دوره
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'courses' => $courses,
            'count'   => count($courses),
        ));
    }

    /**
     * v1.3.3: جزئیات دوره با شناسهٔ عددی محصول — مسیر بدون وابستگی به اسلاگ.
     */
    public function handle_get_single_course_by_id(WP_REST_Request $request) {
        return $this->serve_single_course(sanitize_text_field($request->get_param('id')));
    }

    /**
     * استعلام جزئیات یک دوره (با slug محصول) به همراه سرفصل‌ها (درس‌ها)
     *
     * v1.3.2: جستجوی تحمل‌پذیر با اسلاگ — فرم دریافتی می‌تواند خام (فارسی)،
     * یک‌بار یا دوبار percent-encoded باشد و post_name دیتابیس هم بسته به نحوهٔ
     * ذخیره، همان خام یا همان «%d8%af...» است؛ همهٔ فرم‌های معتبر امتحان می‌شوند.
     */
    public function handle_get_single_course(WP_REST_Request $request) {
        return $this->serve_single_course(sanitize_text_field($request->get_param('id')));
    }

    /**
     * v1.3.3: هستهٔ مشترک جزئیات دوره (اسلاگ یا شناسهٔ عددی).
     *
     * تشخیص استقرار ۱.۳.۲: جستجوی اسلاگ با get_posts روی سرور واقعی حتی برای
     * کاندیدای «دقیقاً برابر با post_name» هم شکست می‌خورد (تأییدشده با کش‌باستر
     * و lscache:miss) — پس lookup به کوئری مستقیم $wpdb روی post_name منتقل شد
     * که از تمام فیلترها/سانیتایزهای WP_Query ووکامرسی عبور می‌کند. مسیر عددی
     * by-id هم برای کلاینت اضافه شد (پاسخ لیست productId دارد).
     */
    private function serve_single_course($course_id) {
        if (!class_exists('WooCommerce')) {
            return new WP_Error('woocommerce_missing', 'ووکامرس فعال نیست؛ امکان نمایش دوره وجود ندارد.', array('status' => 503));
        }

        $course_id = trim((string) $course_id);
        if ('' === $course_id) {
            return new WP_Error('not_found', 'دوره مورد نظر یافت نشد.', array('status' => 404));
        }

        $tried = array();
        $post_found = $this->resolve_product($course_id, $tried);

        if (null === $post_found) {
            // v1.3.3: فهرست اشکال امتحان‌شده در بدنهٔ خطا — عیب‌یابی آینده بدون حدس
            return new WP_Error('not_found', 'دوره مورد نظر یافت نشد.', array(
                'status' => 404,
                'tried' => array_slice($tried, 0, 8),
            ));
        }

        // v1.2.0 (یافتهٔ ۳): محصول باید واقعاً در دستهٔ دوره‌ها باشد — قبلاً هر محصول
        // ووکامرسی (مثلاً ابزار بافندگی) با داشتن slug در این اندپوینت «دوره» جا می‌شد.
        $category_slug = (string) get_option('mrdastbaaf_course_category', 'courses');
        if ('' !== $category_slug && !has_term($category_slug, 'product_cat', $post_found->ID)) {
            return new WP_Error('not_found', 'دوره یافت نشد.', array('status' => 404));
        }

        // v1.4.0: مدت عددی دوره (یک پرس‌وجوی SUM تک‌محصولی — جزئیات، بدون N+1)
        $course_durations = $this->sum_lesson_durations(array((int) $post_found->ID));
        $course_duration_minutes = isset($course_durations[(int) $post_found->ID]) ? (int) $course_durations[(int) $post_found->ID] : 0;
        $course = $this->map_product_to_course($post_found, $course_duration_minutes);

        /**
         * SECURITY: درس‌ها بدون video_url برگردانده می‌شوند — تنها فیلدهای نمایشی.
         * آدرس ویدیو فقط از اندپوینت پخش با بلیط معتبر قابل دریافت است.
         */
        $course['lessons'] = $this->get_product_lessons_public((int) $post_found->ID);
        $course['freePreviewCount'] = $this->count_free_lessons((int) $post_found->ID);

        // ─── v1.3.5: پرچم «خرید تک‌دوره‌ای از سایت» ───
        // کاربرِ لاگین‌شده (Bearer یا کوکی) که این محصول را در سفارش پرداخت‌شدهٔ
        // ووکامرس دارد → تمام جلسات همین دوره بدون اشتراک باز می‌شود. مهمان همیشه
        // false (پرچم هرگز سمت کلاینت استنتاج نمی‌شود — هم‌راستا با گیت بلیط پخش).
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();
        $viewer_id = get_current_user_id();
        $course['owned'] = $viewer_id
            ? $this->user_owns_course_product($viewer_id, (int) $post_found->ID)
            : false;

        return rest_ensure_response($course);
    }

    /**
     * v1.3.3: پیدا کردن محصول publish با اسلاگ یا شناسهٔ عددی — بدون WP_Query.
     *
     * @param string $course_id اسلاگ (هر فرمی) یا شناسهٔ عددی
     * @param array  &$tried    خروجی: فهرست اشکال جستجوشده (برای دیباگ پاسخ ۴۰۴)
     * @return WP_Post|null
     */
    private function resolve_product($course_id, &$tried) {
        global $wpdb;
        $tried = array();

        // مسیر ۱: شناسهٔ عددی — قطعی‌ترین شکل
        if (ctype_digit($course_id)) {
            $tried[] = 'id=' . (int) $course_id;
            $post = get_post((int) $course_id);
            if ($post && 'product' === $post->post_type && 'publish' === $post->post_status) {
                return $post;
            }
            return null;
        }

        // مسیر ۲: کاندیداهای اسلاگ [خام، یک‌بار decode، دوبار decode، encode خام]
        $candidates = array($course_id);
        $form = $course_id;
        for ($i = 0; $i < 2; $i++) {
            $form = rawurldecode($form);
            if (!in_array($form, $candidates, true)) {
                $candidates[] = $form;
            }
        }
        $encoded = rawurlencode($course_id);
        if (!in_array($encoded, $candidates, true)) {
            $candidates[] = $encoded;
        }

        foreach ($candidates as $candidate) {
            $candidate = (string) $candidate;
            if ('' === $candidate) {
                continue;
            }
            $tried[] = 'slug=' . $candidate;
            $pid = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'product' AND post_status = 'publish' LIMIT 1",
                $candidate
            ));
            if ($pid) {
                $post = get_post((int) $pid);
                if ($post) {
                    return $post;
                }
            }
        }

        return null;
    }

    /**
     * v1.3.3: شمارش درس‌های پیش‌نمایش رایگان یک محصول
     */
    private function count_free_lessons($product_id) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_lessons WHERE product_id = %d AND is_free = 1",
            (int) $product_id
        ));
        return (int) $count;
    }

    /**
     * صدور بلیط امن موقت استریم ویدیو (Signed Playback Ticket)
     *
     * v1.1.0: وضعیت is_free و required_tier ابتدا از جدول درس‌ها خوانده می‌شود؛
     * در نبود ردیف، فیلترهای قبلی به‌عنوان fallback حفظ شده‌اند. بلیط در transient
     * mrdastbaaf_tkt_{ticketId} (۱۵ دقیقه) با {user_id, lesson_id} ذخیره می‌شود
     * تا اندپوینت پخش بتواند معتبر بودن آن را تطبیق دهد.
     */
    public function handle_get_playback_ticket(WP_REST_Request $request) {
        // v1.1.0: پشتیبانی احراز هویت Bearer در کنار کوکی
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();

        $lesson_id = sanitize_text_field($request->get_param('id'));
        $user_id = get_current_user_id();

        // ABUSE FIX (v1.2.0 — یافتهٔ ۲-۲۰): rate-limit مهمان — اندپوینت عمومی است و
        // مهمان می‌توانست بلیط‌های پیش‌نمایش را بی‌حساب انبوه بگیرد (سوخت‌سوزی منابع
        // و دور زدن اثر واترمارک با بلیط‌های متعدد). سقف: ۳۰ بلیط در ساعت برای هر
        // ترکیب IP | کاربر | درس (مهمان هم پوشش داده می‌شود).
        $ip = MrDastbaaf_Auth_Controller::get_client_ip();
        $tkt_rl_key = 'mrdastbaaf_tkt_rl_' . md5($ip . '|' . (int) $user_id . '|' . (string) $lesson_id);
        $tkt_count = (int) get_transient($tkt_rl_key);
        if ($tkt_count >= 30) {
            return new WP_Error('rate_limited', 'تعداد درخواست‌های تماشا بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.', array('status' => 429));
        }

        $lesson_row = $this->get_lesson_row($lesson_id);

        if ($lesson_row) {
            $is_free_preview = (bool) $lesson_row->is_free;
        } else {
            // fallback (رفتار قبلی): درس‌های خارج از جدول از فیلتر سفارشی می‌آیند
            $is_free_preview = apply_filters('mrdastbaaf_is_lesson_free_preview', false, $lesson_id);
        }

        if (!$is_free_preview) {
            if (!$user_id) {
                return new WP_Error('unauthorized', 'برای مشاهده این جلسه ابتدا وارد حساب کاربری خود شوید.', array('status' => 401));
            }

            $has_access = $this->user_has_lesson_access($user_id, $lesson_id, $lesson_row);
            if (!$has_access) {
                return new WP_Error('forbidden', 'مشاهده این جلسه نیازمند خرید یا تمدید اشتراک معتبر است.', array('status' => 403));
            }
        }

        $watermark = $this->build_watermark($user_id);

        $ticket_id = 'tkt_' . wp_generate_password(16, false);

        // v1.2.0: شمارندهٔ rate-limit فقط پس از عبور از گاردهای دسترسی افزایش می‌یابد
        set_transient($tkt_rl_key, $tkt_count + 1, HOUR_IN_SECONDS);

        // v1.1.0: ذخیرهٔ بلیط برای تطبیق در اندپوینت پخش — مقید به کاربر و درس
        set_transient('mrdastbaaf_tkt_' . $ticket_id, array(
            'user_id'   => (int) $user_id,
            'lesson_id' => (string) $lesson_id,
            'issued_at' => time(),
        ), self::TICKET_TTL);

        return rest_ensure_response(array(
            'ticketId' => $ticket_id,
            'lessonId' => $lesson_id,
            'expiresInSeconds' => self::TICKET_TTL,
            'issuedAt' => gmdate('c'), // BUG-12: زمان مطلق صدور (ISO 8601)
            'expiresAt' => gmdate('c', time() + self::TICKET_TTL), // BUG-12: زمان مطلق انقضا
            'watermarkText' => $watermark['text'],
            'watermarkUserId' => $watermark['user_id'],
            'streamType' => 'hls',
        ));
    }

    /**
     * v1.1.0: تحویل آدرس ویدیو در ازای بلیط معتبر — fail-closed
     *
     * بلیط باید: موجود باشد (منقضی نشده باشد)، متعلق به همین کاربر جاری و همین درس
     * باشد؛ در غیر این صورت 403 برگردانده می‌شود. خود ردیف درس هم باید ویدیوی
     * غیرخالی داشته باشد (404). پاسخ: videoUrl / watermarkText / expiresAt.
     */
    public function handle_lesson_playback(WP_REST_Request $request) {
        // v1.1.0: پشتیبانی احراز هویت Bearer در کنار کوکی
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();

        $lesson_id = sanitize_text_field($request->get_param('id'));
        $ticket_id = sanitize_text_field($request->get_param('ticketId'));
        $user_id = get_current_user_id();

        if ('' === $ticket_id) {
            return new WP_Error('invalid_ticket', 'بلیط پخش ارسال نشده است؛ ابتدا بلیط تماشا دریافت کنید.', array('status' => 403));
        }

        $ticket = get_transient('mrdastbaaf_tkt_' . $ticket_id);
        if (!is_array($ticket) || !isset($ticket['user_id'], $ticket['lesson_id'])) {
            return new WP_Error('invalid_ticket', 'بلیط پخش منقضی شده است؛ لطفاً دوباره تلاش کنید.', array('status' => 403));
        }

        // fail-closed: بلیط باید دقیقاً متعلق به همین کاربر و همین درس باشد
        if ((int) $user_id !== (int) $ticket['user_id'] || $lesson_id !== (string) $ticket['lesson_id']) {
            return new WP_Error('ticket_mismatch', 'بلیط پخش با کاربر یا درس درخواستی مطابقت ندارد.', array('status' => 403));
        }

        $lesson_row = $this->get_lesson_row($lesson_id);
        if (!$lesson_row || '' === trim((string) $lesson_row->video_url)) {
            return new WP_Error('lesson_not_found', 'ویدیوی این درس در دسترس نیست.', array('status' => 404));
        }

        $issued_at = isset($ticket['issued_at']) ? (int) $ticket['issued_at'] : time();

        /**
         * v1.2.0 (یافتهٔ ۱-۸): فیلتر ارائه‌دهندهٔ استریم — لایهٔ provider برای URLهای
         * امضاشدهٔ CDN (نقطهٔ اتصال ArvanCloud؛ نیاز به کلیدها در کار آینده). امروز
         * فیلتر URL را دست‌نخورده عبور می‌دهد؛ فردا افزونهٔ ثالث می‌تواند MP4 را به
         * HLS امضاشده تبدیل کند. شکل پاسخ (videoUrl/watermarkText/expiresAt) ثابت است.
         */
        $video_url = apply_filters('mrdastbaaf_stream_video_url', (string) $lesson_row->video_url, $lesson_row, $user_id);

        // ─── v1.4.0: کیفیت‌های استریم ───
        // مقدار ذخیره‌شدهٔ درس (qualities_json) دوباره از فیلتر https + لیست سفید
        // هاست عبور داده می‌شود و سپس از فیلتر مستندِ ۱.۳.۵ واقعاً عبور می‌کند؛
        // خروجی خالی = کلاینت فقط گزینهٔ «خودکار» نشان می‌دهد. بقیهٔ پاسخ (بلیط/
        // واترمارک/TTL/no-store) دست‌نخورده است.
        $stored_qualities = $this->sanitize_stored_qualities(isset($lesson_row->qualities_json) ? (string) $lesson_row->qualities_json : '');
        $stored_qualities = apply_filters('mrdastbaaf_stream_video_qualities', $stored_qualities, $lesson_row, $user_id);
        if (!is_array($stored_qualities)) {
            $stored_qualities = array();
        }
        $stored_qualities = array_values($stored_qualities);

        // v1.7.0: استریم پروکسی — وقتی فعال باشد، URL واقعی CDN هرگز به کلاینت
        // نمی‌رسد. به جای آن، URL اندپوینت پروکسی برمی‌گردد که توکن کوتاه‌عمر دارد.
        // کلاینت فقط /wp-json/.../stream?token=... را در <video src> می‌بیند.
        $proxy_mode = (bool) get_option('mrdastbaaf_stream_proxy_mode', false);

        if ($proxy_mode) {
            // توکن کوتاه‌عمر برای استریم (۶۰ ثانیه — کافی برای شروع پخش)
            $stream_token = wp_generate_password(20, false);
            set_transient('mrdastbaaf_strm_' . $stream_token, array(
                'user_id'   => (int) $user_id,
                'lesson_id' => (string) $lesson_id,
                'video_url' => $video_url, // URL واقعی فقط اینجا ذخیره می‌شود — هرگز به کلاینت نمی‌رود
                'issued_at' => time(),
            ), 60); // ۶۰ ثانیه

            $base_url = rest_url(MRDASTBAAF_API_NAMESPACE . '/lessons/' . rawurlencode($lesson_id) . '/stream');
            $proxy_video_url = add_query_arg('token', $stream_token, $base_url);

            // کیفیت‌ها هم پروکسی می‌شوند — هر کدام توکن جداگانه
            $proxy_qualities = array();
            foreach ($stored_qualities as $q) {
                $q_token = wp_generate_password(20, false);
                set_transient('mrdastbaaf_strm_' . $q_token, array(
                    'user_id'   => (int) $user_id,
                    'lesson_id' => (string) $lesson_id,
                    'video_url' => $q['url'],
                    'issued_at' => time(),
                ), 60);
                $proxy_qualities[] = array(
                    'label' => $q['label'],
                    'url'   => add_query_arg('token', $q_token, $base_url),
                );
            }

            return rest_ensure_response(array(
                'videoUrl'     => $proxy_video_url,
                'watermarkText' => $this->build_watermark($user_id)['text'],
                'expiresAt'    => gmdate('c', $issued_at + self::TICKET_TTL),
                'qualities'    => $proxy_qualities,
                'proxyMode'    => true,
            ));
        }

        return rest_ensure_response(array(
            'videoUrl' => $video_url,
            'watermarkText' => $this->build_watermark($user_id)['text'],
            'expiresAt' => gmdate('c', $issued_at + self::TICKET_TTL),
            // v1.4.0: [{label,url}, …] — ممکن است خالی باشد (فقط «خودکار»)
            'qualities' => $stored_qualities,
            'proxyMode' => false,
        ));
    }

    /**
     * v1.7.0: استریم پروکسی — URL واقعی ویدیو را از CDN می‌خواند و byte-by-byte
     * به کلاینت stream می‌کند. کلاینت هرگز URL واقعی dll.mrdastbaaf.com را نمی‌بیند.
     *
     * پشتیبانی از HTTP Range requests برای seek. توکن ۶۰ ثانیه‌ای.
     * حافظه: chunked streaming — کل فایل در حافظه بارگذاری نمی‌شود.
     */
    public function handle_lesson_stream_proxy(WP_REST_Request $request) {
        MrDastbaaf_Auth_Controller::maybe_authenticate_bearer();

        $lesson_id = sanitize_text_field($request->get_param('id'));
        $token = sanitize_text_field($request->get_param('token'));

        if ('' === $token) {
            return new WP_Error('missing_token', 'توکن استریم ارسال نشده است.', array('status' => 403));
        }

        $stream_data = get_transient('mrdastbaaf_strm_' . $token);
        if (!is_array($stream_data) || !isset($stream_data['video_url'], $stream_data['user_id'], $stream_data['lesson_id'])) {
            return new WP_Error('expired_token', 'توکن استریم منقضی شده است.', array('status' => 403));
        }

        // تطبیق درس — توکن باید دقیقاً متعلق به همین درس باشد
        if ($lesson_id !== (string) $stream_data['lesson_id']) {
            return new WP_Error('token_mismatch', 'توکن با درس درخواستی مطابقت ندارد.', array('status' => 403));
        }

        $video_url = (string) $stream_data['video_url'];
        if ('' === trim($video_url)) {
            return new WP_Error('no_video', 'ویدیوی این درس در دسترس نیست.', array('status' => 404));
        }

        // توکن یک‌بارمصرف نیست — ولی کوتاه‌عمر است (۶۰ ثانیه). در طول پخش،
        // توکن معتبر می‌ماند. اگه کاربر seek کند، Range request جدید با همان
        // توکن می‌آید — تا وقتی توکن معتبر است، کار می‌کند.

        // اعتبارسنجی نهایی URL (https + allowlist)
        $allowed_hosts = MrDastbaaf_Admin_Controller::get_allowed_video_hosts();
        $parsed = wp_parse_url($video_url);
        $host = isset($parsed['host']) ? strtolower($parsed['host']) : '';
        $scheme = isset($parsed['scheme']) ? strtolower($parsed['scheme']) : '';

        if ($scheme !== 'https' || !in_array($host, $allowed_hosts, true)) {
            return new WP_Error('invalid_host', 'هاست ویدیو مجاز نیست.', array('status' => 403));
        }

        // ─── هدرهای no-cache ───
        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: Wed, 11 Jan 1984 05:00:00 GMT');
        }

        // v1.7.1: بهینه‌سازی سرعت پروکسی — این سه تا bottleneck اصلی روی هاست اشتراکی‌اند:
        // ۱) zlib.output_compression: PHP سعی می‌کند خروجی را gzip کند — برای stream
        //    این یعنی buffer کامل هر chunk قبل از فرستادن. غیرفعال‌کردنش می‌تواند
        //    سرعت را ۵-۱۰ برابر کندتر-به-سریع‌تر کند.
        // ۲) output_buffering: به‌طور پیش‌فرض ۴۰۹۶ بایت buffer می‌کند.
        // ۳) memory_limit: برای ویدیوهای بزرگ ممکن است کم باشد.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        @ini_set('memory_limit', '512M');
        @ini_set('max_execution_time', '0');
        @ini_set('max_input_time', '0');
        // اگر mod_deflate فعال است، هدر Content-Encoding نفرست تا gzip نکند
        if (!headers_sent()) {
            header('Content-Encoding: none');
        }

        // ─── دریافت اطلاعات فایل از CDN (HEAD request) ───
        // برای Content-Length و پشتیبانی از Range
        $ch = curl_init($video_url);
        curl_setopt_array($ch, array(
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'MrDastbaaf-Stream-Proxy/1.7',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            // v1.7.1: Referer درست بفرست تا BitNinja/WAF رد نکند
            CURLOPT_REFERER        => site_url('/'),
            CURLOPT_HTTPHEADER     => array(
                'Accept: video/webm,video/ogg,video/*;q=0.9,application/ogg,*/*;q=0.6',
            ),
        ));
        curl_exec($ch);
        $file_size = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200 || $file_size <= 0) {
            // اگه HEAD شکست خورد، حداقل Content-Type را ست کن و GET را امتحان کن
            $content_type = $content_type ?: 'video/mp4';
            $file_size = 0; // مجهول — stream بدون Range
        }

        if (empty($content_type) || stripos($content_type, 'text/') !== false) {
            $content_type = 'video/mp4';
        }

        // ─── پشتیبانی از Range requests (برای seek) ───
        $range_header = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : '';
        $start = 0;
        $end = $file_size - 1;

        if ($file_size > 0 && preg_match('/bytes=(\d+)-(\d*)/', $range_header, $matches)) {
            $start = (int) $matches[1];
            $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $file_size - 1;
            if ($start > $end || $end >= $file_size) {
                status_header(416); // Range Not Satisfiable
                header('Content-Range: bytes */' . $file_size);
                exit;
            }
            status_header(206); // Partial Content
        } else {
            status_header(200);
        }

        // ─── هدرهای استریم ───
        header('Content-Type: ' . $content_type);
        header('Accept-Ranges: bytes');
        if ($file_size > 0) {
            header('Content-Length: ' . ($end - $start + 1));
            if (isset($matches)) {
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $file_size);
            }
        }
        header('Content-Disposition: inline');
        header('X-Content-Type-Options: nosniff');

        // ─── غیرفعال‌سازی output buffering ───
        // v1.7.1: حذف کامل همه level ها — نه فقط flush. این حیاتی است برای stream.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        @set_time_limit(0); // برای ویدیوهای طولانی

        // ─── استریم با fopen + stream context (سریع‌تر از cURL WRITEFUNCTION) ───
        // v1.7.1: به‌جای cURL WRITEFUNCTION با echo، از fopen با stream context استفاده
        // می‌کنیم. این روش:
        //   - buffer داخلی کوچک‌تری دارد (default 8192)
        //   - مستقیم به output stream وصل می‌شود
        //   - بهتر با Range request کار می‌کند
        //   - کمتر روی memory فشار می‌آورد
        $context_options = array(
            'http' => array(
                'method'        => $file_size > 0 && isset($matches) ? 'GET' : 'GET',
                'header'        => array(
                    'User-Agent: MrDastbaaf-Stream-Proxy/1.7',
                    'Referer: ' . site_url('/'),
                    'Accept: video/webm,video/ogg,video/*;q=0.9,application/ogg,*/*;q=0.6',
                ),
                'timeout'       => 0, // بدون timeout برای ویدیوهای طولانی
                'ignore_errors' => true,
                'protocol_version' => 1.1,
            ),
            'ssl' => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ),
        );

        // Range header برای seek
        if ($file_size > 0 && isset($matches)) {
            $context_options['http']['header'][] = "Range: bytes=$start-$end";
        }

        $context = stream_context_create($context_options);
        $remote_handle = @fopen($video_url, 'rb', false, $context);

        if ($remote_handle === false) {
            // fallback به cURL اگه fopen کار نکرد
            $range = $file_size > 0 ? "$start-$end" : '0-';
            $ch = curl_init($video_url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_TIMEOUT        => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'MrDastbaaf-Stream-Proxy/1.7',
                CURLOPT_REFERER        => site_url('/'),
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_RANGE          => $range,
                CURLOPT_WRITEFUNCTION  => function ($curl, $data) {
                    echo $data;
                    @flush();
                    if (connection_aborted()) {
                        return -1;
                    }
                    return strlen($data);
                },
            ));
            curl_exec($ch);
            curl_close($ch);
            exit;
        }

        // ─── stream_copy_to_stream: سریع‌ترین روش PHP ───
        // مستقیم از remote به php://output — بدون echo loop
        $output_handle = fopen('php://output', 'wb');
        if ($output_handle !== false) {
            // chunk size 256KB — تعادل بین memory و سرعت
            stream_copy_to_stream($remote_handle, $output_handle, $file_size > 0 ? ($end - $start + 1) : -1);
            fclose($output_handle);
        }
        fclose($remote_handle);
        exit;
    }

    /**
     * v1.4.0: رمزگشایی + بازاعتبارسنجی کیفیت‌های ذخیره‌شدهٔ درس — هر نشانی باید
     * دوباره از همان گیت ویدیوی درس عبور کند (https + لیست سفید هاست از آپشن
     * mrdastbaaf_video_hosts)؛ ردیف نامعتبر حذف می‌شود (fail-closed).
     *
     * @param string $raw_json محتوای ستون qualities_json
     * @return array آرایهٔ [{label,url}, …] (ممکن است خالی)
     */
    private function sanitize_stored_qualities($raw_json) {
        $decoded = json_decode((string) $raw_json, true);
        if (!is_array($decoded)) {
            return array();
        }

        $allowed_hosts = MrDastbaaf_Admin_Controller::get_allowed_video_hosts();
        $clean = array();
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['url'])) {
                continue;
            }
            $url = esc_url_raw(trim((string) $entry['url']));
            if ('' === $url || 0 !== strpos($url, 'https://')) {
                continue;
            }
            $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
            if ('' === $host || !in_array($host, $allowed_hosts, true)) {
                continue;
            }
            $label = isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '';
            if ('' === $label) {
                continue;
            }
            $clean[] = array(
                'label' => $label,
                'url'   => $url,
            );
        }
        return $clean;
    }

    /**
     * نگاشت محصول ووکامرس به شکل Course فرانت (فیلدهای همگام با تایپ‌های PWA)
     *
     * v1.2.0 (یافتهٔ ۳): فیلدهای ساختگی rating/students حذف شدند — مقدارهای جعلی «۰»
     * در UI رندر می‌شدند. قرارداد تغییر کرده: نرمال‌ساز کلاینت برای این دو فیلد
     * مقدار پیش‌فرض دارد و نبودشان در پاسخ مشکلی ایجاد نمی‌کند.
     *
     * v1.4.0: فیلد duration دیگر «—» نیست — مدت کل دوره به دقیقه (جمع درس‌ها؛
     * صفر یعنی درس بدون مدت ثبت نشده). لیست از پرس‌وجوی batched و جزئیات از همان
     * مسیر می‌آید (پارامتر اختیاری) تا هیچ N+1 رخ ندهد.
     *
     * @param WP_Post $post             محصول ووکامرس
     * @param int     $duration_minutes جمع دقیقه‌های درس‌ها (۰ پیش‌فرض)
     * @return array
     */
    private function map_product_to_course(WP_Post $post, $duration_minutes = 0) {
        $product = function_exists('wc_get_product') ? wc_get_product($post->ID) : null;

        // نام ترم اول دستهٔ ووکامرس
        $category_name = '';
        $terms = get_the_terms($post->ID, 'product_cat');
        if (is_array($terms) && !empty($terms)) {
            $category_name = $terms[0]->name;
        }

        // سطح دوره از اتریبیوت pa_level (در نبودش: مقدماتی)
        $level = 'مقدماتی';
        if ($product) {
            $raw_level = $product->get_attribute('pa_level');
            if (is_string($raw_level) && '' !== $raw_level) {
                $level_parts = array_map('trim', explode(',', $raw_level));
                if ('' !== $level_parts[0]) {
                    $level = $level_parts[0];
                }
            }
        }

        // قیمت سادهٔ محصول به تومان (واحد پول فروشگاه)
        $price = 0;
        if ($product && '' !== $product->get_price() && null !== $product->get_price()) {
            $price = (int) $product->get_price();
        }

        return array(
            'id' => $post->post_name, // slug محصول = شناسهٔ دوره در فرانت
            // v1.3.3: شناسهٔ عددی محصول — کلاینت برای جزئیات از /courses/by-id/{productId} استفاده می‌کند
            'productId' => (int) $post->ID,
            'title' => $post->post_title,
            'description' => $post->post_excerpt,
            'image' => (string) get_the_post_thumbnail_url($post->ID, 'medium'),
            'category' => $category_name,
            'level' => $level,
            'lessonsCount' => 0, // در handle_get_courses با شمارش واقعی جایگزین می‌شود
            // v1.4.0: مدت کل دوره به دقیقه (جمع درس‌ها؛ عدد — نه «—»)
            'duration' => max(0, (int) $duration_minutes),
            'price' => $price,
            // v1.2.0: rating/students ساختگی حذف شدند (نرمال‌ساز کلاینت default دارد)
        );
    }

    /**
     * درس‌های عمومی محصول (بدون video_url) — هم‌شکل سرفصل‌های فرانت
     *
     * v1.4.0: مدت هر درس (duration_minutes) از ستون NEW جدول؛ صفر = null تا
     * کلاینت «۰ دقیقه» نشان ندهد. درس‌های قدیمی (پیش از مهاجرت) هم null می‌گیرند.
     */
    private function get_product_lessons_public($product_id) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, is_free, required_tier, duration_minutes
             FROM $table_lessons
             WHERE product_id = %d
             ORDER BY sort_order ASC, id ASC",
            $product_id
        ));

        $lessons = array();
        foreach ($rows as $row) {
            $lesson_duration = isset($row->duration_minutes) ? (int) $row->duration_minutes : 0;
            $lessons[] = array(
                'id' => (string) (int) $row->id,
                'title' => $row->title,
                'isFreePreview' => (bool) $row->is_free,
                'requiredTier' => (string) $row->required_tier,
                // v1.4.0: مدت درس به دقیقه — صفر/ثبت‌نشده = null (کلاینت مخفی می‌کند)
                'duration' => $lesson_duration > 0 ? $lesson_duration : null,
            );
        }

        return $lessons;
    }

    /**
     * شمارش درس‌های چند محصول در یک پرس‌وجو
     *
     * v1.3.3: تعداد پیش‌نمایش‌های رایگان هم در همان پرس‌وجو برمی‌گردد.
     *
     * @param array $product_ids شناسه‌های عددی محصول
     * @return array نگاشت product_id => ['total' => int, 'free' => int]
     */
    private function get_lesson_counts(array $product_ids) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        if (empty($product_ids)) {
            return array();
        }

        // شناسه‌ها با absint تصفیه شده‌اند — امن برای IN
        $sanitized_ids = array_map('absint', $product_ids);
        $ids_sql = implode(',', $sanitized_ids);

        $rows = $wpdb->get_results("SELECT product_id, COUNT(*) AS lesson_total, COALESCE(SUM(CASE WHEN is_free = 1 THEN 1 ELSE 0 END), 0) AS lesson_free FROM $table_lessons WHERE product_id IN ($ids_sql) GROUP BY product_id");

        $counts = array();
        foreach ($rows as $row) {
            $counts[(int) $row->product_id] = array(
                'total' => (int) $row->lesson_total,
                'free'  => (int) $row->lesson_free,
            );
        }

        return $counts;
    }

    /**
     * v1.4.0: جمع دقیقه‌های درس‌های چند محصول در یک پرس‌وجو (همان الگوی
     * get_lesson_counts) — مبناى فیلد duration عددی در لیست/جزئیات/my-courses
     * بدون هیچ N+1.
     *
     * @param array $product_ids شناسه‌های عددی محصول
     * @return array نگاشت product_id => جمع دقیقه‌ها (int)
     */
    private function sum_lesson_durations(array $product_ids) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        if (empty($product_ids)) {
            return array();
        }

        // شناسه‌ها با absint تصفیه شده‌اند — امن برای IN
        $sanitized_ids = array_map('absint', $product_ids);
        $ids_sql = implode(',', $sanitized_ids);

        $rows = $wpdb->get_results("SELECT product_id, COALESCE(SUM(duration_minutes), 0) AS duration_total FROM $table_lessons WHERE product_id IN ($ids_sql) GROUP BY product_id");

        $totals = array();
        foreach ($rows as $row) {
            $totals[(int) $row->product_id] = (int) $row->duration_total;
        }

        return $totals;
    }

    /**
     * خواندن ردیف درس از جدول اختصاصی — فقط شناسهٔ عددی
     */
    private function get_lesson_row($lesson_id) {
        if (!ctype_digit((string) $lesson_id)) {
            return null; // شناسهٔ غیرعددی (رفتار قدیمی/فیلتری) → مسیر fallback
        }

        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_lessons WHERE id = %d",
            (int) $lesson_id
        ));
    }

    /**
     * بررسی دسترسی کاربر به درس بر اساس سطح اشتراک فعال
     *
     * v1.1.0: tier موردنیاز ابتدا از جدول درس‌ها خوانده می‌شود؛ در نبود ردیف،
     * فیلتر قبلی mrdastbaaf_get_lesson_required_tier به‌عنوان fallback حفظ شده است.
     * v1.3.5: خرید تک‌دوره‌ای — اگر کاربر محصولِ والدِ این درس را در سفارش
     * پرداخت‌شدهٔ ووکامرس داشته باشد، tier نادیده گرفته می‌شود (خرید دوره =
     * دسترسی کامل به تمام جلسات همان دوره؛ حتی درس‌های tier بالاتر).
     */
    private function user_has_lesson_access($user_id, $lesson_id, $lesson_row = null) {
        if (user_can($user_id, 'manage_options')) {
            return true; // ادمین به تمام جلسات دسترسی دارد
        }

        // v1.3.5: مسیر خرید تک‌دوره‌ای — محصولِ والدِ درس در سفارش‌های پرداخت‌شدهٔ کاربر؟
        if ($lesson_row && isset($lesson_row->product_id) && (int) $lesson_row->product_id > 0) {
            if ($this->user_owns_course_product($user_id, (int) $lesson_row->product_id)) {
                return true;
            }
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

        if ($lesson_row && isset($lesson_row->required_tier)) {
            $required_tier = (string) $lesson_row->required_tier;
        } else {
            $required_tier = apply_filters('mrdastbaaf_get_lesson_required_tier', 'bronze', $lesson_id);
        }

        // بررسی سلسله‌مراتب دسترسی: طلایی > نقره‌ای > برنزی
        $ranks = array('bronze' => 1, 'silver' => 2, 'gold' => 3);

        $user_rank = isset($ranks[$active_tier]) ? $ranks[$active_tier] : 0;
        $req_rank = isset($ranks[$required_tier]) ? $ranks[$required_tier] : 1;

        return $user_rank >= $req_rank;
    }

    /**
     * v1.3.5: آیا کاربر این محصول (دوره) را در سفارش پرداخت‌شدهٔ ووکامرس دارد؟
     *
     * v1.4.0: پوستهٔ نازکِ هستهٔ مشترک get_paid_product_ids_for_user — همان
     * استراتژی‌های قبلی (customer_id + مهمان با سه زیر-استراتژی شمارهٔ موبایل +
     * نگاشت والدِ variation) عیناً حفظ شده‌اند؛ فراخوانی تکی جزئیات/بلیط بدون
     * تغییر رفتار است.
     *
     * @param int $user_id    شناسهٔ کاربر فعلی
     * @param int $product_id شناسهٔ محصول ووکامرس (دوره)
     * @return bool
     */
    private function user_owns_course_product($user_id, $product_id) {
        if (!$user_id || $product_id <= 0) {
            return false;
        }
        return in_array((int) $product_id, $this->get_paid_product_ids_for_user((int) $user_id), true);
    }

    /**
     * v1.4.0: مجموعهٔ شناسهٔ محصولاتِ (دوره‌های) پرداخت‌شدهٔ یک کاربر — هستهٔ مشترک
     * پرچم owned در لیست (GET /courses)، جزئیات، GET /my/courses و گیت بلیط پخش.
     *
     * پوشش کامل هر دو سناریو:
     *  ۱) سفارش‌های حساب کاربری (customer_id) — خرید لاگین‌شده
     *  ۲) سفارش‌های مهمان با همان شماره موبایل (billing_phone) — کاربرِ OTP
     *     دقیقاً با شماره‌ای که هنگام خرید مهمان وارد کرده، حساب پیدا می‌کند
     *     (user_login = شماره، الگوی همان auth controller؛ نرمال‌سازی +98/0098)
     *
     * وضعیت‌های معتبر: wc-completed و wc-processing (در انتظار تکمیل سفارش فیزیکی).
     * یکپارچگی با HPOS: کوئری billing_phone اول wc_get_orders (پشتیبانی رسمی)،
     * سپس جدول wp_wc_orders (HPOS) و در نهایت postmeta قدیمی (_billing_phone)
     * امتحان می‌شود — روی هر نصبی یکی حتماً جواب می‌دهد.
     *
     * اقلام هر سفارش به شناسهٔ محصول نگاشت می‌شود؛ variation هم به والدش اضافه
     * می‌شود (خرید واریانتِ دوره = مالکیت دوره).
     *
     * کارایی: بدون کش — GET /courses این متد را «یک‌بار» برای کل لیست صدا می‌زند
     * (بدون N+1) و خریدِ همین لحظه بلافاصله در پاسخ دیده می‌شود (رفتار مورد
     * انتظار کارفرما).
     *
     * @param int $user_id شناسهٔ کاربر (مهمان = ۰)
     * @return int[] شناسه‌های یکتای محصول
     */
    private function get_paid_product_ids_for_user($user_id) {
        if (!$user_id || !class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return array();
        }

        $paid_statuses = array('wc-completed', 'wc-processing');
        $order_ids = array();

        // ۱) سفارش‌های حساب کاربری
        $own_orders = wc_get_orders(array(
            'customer_id' => (int) $user_id,
            'status'      => $paid_statuses,
            'limit'       => 100,
        ));
        if (is_array($own_orders)) {
            foreach ($own_orders as $order) {
                $order_ids[] = method_exists($order, 'get_id') ? (int) $order->get_id() : (int) $order;
            }
        }

        // ۲) سفارش‌های مهمان با همان شماره موبایل — v1.7.13: کاندیداهای شماره از
        // «هر دو منبع» user_login و متای billing_phone حساب ساخته و با همهٔ فرم‌های
        // phone_variants تطبیق داده می‌شوند (حساب‌هایی که login‌شان شماره نیست —
        // گزارش کارفرما: دوره‌های خریداری‌شده در داشبورد ظاهر نمی‌شد).
        $user = get_user_by('ID', (int) $user_id);
        if ($user) {
            $phone_candidates = $this->phone_normalization_candidates((string) $user->user_login);
            $account_phone = (string) get_user_meta((int) $user_id, 'billing_phone', true);
            if ('' !== $account_phone) {
                $phone_candidates = array_merge(
                    $phone_candidates,
                    $this->phone_normalization_candidates($account_phone)
                );
            }
            $phone_candidates = array_values(array_unique(array_filter($phone_candidates)));
            foreach ($phone_candidates as $phone) {
                foreach ($this->get_paid_order_ids_by_phone($phone, $paid_statuses) as $oid) {
                    $order_ids[] = (int) $oid;
                }
            }
        }

        if (empty($order_ids)) {
            return array();
        }
        $order_ids = array_values(array_unique(array_map('intval', $order_ids)));

        // نگاشت اقلام سفارش‌ها به شناسهٔ محصول — شامل variation (والدش هم اضافه می‌شود)
        $product_ids = array();
        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order || !method_exists($order, 'get_items')) {
                continue;
            }
            foreach ($order->get_items() as $item) {
                if (!method_exists($item, 'get_product_id')) {
                    continue;
                }
                $item_product_id = (int) $item->get_product_id();
                if ($item_product_id > 0) {
                    $product_ids[] = $item_product_id;
                }
                $item_variation_id = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
                if ($item_variation_id > 0) {
                    $variation = wc_get_product($item_variation_id);
                    if ($variation && method_exists($variation, 'get_parent_id') && (int) $variation->get_parent_id() > 0) {
                        $product_ids[] = (int) $variation->get_parent_id();
                    }
                }
            }
        }

        return array_values(array_unique(array_map('intval', $product_ids)));
    }

    /**
     * v1.3.5: فرم‌های نرمال‌سازی شماره موبایل برای تطبیق با billing_phone سفارش‌ها
     * v1.7.13: به هستهٔ مشترک MrDastbaaf_Auth_Controller::phone_variants سپرده شد —
     * هر فرم شناخته‌شده (09…/9…/989…/+989…/0098…) به «هر ۵ فرم» گسترش می‌یابد
     * (قبلاً فقط ۲ فرم برگردانده می‌شد و سفارش مهمانی که شماره‌اش با فرم دیگری
     * ثبت شده بود پیدا نمی‌شد). فرم خام ارقام به‌عنوان آخرین کاندیدا حفظ شده است.
     *
     * @param string $raw_phone شمارهٔ خام (user_login یا billing_phone)
     * @return string[] کاندیداهای یکتا
     */
    private function phone_normalization_candidates($raw_phone) {
        $candidates = MrDastbaaf_Auth_Controller::phone_variants($raw_phone);

        // فرم خام ارقام (بدون +) — برای ذخیره‌سازی‌های نامتعارف
        $digits = preg_replace('/\D+/', '', (string) $raw_phone);
        if (null !== $digits && '' !== $digits) {
            $candidates[] = $digits;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * v1.3.5: یافتن سفارش‌های پرداخت‌شده با شمارهٔ موبایل — سه استراتژی پشت‌سرهم
     * (wc_get_orders، HPOS مستقیم، postmeta قدیمی) تا روی هر نصبی کار کند.
     *
     * @param string $phone         شمارهٔ نرمال‌شده
     * @param array  $paid_statuses وضعیت‌های معتبر (wc-completed/wc-processing)
     * @return int[] شناسه‌های سفارش
     */
    private function get_paid_order_ids_by_phone($phone, array $paid_statuses) {
        global $wpdb;
        $order_ids = array();

        // استراتژی ۱ — wc_get_orders (پشتیبانی رسمی billing_phone در نسخه‌های جدید)
        try {
            $found = wc_get_orders(array(
                'billing_phone' => (string) $phone,
                'status'        => $paid_statuses,
                'limit'         => 100,
            ));
            if (is_array($found)) {
                foreach ($found as $order) {
                    $order_ids[] = method_exists($order, 'get_id') ? (int) $order->get_id() : (int) $order;
                }
            }
        } catch (Throwable $e) {
            // نسخه‌های قدیمی ووکامرس آرگومان billing_phone را نمی‌شناسند — مسیرهای بعدی
        }
        if (!empty($order_ids)) {
            return $order_ids;
        }

        // استراتژی ۲ — جدول HPOS (wp_wc_orders با ستون billing_phone)
        // نکته: در جدول HPOS وضعیت‌ها «بدون» پیشوند wc- ذخیره می‌شوند (completed/processing)
        // — هر دو فرم برای پوشش کامل نسخه‌های مختلف ووکامرس چک می‌شوند.
        $hpos_table = $wpdb->prefix . 'wc_orders';
        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table));
        if ($table_exists === $hpos_table) {
            $bare_statuses = array_map(
                static function ($status) {
                    return 'wc-' === substr((string) $status, 0, 3) ? substr((string) $status, 3) : (string) $status;
                },
                $paid_statuses
            );
            $all_statuses = array_values(array_unique(array_merge($paid_statuses, $bare_statuses)));
            $placeholders = implode(',', array_fill(0, count($all_statuses), '%s'));
            $sql = "SELECT id FROM {$hpos_table} WHERE billing_phone = %s AND status IN ({$placeholders}) LIMIT 100";
            $params = array_merge(array($phone), $all_statuses);
            $rows = call_user_func_array(array($wpdb, 'get_col'), array_merge(array($sql), $params));
            if (is_array($rows) && !empty($rows)) {
                return array_map('intval', $rows);
            }
        }

        // استراتژی ۳ — ذخیره‌سازی قدیمی (postmeta با کلید _billing_phone)
        // ترتیب پارامترهای prepare بر اساس ترتیب ظاهرشدن در SQL: وضعیت‌ها سپس شماره
        $placeholders = implode(',', array_fill(0, count($paid_statuses), '%s'));
        $sql = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                WHERE p.post_type = 'shop_order'
                  AND p.post_status IN ({$placeholders})
                  AND pm.meta_key = '_billing_phone'
                  AND pm.meta_value = %s
                LIMIT 100";
        $ordered_params = array_merge($paid_statuses, array($phone));
        $prepared = call_user_func_array(array($wpdb, 'prepare'), array_merge(array($sql), $ordered_params));
        $rows = $wpdb->get_col($prepared);
        if (is_array($rows) && !empty($rows)) {
            return array_map('intval', $rows);
        }

        return $order_ids;
    }

    /**
     * واترمارک پیش‌فرض کاربر — PRIVACY FIX (BUG-01) دست‌نخورده:
     * user_login شماره تلفن خام است و فقط شکل ماسک‌شده مجاز به خروج از سرور است.
     *
     * @param int $user_id
     * @return array آرایه با کلیدهای text و user_id
     */
    private function build_watermark($user_id) {
        $user = $user_id ? get_user_by('ID', $user_id) : false;
        if (!$user) {
            return array('text' => 'مهمان | مستر دستباف', 'user_id' => 'guest');
        }

        $phone = (string) $user->user_login;
        $masked_phone = substr($phone, 0, 4) . '***' . substr($phone, -4);
        return array(
            'text' => $masked_phone . ' | ID: ' . $user->ID,
            'user_id' => (string) $user_id,
        );
    }
}
