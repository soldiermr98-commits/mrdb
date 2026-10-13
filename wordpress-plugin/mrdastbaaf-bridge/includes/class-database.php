<?php
/**
 * مدیریت جداول دیتابیس اختصاصی مستر دستباف در وردپرس
 */

if (!defined('ABSPATH')) {
    exit;
}

class MrDastbaaf_Database {

    /**
     * ایجاد جداول پایگاه داده هنگام فعال‌سازی افزونه
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // ۱. جدول پلن‌های عضویت
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';
        $sql_plans = "CREATE TABLE $table_plans (
            id varchar(64) NOT NULL,
            tier varchar(20) NOT NULL,
            title varchar(191) NOT NULL,
            description text NULL,
            price_toman int(11) unsigned NOT NULL,
            original_price_toman int(11) unsigned NULL,
            duration_days smallint(5) unsigned NOT NULL,
            duration_label varchar(50) NOT NULL,
            features_json longtext NOT NULL,
            is_popular tinyint(1) DEFAULT 0 NOT NULL,
            is_active tinyint(1) DEFAULT 1 NOT NULL,
            color_scheme varchar(20) DEFAULT 'amber' NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id)
        ) $charset_collate;";
        dbDelta($sql_plans);

        // ۲. جدول وضعیت عضویت هنرجویان
        $table_memberships = $wpdb->prefix . 'mrdastbaaf_user_memberships';
        $sql_memberships = "CREATE TABLE $table_memberships (
            id varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            plan_id varchar(64) NOT NULL,
            tier varchar(20) NOT NULL,
            status varchar(20) DEFAULT 'active' NOT NULL,
            started_at datetime NOT NULL,
            expires_at datetime NOT NULL,
            last_order_id varchar(64) NULL,
            auto_renew tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY user_expiry_idx (user_id, expires_at)
        ) $charset_collate;";
        dbDelta($sql_memberships);

        // ۳. جدول سفارش‌ها و فاکتورها
        $table_orders = $wpdb->prefix . 'mrdastbaaf_orders';
        $sql_orders = "CREATE TABLE $table_orders (
            id varchar(64) NOT NULL,
            order_number varchar(30) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            user_phone varchar(20) NOT NULL,
            user_fullname varchar(191) NOT NULL,
            plan_id varchar(64) NOT NULL,
            item_title varchar(191) NOT NULL,
            amount_toman int(11) unsigned NOT NULL,
            gateway varchar(20) NOT NULL,
            status varchar(20) DEFAULT 'pending' NOT NULL,
            zibal_track_id varchar(64) NULL,
            zibal_ref_number varchar(64) NULL,
            verified_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY order_number_idx (order_number),
            KEY status_gateway_idx (status, gateway)
        ) $charset_collate;";
        dbDelta($sql_orders);

        // ۴. جدول بررسی فیش‌های کارت به کارت
        $table_transfers = $wpdb->prefix . 'mrdastbaaf_manual_transfers';
        $sql_transfers = "CREATE TABLE $table_transfers (
            order_id varchar(64) NOT NULL,
            tracking_code varchar(64) NOT NULL,
            card_last_four char(4) NOT NULL,
            deposit_date_jalali varchar(30) NOT NULL,
            receipt_image_name varchar(255) NULL,
            sender_notes text NULL,
            reviewer_admin_id bigint(20) unsigned NULL,
            admin_review_note text NULL,
            reviewed_at datetime NULL,
            PRIMARY KEY  (order_id)
        ) $charset_collate;";
        dbDelta($sql_transfers);

        // درج پلن‌های پیش‌فرض در صورت خالی بودن جدول
        self::seed_initial_plans();
    }

    /**
     * درج پلن‌های عضویت پیش‌فرض
     */
    private static function seed_initial_plans() {
        global $wpdb;
        $table_plans = $wpdb->prefix . 'mrdastbaaf_plans';

        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_plans");
        if ($count > 0) {
            return;
        }

        $default_plans = array(
            array(
                'id' => 'plan-free',
                'tier' => 'free',
                'title' => 'اشتراک رایگان',
                'description' => 'آشنایی با مبانی صنایع دستی و دسترسی به ویدیوهای پیش‌نمایش دوره‌ها',
                'price_toman' => 0,
                'duration_days' => 30,
                'duration_label' => 'همیشگی',
                'features_json' => json_encode(array(
                    'مشاهده درس‌های پیش‌نمایش رایگان تمام دوره‌ها',
                    'دسترسی به مقالات و دانشنامه بافندگی',
                    'حضور در تالار گفتگو و پرسش و پاسخ عمومی'
                ), JSON_UNESCAPED_UNICODE),
                'is_popular' => 0,
                'is_active' => 1,
            ),
            array(
                'id' => 'plan-bronze-1m',
                'tier' => 'bronze',
                'title' => 'اشتراک برنزی',
                'description' => 'مناسب برای علاقه‌مندان تازه‌کار و یادگیری تکنیک‌های پایه دستبافته‌ها',
                'price_toman' => 99000,
                'original_price_toman' => 130000,
                'duration_days' => 30,
                'duration_label' => '۱ ماهه',
                'features_json' => json_encode(array(
                    'دسترسی کامل به تمام دوره‌های سطح مقدماتی',
                    'امکان دانلود فایل‌های PDF نقشه‌های ساده',
                    'مشاهده آنلاین با کیفیت استاندارد HD'
                ), JSON_UNESCAPED_UNICODE),
                'is_popular' => 0,
                'is_active' => 1,
            ),
            array(
                'id' => 'plan-silver-3m',
                'tier' => 'silver',
                'title' => 'اشتراک نقره‌ای (ویژه هنرجویان)',
                'description' => 'محبوب‌ترین پلن برای ارتقای مهارت در دوره‌های متوسط و کاربردی',
                'price_toman' => 249000,
                'original_price_toman' => 320000,
                'duration_days' => 90,
                'duration_label' => '۳ ماهه',
                'features_json' => json_encode(array(
                    'دسترسی کامل به دوره‌های مقدماتی و متوسط',
                    'دسترسی به مسترکلاس گلیم و پته‌دوزی سنتی',
                    'دریافت نقشه‌های شطرنجی و راهنمای چله‌کشی',
                    'تماشای درس‌ها با کیفیت Full HD'
                ), JSON_UNESCAPED_UNICODE),
                'is_popular' => 1,
                'is_active' => 1,
            ),
            array(
                'id' => 'plan-gold-12m',
                'tier' => 'gold',
                'title' => 'اشتراک طلایی (جامع سالانه)',
                'description' => 'کامل‌ترین سطح دسترسی برای تبدیل شدن به استادکار و راه‌اندازی کارگاه خانگی',
                'price_toman' => 690000,
                'original_price_toman' => 980000,
                'duration_days' => 365,
                'duration_label' => '۱ ساله',
                'features_json' => json_encode(array(
                    'دسترسی نامحدود به تمامی دوره‌ها (مقدماتی، متوسط و پیشرفته)',
                    'شامل دوره تخصصی رنگرزی گیاهی و طراحی نقشه',
                    'جلسات ماهانه وبینار زنده و رفع اشکال با اساتید',
                    'گواهی پایان دوره اختصاصی مستر دستباف'
                ), JSON_UNESCAPED_UNICODE),
                'is_popular' => 0,
                'is_active' => 1,
            ),
        );

        foreach ($default_plans as $p) {
            $wpdb->insert($table_plans, $p);
        }
    }
}
