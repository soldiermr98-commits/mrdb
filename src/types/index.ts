/**
 * @file types/index.ts
 * @description Domain types and interfaces for Mr. Dastbaaf Handicraft Learning Platform
 */

export type UserRole = 'student' | 'instructor' | 'admin';

export type MembershipTier = 'free' | 'bronze' | 'silver' | 'gold';

export interface UserMembership {
  tier: MembershipTier;
  planId: string;
  planName: string;
  status: 'active' | 'expired' | 'pending';
  startedAt: string; // ISO date
  expiresAt: string; // ISO date
  formattedExpireDateJalali: string; // e.g. "۱۴۰۵/۰۲/۱۵"
  daysRemaining: number;
}

export interface UserProfile {
  id: string;
  phoneNumber: string; // e.g. "09123456789"
  maskedPhone: string; // e.g. "0912***6789"
  fullName: string;
  avatarUrl?: string;
  role: UserRole;
  membership?: UserMembership;
  completedLessonIds: string[];
  bookmarkedLessonIds: string[];
  createdAt: string;
}

export type CourseLevel = 'مقدماتی' | 'متوسط' | 'پیشرفته';

export type CourseCategory = 
  | 'قالی‌بافی و گلیم'
  | 'رودوزی‌های سنتی'
  | 'مکرمه و منسوجات'
  | 'رنگرزی طبیعی'
  | 'طراحی نقشه و چله‌کشی';

export interface LessonNote {
  id: string;
  lessonId: string;
  // BUG-30: یادداشت‌ها per-user هستند؛ ردیف‌های قدیمی بدون userId با '' نشان داده
  // می‌شوند که عملاً برای هیچ کاربری نمایان نیست (مهاجرت نرم بدون اسکریپت).
  userId: string;
  timestampSeconds: number;
  content: string;
  createdAt: string;
}

export interface Lesson {
  id: string;
  courseId: string;
  sectionId: string;
  title: string;
  /**
   * v1.3.0 قرارداد سرور: duration عدد | null است (null/غایب = نامشخص).
   * نگاشت کلاینت: هر مقدار غیرمثبت/نامشخص → 0 و رندر سمت UI چیپ «دقیقه» را
   * فقط برای مقادیر > 0 نشان می‌دهد (هرگز «۰ دقیقه»). پلیر در حالت Real مدت
   * واقعی را از متادیتای <video> می‌گیرد.
   */
  durationMinutes: number;
  order: number;
  isFreePreview: boolean;
  requiredTier: MembershipTier;
  description: string;
  videoTokenId?: string; // Token ID for secure stream (never raw MP4 url)
}

export interface CourseSection {
  id: string;
  courseId: string;
  title: string;
  order: number;
  lessons: Lesson[];
}

export interface Course {
  id: string;
  title: string;
  slug: string;
  shortDescription: string;
  fullDescription: string;
  category: CourseCategory;
  level: CourseLevel;
  instructorName: string;
  instructorBio: string;
  instructorAvatar: string;
  thumbnailUrl: string;
  coverImageUrl: string;
  totalDurationHours: number;
  totalLessonsCount: number;
  requiredTier: MembershipTier; // Minimum tier required to watch all lessons
  priceToman: number; // One-time purchase alternative price
  featured: boolean;
  sections: CourseSection[];
  learningOutcomes: string[];
  prerequisites: string[];
  /**
   * v1.3.0 — مدت کل دوره به‌صورت دقیقهٔ صحیح از سرور (0/غایب = نامشخص).
   * فرمت نمایش: «{n} دقیقه» یا برای ≥۶۰ «{h} ساعت و {m} دقیقه» (utils/format.formatDurationFa).
   */
  durationMinutes?: number;
  /** v1.2.6 Real-API: شناسهٔ عددی محصول ووکامرس — مسیر مطمئن جزئیات (/courses/by-id) */
  productId?: number | null;
  /** v1.2.6 Real-API: تعداد درس‌های پیش‌نمایش رایگان (نشان کارت در کاتالوگ) */
  freePreviewCount?: number;
  /**
   * v1.2.8 — خرید تک‌دوره‌ای از سایت: کاربر با حساب ووکامرسیِ خود این محصول را
   * خریداری کرده است (سفارش wc-completed/wc-processing) → تمام جلسات همان دوره
   * بدون اشتراک باز می‌شود. فقط سرور این پرچم را ست می‌کند (guest همیشه false).
   */
  owned?: boolean;
}

export type PlanDuration = '1_month' | '3_months' | '6_months' | '12_months';

export interface MembershipPlan {
  id: string;
  tier: MembershipTier;
  title: string;
  description: string;
  priceToman: number;
  originalPriceToman?: number;
  duration: PlanDuration;
  durationLabel: string; // e.g. "۱ ماهه", "۳ ماهه", "۱ ساله"
  features: string[];
  isPopular?: boolean;
  isActive: boolean;
  colorScheme: 'slate' | 'amber' | 'stone' | 'yellow';
}

export type PaymentGatewayType = 'zibal' | 'card_to_card';
// BUG-34: 'verifying' برای گارد موازی‌سازی verify و BUG-35: 'processing' برای
// سفارش‌هایی که گرنتشان معلق شده؛ 'cancelled' برای انصراف صریح کاربر.
export type OrderStatus = 'pending' | 'processing' | 'verifying' | 'completed' | 'failed' | 'rejected' | 'cancelled';

export interface ManualTransferDetails {
  trackingCode: string;
  sourceCardLastFour: string;
  depositDateJalali: string;
  receiptImageName?: string;
  senderNotes?: string;
  adminReviewNote?: string;
  reviewedAt?: string;
}

export interface PaymentOrder {
  id: string;
  orderNumber: string;
  userId: string;
  userPhone: string;
  userFullName: string;
  itemType: 'membership' | 'course';
  itemId: string;
  itemTitle: string;
  amountToman: number;
  gateway: PaymentGatewayType;
  status: OrderStatus;
  createdAt: string;
  createdAtJalali: string;
  verifiedAt?: string;
  zibalTrackId?: string;
  zibalRefNumber?: string;
  manualTransferDetails?: ManualTransferDetails;
  // BUG-35: یادداشت داخلی برای حالت‌های خاص (مثلاً پلن سفارش یافت نشد)
  adminNote?: string;
  // BUG-37: اسنپ‌شات پلن در لحظه خرید تا گرنت به فروشگاه پلن‌ها وابسته نماند
  planSnapshot?: { tier: MembershipTier; durationDays: number };
}

export interface HomepageSectionConfig {
  id: string;
  // v1.2.8: سکشن «categories» بازنشسته شد (به درخواست کارفرما — دوره‌ها دسته‌بندی لازم ندارند)
  // v1.3.0: سکشن «physical_products» (محصولات دست‌ساز از GET /shop/products) اضافه شد
  key: 'hero' | 'featured_courses' | 'membership_plans' | 'artisan_story' | 'faq' | 'physical_products';
  title: string;
  subtitle?: string;
  isEnabled: boolean;
  sortOrder: number;
  configData?: Record<string, any>;
}

export interface EditorialPage {
  slug: string;
  title: string;
  content: string;
  excerpt: string;
  lastUpdatedJalali: string;
}

export interface VideoStreamingTicket {
  ticketId: string;
  lessonId: string;
  expiresInSeconds: number;
  /** TICKET FIX (BUG-12): absolute ISO expiry so the player can compute live TTL and re-auth. */
  issuedAt: string;
  expiresAt: string;
  watermarkText: string;
  watermarkUserId: string;
  // v1.1.0 Real-API: در حالت دمو برچسب mock ثابت است؛ در حالت Real از streamType سرور ساخته می‌شود.
  provider: string;
  qualityOptions: Array<{ label: string; resolution: string }>;
  /**
   * v1.1.0 Real-API: آدرس پخش واقعی دریافتی از «POST /lessons/{id}/playback» —
   * تنها منبع مجاز <video> در حالت اتصال واقعی. در دمو undefined می‌ماند.
   */
  videoUrl?: string;
  /**
   * v1.2.8 — نسخه‌های کیفیت اختیاری سرور (فیلتر mrdastbaaf_stream_video_qualities).
   * وقتی سرور نسخهٔ چندکیفیته دارد، پلیر انتخاب‌گر واقعی رندر می‌کند و با سوییچ
   * نسخه، جای پخش حفظ می‌شود. خالی/undefined = پخش خودکار طبق فایل اصلی.
   */
  qualities?: Array<{ label: string; url: string }>;
}

// ---------------------------------------------------------------------------
// v1.2.6 — صفحات سایت در منوی اپ (سطح دسترسی از پیشخوان وردپرس)
// ---------------------------------------------------------------------------

export type AppPageAccess = 'all' | 'members' | 'admins';

export interface AppPage {
  /** شناسهٔ یکتای رکورد (uid افزونه) — کلید مسیر #/app-page/{id} */
  id: string;
  title: string;
  access: AppPageAccess;
  sortOrder: number;
  /** v1.2.8: نشانی برگهٔ وردپرس (فقط صفحات publish) — برای رندر iframe با mrdapp=1 */
  url?: string;
}

export interface AppPageContent {
  id: string;
  title: string;
  /** HTML رندرشدهٔ برگهٔ وردپرس (فیلتر the_content سمت سرور) — فال‌بک */
  html: string;
  access: AppPageAccess;
  updatedAtJalali: string;
  /**
   * v1.2.8: نشانی واقعی برگه — وقتی هست، به‌جای HTML خام، صفحه با iframe و
   * پارامتر mrdapp=1 رندر می‌شود (فیکس استایل صفحات المنتور: CSS/JS کامل
   * المنتور در قالب Canvas خودش اجرا می‌شود و استایل به‌هم نمی‌ریزد).
   */
  url?: string;
}

// ---------------------------------------------------------------------------
// v1.2.7 — «محتوای وب‌اپ» از پیشخوان (افزونهٔ 1.3.4 → GET /app-ui)
// متن‌های بنر اصلی، سکشن‌های صفحهٔ اصلی و برچسب/نمایانی منو — همهٔ فیلدها
// اختیاری‌اند تا پاسخ ناقصِ سرور هرگز اپ را نشکند (پیش‌فرض‌ها سمت اپ هم هستند).
// ---------------------------------------------------------------------------

export interface AppUiHero {
  badgeText: string;
  headline: string;
  subheadline: string;
  ctaPrimaryText: string;
  ctaSecondaryText: string;
  stat1Value: string;
  stat1Label: string;
  stat2Value: string;
  stat2Label: string;
  stat3Value: string;
  stat3Label: string;
}

/** کلیدهای مجاز — دقیقاً همان‌هایی که HomeView رندر می‌کند (hero جدا سرو می‌شود) */
// v1.2.8: «categories» بازنشسته شد (به درخواست کارفرما)
// v1.3.0: «physical_products» (محصولات دست‌ساز) اضافه شد
export type AppUiSectionKey =
  | 'featured_courses'
  | 'artisan_story'
  | 'membership_plans'
  | 'faq'
  | 'physical_products';

export interface AppUiSection {
  key: AppUiSectionKey;
  title: string;
  subtitle: string;
  enabled: boolean;
  sortOrder: number;
}

export interface AppUiDockItem {
  key: 'home' | 'catalogue' | 'membership' | 'dashboard';
  label: string;
  enabled: boolean;
}

export type AppUiGuideKey = 'about-us' | 'guide-membership' | 'faq' | 'membership';

export interface AppUiGuideItem {
  key: AppUiGuideKey;
  label: string;
  enabled: boolean;
  sortOrder: number;
}

// ---------------------------------------------------------------------------
// v1.3.0 — «محصولات دست‌ساز» (GET /shop/products — افزونهٔ 1.4.0)
// گروه‌بندی محصولات فیزیکی بر اساس دستهٔ ووکامرس؛ رندر در سکشن «physical_products».
// ---------------------------------------------------------------------------

export interface ShopProduct {
  id: number;
  title: string;
  /** قیمت به تومان (عدد صحیح) */
  price: number;
  /** null = بدون تصویر → کاشی حرف‌اول در UI */
  imageUrl: string | null;
  /** نشانی صفحهٔ محصول در سایت — لینک «مشاهده در سایت» (target=_blank) */
  url: string;
}

export interface ShopCategory {
  id: number;
  slug: string;
  name: string;
  products: ShopProduct[];
}

/**
 * v1.4.0 — خوراک کامل «GET /shop/products» (قرارداد افزونهٔ 1.5.0):
 * {featured: [{id,title,price,imageUrl,url}], categories: [...]} — فهرست «featured»
 * سطح بالای پاسخ است (هم‌شکل آیتم محصولات دسته‌ها) و روی سرور قدیمی غایب است → [].
 */
export interface ShopFeed {
  featured: ShopProduct[];
  categories: ShopCategory[];
}

/**
 * v1.5.0 — اطلاعات ارسال برای «خرید درون‌اپ محصول فیزیکی» (POST
 * /payment/zibal/initiate {product_id, kind:'shop', ...} — افزونهٔ 1.6.0).
 * کدپستی اختیاری است؛ بقیهٔ فیلدها پیش از ارسال در UI سنجیده می‌شوند.
 */
export interface ShopShippingInfo {
  fullName: string;
  phone: string;
  address: string;
  postalCode: string;
}

/**
 * v1.5.7 — «مدرس» از پیشخوان (محتوای وب‌اپ ← مدرس دوره‌ها): وقتی سرور برای
 * تک‌تک دوره‌ها فیلد مدرس نمی‌فرستد، این بلاک سراسری به‌عنوان پیش‌فرض همهٔ
 * کارت‌ها/جزئیات استفاده می‌شود. photoUrl خالی = آواتار برند (حرف اول نام).
 */
export interface AppUiInstructor {
  name: string;
  photoUrl: string;
  bio: string;
}

export interface AppUiConfig {
  hero: AppUiHero;
  sections: AppUiSection[];
  menu: {
    dock: AppUiDockItem[];
    guides: AppUiGuideItem[];
  };
  /**
   * v1.2.8 — «دوره‌های برگزیده» از پیشخوان (محتوای وب‌اپ ← دوره‌های برگزیده):
   * شناسه‌های عددی محصولات ووکامرس انتخاب‌شده. فهرست خالی = سکشن خالی می‌ماند
   * (رفتار قبلی بر اساس پرچم featured که سرور نمی‌فرستد).
   */
  featuredCourseIds: number[];
  /** v1.5.7 — مدرس سراسری از پیشخوان (پیش‌فرض کد: «استاد خاموشیان») */
  instructor: AppUiInstructor;
}
