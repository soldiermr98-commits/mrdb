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

        $courses = array();
        foreach ($query->posts as $product_post) {
            $course = $this->map_product_to_course($product_post);
            $counts = isset($lesson_counts[(int) $product_post->ID]) ? $lesson_counts[(int) $product_post->ID] : array('total' => 0, 'free' => 0);
            $course['lessonsCount'] = (int) $counts['total'];
            // v1.3.3: تعداد درس‌های پیش‌نمایش رایگان — کارت دوره در اپ نشان «پیش‌نمایش رایگان» می‌گیرد
            $course['freePreviewCount'] = (int) $counts['free'];
            $courses[] = $course;
        }

        return rest_ensure_response($courses);
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

        $course = $this->map_product_to_course($post_found);

        /**
         * SECURITY: درس‌ها بدون video_url برگردانده می‌شوند — تنها فیلدهای نمایشی.
         * آدرس ویدیو فقط از اندپوینت پخش با بلیط معتبر قابل دریافت است.
         */
        $course['lessons'] = $this->get_product_lessons_public((int) $post_found->ID);
        $course['freePreviewCount'] = $this->count_free_lessons((int) $post_found->ID);

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

        return rest_ensure_response(array(
            'videoUrl' => $video_url,
            'watermarkText' => $this->build_watermark($user_id)['text'],
            'expiresAt' => gmdate('c', $issued_at + self::TICKET_TTL),
        ));
    }

    /**
     * نگاشت محصول ووکامرس به شکل Course فرانت (فیلدهای همگام با تایپ‌های PWA)
     *
     * v1.2.0 (یافتهٔ ۳): فیلدهای ساختگی rating/students حذف شدند — مقدارهای جعلی «۰»
     * در UI رندر می‌شدند. قرارداد تغییر کرده: نرمال‌ساز کلاینت برای این دو فیلد
     * مقدار پیش‌فرض دارد و نبودشان در پاسخ مشکلی ایجاد نمی‌کند.
     */
    private function map_product_to_course(WP_Post $post) {
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
            'duration' => '—',
            'price' => $price,
            // v1.2.0: rating/students ساختگی حذف شدند (نرمال‌ساز کلاینت default دارد)
        );
    }

    /**
     * درس‌های عمومی محصول (بدون video_url) — هم‌شکل سرفصل‌های فرانت
     */
    private function get_product_lessons_public($product_id) {
        global $wpdb;
        $table_lessons = $wpdb->prefix . 'mrdastbaaf_lessons';

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, title, is_free, required_tier
             FROM $table_lessons
             WHERE product_id = %d
             ORDER BY sort_order ASC, id ASC",
            $product_id
        ));

        $lessons = array();
        foreach ($rows as $row) {
            $lessons[] = array(
                'id' => (string) (int) $row->id,
                'title' => $row->title,
                'isFreePreview' => (bool) $row->is_free,
                'requiredTier' => (string) $row->required_tier,
                'duration' => null,
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
     */
    private function user_has_lesson_access($user_id, $lesson_id, $lesson_row = null) {
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
