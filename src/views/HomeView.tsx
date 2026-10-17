/**
 * @file views/HomeView.tsx
 * @description Dynamic, data-driven Persian homepage for Mr. Dastbaaf
 */

import React, { useState, useEffect } from 'react';
import {
  Sparkles,
  ArrowLeft,
  Crown,
  BookOpen,
  CheckCircle,
  ChevronDown,
  HeartHandshake,
  Play,
  Flame,
  ShoppingBag,
  ExternalLink,
} from 'lucide-react';
// BUG-52: unused icon imports removed (Award, HelpCircle, Layers). BookOpen
// stays — it is used by the DEV/BUG-53 featured empty-state card.
import { Course, HomepageSectionConfig, MembershipPlan, ShopCategory, ShopFeed, ShopProduct, UserProfile, AppUiConfig } from '../types';
import { INITIAL_HOMEPAGE_SECTIONS } from '../data/mockData';
import { adminService } from '../services/adminService';
import { courseService } from '../services/courseService';
import { membershipService } from '../services/membershipService';
import { shopService } from '../services/shopService';
import { PWAInstallButton } from '../components/common/PWAInstallButton';
// v1.5.7: آواتار برند شدهٔ مدرس — عکس قابل‌لود از پیشخوان، فال‌بک حرف اول نام
import { InstructorAvatar } from '../components/common/InstructorAvatar';

interface HomeViewProps {
  onNavigate: (view: string, param?: any) => void;
  onOpenLessonPreview: (course: Course) => void;
  onSelectPlan: (plan: MembershipPlan) => void;
  /**
   * v1.2.7 — «محتوای وب‌اپ» از پیشخوان (GET /app-ui): متن‌های بنر اصلی + عنوان/زیرنویس/
   * نمایانی/ترتیب سکشن‌ها. null/undefined = دمو یا خطا → رفتار قبلی (پیش‌فرض‌های کد).
   */
  appUi?: AppUiConfig | null;
  /** v1.3.0: برای حالت خالی سکشن «محصولات دست‌ساز» — راهنمای پیشخوان فقط برای مدیر */
  currentUser?: UserProfile | null;
  /**
   * v1.5.0: خرید درون‌اپ محصول فیزیکی — بازکردن مودال اطلاعات ارسال/پرداخت
   * (App مودال را مدیریت می‌کند؛ قرارداد افزونهٔ 1.6.0). غایب = دکمهٔ خرید رندر نمی‌شود.
   */
  onBuyProduct?: (product: ShopProduct) => void;
}

// ---------------------------------------------------------------------------
// Hardening (prelive 18-b — یافتهٔ 2-18): امنیت رندر configData.
//
// راستی‌آزمایی فاز پیاده‌سازی (کل src):
//  - هیچ dangerouslySetInnerHTML / innerHTML در کل کد فرانت وجود ندارد
//    (جستجو: rg 'dangerouslySetInnerHTML|innerHTML' → صفر نتیجه).
//  - configData در همهٔ سکشن‌ها فقط به‌صورت «متن» در React JSX رندر می‌شود
//    (badgeText/headline/subheadline/quoteText/author) — React متن را escape
//    می‌کند؛ هیچ href/src دینامیکی از configData ساخته نمی‌شود.
//
// برای آینده: هر سکشنی که خواست از configData مقدار URL-مانند (href لینک،
// src تصویر/ویدیو) رندر کند، موظف است از safeExternalUrl زیر عبور دهد —
// فقط scheme‌های http/https/mailto/tel مجازند و خروجی نامعتبر → null است.
// ---------------------------------------------------------------------------
export function safeExternalUrl(raw: unknown): string | null {
  if (typeof raw !== 'string') return null;
  const value = raw.trim();
  if (value === '') return null;
  try {
    const parsed = new URL(value);
    const scheme = parsed.protocol.replace(/:$/, '').toLowerCase();
    return ['http', 'https', 'mailto', 'tel'].includes(scheme) ? value : null;
  } catch {
    // URL مطلق نبود — مقادیر نسبی («/membership») برای anchor/video بی‌خطرند؛
    // اما مقادیر با «javascript:» و امثال آن در اینجا هرگز رد می‌شوند.
    if (/^(https?|mailto|tel):/i.test(value)) return value;
    return /^\/(?!\/)/.test(value) ? value : null;
  }
}

/** v1.5.0: کلاس چیپ «انتخاب دسته‌بندی» محصولات ویژه — فعال/غیرفعال */
function shopChipClass(active: boolean): string {
  return active
    ? 'shrink-0 px-3.5 py-1.5 rounded-full text-[11px] font-semibold border transition whitespace-nowrap bg-amber-800 border-amber-800 text-white shadow-xs'
    : 'shrink-0 px-3.5 py-1.5 rounded-full text-[11px] font-semibold border transition whitespace-nowrap bg-white border-stone-200 text-stone-600 hover:border-amber-700 hover:text-amber-900';
}

export const HomeView: React.FC<HomeViewProps> = ({
  onNavigate,
  onOpenLessonPreview,
  onSelectPlan,
  appUi,
  currentUser,
  onBuyProduct,
}) => {
  const [sections, setSections] = useState<HomepageSectionConfig[]>(INITIAL_HOMEPAGE_SECTIONS);
  // BUG-53: the full course list feeds the featuredCourses derivation
  // (v1.2.8: خواستهٔ پیشخوان — «دوره‌های برگزیده» بر اساس featuredCourseIds)
  const [allCourses, setAllCourses] = useState<Course[]>([]);
  const [plans, setPlans] = useState<MembershipPlan[]>([]);
  const [isCoursesLoading, setIsCoursesLoading] = useState(true);
  const [isPlansLoading, setIsPlansLoading] = useState(true);
  // v1.3.0 — «محصولات» (GET /shop/products): خالی = سکشن محتوایی ندارد
  // v1.4.0 — «محصولات ویژه» (فهرست سطح‌بالای featured در همان پاسخ — سرور قدیمی: [])
  const [shopCategories, setShopCategories] = useState<ShopCategory[]>([]);
  const [featuredProducts, setFeaturedProducts] = useState<ShopProduct[]>([]);
  const [isShopLoading, setIsShopLoading] = useState(true);
  // v1.5.0: «انتخاب دسته‌بندی» — چیپ فعال کاروسل محصولات ویژه را فیلتر می‌کند
  const [selectedShopCat, setSelectedShopCat] = useState<string>('all');
  const [expandedFaq, setExpandedFaq] = useState<number | null>(null);

  useEffect(() => {
    let stale = false;
    // BUG-43f: all three home loads previously ran bare (unhandled rejection on
    // failure, no loading flags). Each now has .catch + minimal skeletons.
    // v1.2.7: بارگذاری سکشن‌ها به اثر بعدی (appUi) منتقل شد — این اثر فقط داده‌ها.
    courseService
      .getCourses()
      .then((c) => {
        if (stale) return;
        setAllCourses(c);
        setIsCoursesLoading(false);
      })
      .catch(() => {
        if (!stale) setIsCoursesLoading(false);
      });

    membershipService
      .getActivePlans()
      .then((p) => {
        if (!stale) {
          setPlans(p);
          setIsPlansLoading(false);
        }
      })
      .catch(() => {
        if (!stale) setIsPlansLoading(false);
      });

    // v1.3.0 — «محصولات» (GET /shop/products): شکست ساکت است — سکشن
    // خالی می‌ماند و کل صفحهٔ نخست هرگز با شکست فروشگاه نمی‌شکند (همان الگوی
    // تحمل‌خطای بقیهٔ سکشن‌ها). v1.4.0: خوراک کامل {featured, categories}.
    shopService
      .fetchShopProducts()
      .then((feed: ShopFeed) => {
        if (!stale) {
          setShopCategories(feed.categories);
          setFeaturedProducts(feed.featured);
          setIsShopLoading(false);
        }
      })
      .catch(() => {
        if (!stale) setIsShopLoading(false);
      });

    return () => {
      stale = true;
    };
  }, []);

  // ---------------------------------------------------------------------------
  // v1.2.7 — «محتوای وب‌اپ»: اگر پیکربندی از پیشخوان رسیده باشد، سکشن‌ها از آن ساخته
  // می‌شوند (بنر Hero همیشه ردیف ۱ + سکشن‌های مرتب‌شده با پرچم enabled). وگرنه
  // رفتار قبلی (adminService دمو → INITIAL_HOMEPAGE_SECTIONS).
  // ---------------------------------------------------------------------------
  useEffect(() => {
    if (appUi) {
      const heroSection: HomepageSectionConfig = {
        id: 'sec-hero',
        key: 'hero',
        title: 'بنر اصلی و پیام آغازین',
        subtitle: '',
        isEnabled: true,
        sortOrder: 1,
        configData: {
          badgeText: appUi.hero.badgeText,
          headline: appUi.hero.headline,
          subheadline: appUi.hero.subheadline,
          ctaText: appUi.hero.ctaPrimaryText,
          secondaryCtaText: appUi.hero.ctaSecondaryText,
          stat1Value: appUi.hero.stat1Value,
          stat1Label: appUi.hero.stat1Label,
          stat2Value: appUi.hero.stat2Value,
          stat2Label: appUi.hero.stat2Label,
          stat3Value: appUi.hero.stat3Value,
          stat3Label: appUi.hero.stat3Label,
        },
      };
      const configured: HomepageSectionConfig[] = appUi.sections.map((s) => ({
        id: `sec-${s.key}`,
        key: s.key,
        title: s.title,
        subtitle: s.subtitle,
        isEnabled: s.enabled,
        sortOrder: s.sortOrder,
      }));
      setSections([heroSection, ...configured].sort((a, b) => a.sortOrder - b.sortOrder));
      return;
    }

    let stale = false;
    adminService
      .getHomepageSections()
      .then((s) => {
        if (!stale) setSections(s);
      })
      .catch(() => {
        // graceful: the INITIAL_HOMEPAGE_SECTIONS fallback stays on screen
      });
    return () => {
      stale = true;
    };
  }, [appUi]);

  // ---------------------------------------------------------------------------
  // v1.2.8 — «دوره‌های برگزیده» از پیشخوان (محتوای وب‌اپ ← دوره‌های برگزیده):
  // وقتی کارفرما در پیشخوان دوره انتخاب کرده باشد (featuredCourseIds)، همان‌ها
  // (بر اساس productId) رندر می‌شوند؛ فهرست خالی = فال‌بک به پرچم featured که
  // سرور واقعی نمی‌فرستد → کارت وضعیت خالی نشان داده می‌شود.
  // ---------------------------------------------------------------------------
  const featuredCourseIds = appUi?.featuredCourseIds ?? [];
  const featuredCourses =
    featuredCourseIds.length > 0
      ? allCourses.filter(
          (c) => typeof c.productId === 'number' && featuredCourseIds.includes(c.productId)
        )
      : allCourses.filter((c) => c.featured);

  const faqs = [
    {
      q: 'آیا برای شروع قالی‌بافی نیاز به ابزار گران‌قیمت است؟',
      a: 'خیر؛ برای شروع تنها به یک دار کوچک آموزشی رومیزی، قلاب، دفتین و مقداری نخ چله و پشم نیاز دارید که در درس اول دوره نحوه تهیه ارزان و استاندارد آن تشریح شده است.',
    },
    {
      q: 'سیستم عضویت و تمدید اشتراک چگونه کار می‌کند؟',
      a: 'عضویت در مستر دستباف کاملاً دستی است. شما با پرداخت ماهانه، ۳ ماهه یا سالانه به ویدیوها دسترسی پیدا می‌کنید و پس از اتمام دوره، هیچ وجهی به شکل خودکار کسر نمی‌شود؛ تمدید تماماً با اراده خود شما انجام می‌پذیرد.',
    },
    {
      q: 'آیا ویدیوها در موبایل قابل تماشا و ذخیره هستند؟',
      a: 'بله، اپلیکیشن برای نمایش تمام‌صفحه روی انواع گوشی‌های هوشمند بهینه‌سازی شده است. همچنین با نصب PWA می‌توانید به درس‌های کش‌شده دسترسی داشته باشید.',
    },
  ];

  return (
    <div className="space-y-12 pb-16">
      {/* PWA banner prompt for mobile users */}
      <div className="max-w-5xl mx-auto px-4 pt-2">
        <PWAInstallButton variant="banner" />
      </div>

      {/* Render Data-Driven Sections Based on Admin Order & State */}
      {sections.map((sec) => {
        if (!sec.isEnabled) return null;

        // 1. HERO BANNER
        if (sec.key === 'hero') {
          const heroData = sec.configData || {};
          return (
            <section key={sec.id} className="relative overflow-hidden pt-4 pb-8">
              <div className="max-w-5xl mx-auto px-4">
                <div className="bg-gradient-to-br from-amber-950 via-stone-900 to-amber-900 rounded-3xl p-6 sm:p-10 text-white relative overflow-hidden shadow-xl border border-amber-800/40">
                  {/* Subtle woven kilim decorative texture */}
                  <div className="absolute inset-0 opacity-15 bg-[radial-gradient(#fbbf24_1px,transparent_1px)] [background-size:20px_20px]" />
                  <div className="absolute -left-16 -top-16 w-64 h-64 rounded-full bg-amber-600/20 blur-3xl" />

                  <div className="relative z-10 max-w-2xl space-y-4">
                    <div className="inline-flex items-center gap-2 bg-amber-800/60 border border-amber-600/40 text-amber-200 px-3 py-1 rounded-full text-xs font-medium">
                      <Sparkles className="w-3.5 h-3.5 text-amber-300" />
                      <span>{heroData.badgeText || 'پلتفرم تخصصی آموزش صنایع دستی'}</span>
                    </div>

                    <h1 className="text-2xl sm:text-4xl font-black text-amber-50 leading-tight">
                      {heroData.headline || 'هنر بافندگی و اصالت ایرانی را با دستان خود زنده کنید'}
                    </h1>

                    <p className="text-stone-300 text-xs sm:text-sm leading-relaxed max-w-xl">
                      {heroData.subheadline ||
                        'مجموعه مسترکلاس‌های تصویری قالی‌بافی، گلیم‌بافی و منسوجات دستباف، همراه با هدایت گام‌به‌گام استادکاران نام‌آور'}
                    </p>

                    <div className="pt-2 flex flex-wrap items-center gap-3">
                      <button
                        onClick={() => onNavigate('catalogue')}
                        className="bg-amber-500 hover:bg-amber-400 text-stone-950 font-bold px-5 py-2.5 rounded-xl text-xs sm:text-sm transition shadow-md flex items-center gap-2"
                      >
                        {/* v1.2.7: متن دکمهٔ اصلی از پیشخوان (محتوای وب‌اپ) */}
                        <span>{heroData.ctaText || 'مشاهده دوره‌ها و شروع یادگیری'}</span>
                        <ArrowLeft className="w-4 h-4" />
                      </button>

                      <button
                        onClick={() => onNavigate('membership')}
                        className="bg-stone-800/80 hover:bg-stone-800 text-amber-200 border border-amber-700/50 font-semibold px-4 py-2.5 rounded-xl text-xs sm:text-sm transition flex items-center gap-1.5"
                      >
                        <Crown className="w-4 h-4 text-amber-400" />
                        {/* v1.2.7: متن دکمهٔ دوم از پیشخوان (محتوای وب‌اپ) */}
                        <span>{heroData.secondaryCtaText || 'پلن‌های عضویت و اشتراک'}</span>
                      </button>
                    </div>

                    {/* Highlights stats row — v1.2.7: مقادیر/برچسب‌ها از پیشخوان */}
                    <div className="pt-4 border-t border-amber-800/50 grid grid-cols-3 gap-3 text-center sm:text-right">
                      <div>
                        <p className="text-lg sm:text-xl font-bold font-mono text-amber-300">{heroData.stat1Value || '+۱,۴۰۰'}</p>
                        <p className="text-[11px] text-stone-400">{heroData.stat1Label || 'هنرجوی فعال در سراسر ایران'}</p>
                      </div>
                      <div>
                        <p className="text-lg sm:text-xl font-bold font-mono text-amber-300">{heroData.stat2Value || '۶ دوره'}</p>
                        <p className="text-[11px] text-stone-400">{heroData.stat2Label || 'مسترکلاس ویدیویی گام‌به‌گام'}</p>
                      </div>
                      <div>
                        <p className="text-lg sm:text-xl font-bold font-mono text-amber-300">{heroData.stat3Value || '۱۰۰٪ دستی'}</p>
                        <p className="text-[11px] text-stone-400">{heroData.stat3Label || 'تمدید اشتراک بدون کسر خودکار'}</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          );
        }

        // 2. CATEGORIES — v1.2.8 بازنشسته شد (به درخواست کارفرما: «دوره‌های آموزشی
        //    دسته‌بندی لازم ندارن»)؛ ردیف ذخیره‌شدهٔ قدیمی در /app-ui نادیده گرفته می‌شود.

        // 3. FEATURED COURSES
        // v1.5.2 — نشت ادمین به کاربر عادی:
        // قبلاً وقتی دورهٔ ویژه‌ای وجود نداشت، کارت «هنوز دوره ویژه‌ای منتشر نشده است»
        // به همهٔ کاربران نشان داده می‌شد و متن «این بخش از پیشخوان وردپرس مدیریت
        // می‌شود» برای کاربر عادی نامناسب بود. حالا:
        //   - کاربر عادی: کل سکشن مخفی می‌شود (مثل shop خالی)
        //   - مدیر: راهنمای پیشخوان می‌بیند (برای راهنمایی خودش)
        if (sec.key === 'featured_courses') {
          const isFeaturedEmpty = !isCoursesLoading && featuredCourses.length === 0;
          if (isFeaturedEmpty && currentUser?.role !== 'admin') return null;

          return (
            <section key={sec.id} className="max-w-5xl mx-auto px-4">
              <div className="flex items-end justify-between mb-4">
                <div>
                  <div className="flex items-center gap-2">
                    <h2 className="text-lg sm:text-xl font-bold text-amber-950">{sec.title}</h2>
                    <span className="bg-red-100 text-red-800 text-[10px] font-bold px-2 py-0.5 rounded-full flex items-center gap-1">
                      <Flame className="w-3 h-3 text-red-600" />
                      ویژه
                    </span>
                  </div>
                  <p className="text-xs text-stone-500 mt-0.5">{sec.subtitle}</p>
                </div>
                <button
                  onClick={() => onNavigate('catalogue')}
                  className="text-xs font-semibold text-amber-800 hover:text-amber-900 flex items-center gap-1"
                >
                  مشاهده همه
                  <ArrowLeft className="w-3.5 h-3.5" />
                </button>
              </div>

              {isCoursesLoading ? (
                // BUG-43f: minimal skeleton while the featured courses load
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="bg-white rounded-2xl border border-stone-200/90 overflow-hidden shadow-xs">
                      <div className="aspect-video bg-stone-200 animate-pulse" />
                      <div className="p-4 space-y-2">
                        <div className="h-3 w-1/3 bg-stone-200 rounded animate-pulse" />
                        <div className="h-4 w-3/4 bg-stone-200 rounded animate-pulse" />
                        <div className="h-3 w-2/3 bg-stone-200 rounded animate-pulse" />
                      </div>
                    </div>
                  ))}
                </div>
              ) : isFeaturedEmpty ? (
                // v1.5.2: فقط برای مدیر — راهنمای مسیر پیشخوان (کاربر عادی کل سکشن را نمی‌بیند)
                <div className="bg-white rounded-2xl p-8 text-center border border-amber-200 text-amber-900 space-y-2 max-w-xl mx-auto">
                  <BookOpen className="w-8 h-8 text-amber-400 mx-auto" />
                  <p className="text-xs leading-relaxed">
                    دوره‌های برگزیده از پیشخوان ← مستر دستباف ← محتوای وب‌اپ ← دوره‌های برگزیده انتخاب می‌شوند.
                  </p>
                </div>
              ) : (
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                {featuredCourses.map((course) => (
                  <div
                    key={course.id}
                    className="bg-white rounded-2xl border border-stone-200/90 overflow-hidden shadow-xs hover:shadow-md transition flex flex-col group"
                  >
                    <div className="relative aspect-video overflow-hidden bg-stone-100">
                      <img
                        src={course.coverImageUrl}
                        alt={course.title}
                        className="w-full h-full object-cover group-hover:scale-105 transition duration-500"
                        loading="lazy"
                      />
                      <div className="absolute top-2.5 right-2.5 bg-black/60 backdrop-blur-xs text-white text-[10px] px-2 py-0.5 rounded-md font-medium">
                        سطح: {course.level}
                      </div>
                      <div className="absolute bottom-2.5 left-2.5 bg-amber-900/90 text-amber-200 text-[10px] px-2 py-0.5 rounded-md font-semibold">
                        {course.requiredTier === 'gold'
                          ? 'اشتراک طلایی'
                          : course.requiredTier === 'silver'
                          ? 'اشتراک نقره‌ای'
                          : 'اشتراک برنزی'}
                      </div>
                    </div>

                    <div className="p-4 flex-1 flex flex-col justify-between space-y-3">
                      <div>
                        <span className="text-[11px] text-amber-800 font-semibold">{course.category}</span>
                        <h3 className="font-bold text-sm text-stone-900 mt-1 leading-snug line-clamp-2">
                          {course.title}
                        </h3>
                        <p className="text-xs text-stone-500 mt-1.5 line-clamp-2 leading-relaxed">
                          {course.shortDescription}
                        </p>
                      </div>

                      <div className="pt-3 border-t border-stone-100 flex items-center justify-between">
                        <div className="flex items-center gap-2">
                          <InstructorAvatar
                            src={course.instructorAvatar}
                            name={course.instructorName}
                            className="w-6 h-6 rounded-full"
                          />
                          <span className="text-[11px] text-stone-600 font-medium">
                            {course.instructorName}
                          </span>
                        </div>

                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => onOpenLessonPreview(course)}
                            className="p-1.5 text-amber-800 hover:bg-amber-50 rounded-lg text-xs font-semibold flex items-center gap-1 transition"
                            title="مشاهده پیش‌نمایش درس"
                          >
                            <Play className="w-3.5 h-3.5 fill-amber-800" />
                            <span>پیش‌نمایش</span>
                          </button>
                          <button
                            onClick={() => onNavigate('course_detail', { courseId: course.id })}
                            className="bg-stone-900 hover:bg-amber-900 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition"
                          >
                            جزئیات
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                ))}
                </div>
              )}
            </section>
          );
        }

        // 4. ARTISAN STORY / EDITORIAL QUOTE
        if (sec.key === 'artisan_story') {
          const storyData = sec.configData || {};
          return (
            <section key={sec.id} className="max-w-5xl mx-auto px-4">
              <div className="bg-[#f5f0e6] border border-amber-200/80 rounded-3xl p-6 sm:p-8 text-stone-800 flex flex-col md:flex-row items-center gap-6 shadow-xs">
                <div className="w-20 h-20 sm:w-28 sm:h-28 rounded-2xl bg-amber-800/10 border border-amber-800/20 flex items-center justify-center shrink-0">
                  <HeartHandshake className="w-10 h-10 sm:w-14 sm:h-14 text-amber-800" />
                </div>
                <div className="space-y-2 text-right flex-1">
                  <h3 className="font-bold text-base sm:text-lg text-amber-950">{sec.title}</h3>
                  <blockquote className="text-xs sm:text-sm text-stone-700 italic leading-relaxed">
                    {storyData.quoteText ||
                      '«هر گره‌ای که بر تار قالی می‌نشیند، تپش قلبی است که فرهنگ و صبوری ایرانی را به آیندگان می‌سپارد.»'}
                  </blockquote>
                  <p className="text-[11px] text-amber-900 font-bold">
                    {storyData.author || 'محمدرضا خاموشیان - بنیان‌گذار مستر دستباف'}
                  </p>
                </div>
                <button
                  onClick={() => onNavigate('editorial', { slug: 'about-us' })}
                  className="bg-amber-800 hover:bg-amber-900 text-white text-xs font-semibold px-4 py-2 rounded-xl transition shrink-0"
                >
                  مطالعه داستان کامل
                </button>
              </div>
            </section>
          );
        }

        // 4.5 — v1.3.0: «محصولات دست‌ساز» (GET /shop/products — افزونهٔ ۱.۴.۰)
        // v1.5.0 (خواستهٔ کارفرما): گریدِ «همهٔ محصولات» حذف شد — فقط «محصولات
        // ویژه» می‌ماند با «انتخاب دسته‌بندی» (چیپ‌های فیلتر) و «خرید درون‌اپ»
        // (افزونهٔ ۱.۶.۰: سفارش واقعی ووکامرس + اطلاع ادمین). کاروسل افقی
        // scroll-snap از ۱.۴.۰ عیناً حفظ شده و الان فیلترپذیر است.
        // خالی بودن فروشگاه برای کاربر عادی یعنی سکشن اصلاً رندر نمی‌شود و فقط
        // مدیر راهنمای پیشخوان می‌بیند.
        if (sec.key === 'physical_products') {
          const isShopEmpty = !isShopLoading && featuredProducts.length === 0;
          if (isShopEmpty && currentUser?.role !== 'admin') return null;

          // چیپ‌های دسته‌بندی از ضرب featured × categories پاسخِ همین سرور ساخته
          // می‌شوند (بدون تغییر قرارداد API): محصول چنددسته‌ای زیر همهٔ دسته‌هایش
          // می‌آید؛ محصول بی‌دسته فقط زیر «همه» دیده می‌شود.
          const catNamesByProduct = new Map<number, string[]>();
          shopCategories.forEach((cat) => {
            cat.products.forEach((p) => {
              const names = catNamesByProduct.get(p.id) ?? [];
              if (!names.includes(cat.name)) names.push(cat.name);
              catNamesByProduct.set(p.id, names);
            });
          });
          const shopCatNames: string[] = [];
          featuredProducts.forEach((p) => {
            (catNamesByProduct.get(p.id) ?? []).forEach((n) => {
              if (!shopCatNames.includes(n)) shopCatNames.push(n);
            });
          });
          const visibleFeatured =
            selectedShopCat === 'all'
              ? featuredProducts
              : featuredProducts.filter((p) => (catNamesByProduct.get(p.id) ?? []).includes(selectedShopCat));

          return (
            <section key={sec.id} className="max-w-5xl mx-auto px-4">
              <div className="text-center max-w-xl mx-auto mb-6">
                <h2 className="text-lg sm:text-xl font-bold text-amber-950 flex items-center justify-center gap-2">
                  <ShoppingBag className="w-5 h-5 text-amber-800" />
                  <span>{sec.title}</span>
                </h2>
                <p className="text-xs text-stone-500 mt-1">{sec.subtitle}</p>
              </div>

              {isShopLoading ? (
                // skeleton هم‌سبک بقیهٔ سکشن‌ها
                <div className="flex gap-3 overflow-hidden">
                  {[0, 1, 2, 3].map((i) => (
                    <div
                      key={i}
                      className="w-40 sm:w-48 shrink-0 bg-white rounded-2xl border border-stone-200/90 overflow-hidden shadow-xs"
                    >
                      <div className="aspect-square bg-stone-200 animate-pulse" />
                      <div className="p-3 space-y-2">
                        <div className="h-3 w-3/4 bg-stone-200 rounded animate-pulse" />
                        <div className="h-6 w-full bg-stone-100 rounded-xl animate-pulse" />
                      </div>
                    </div>
                  ))}
                </div>
              ) : isShopEmpty ? (
                // فقط برای مدیر — راهنمای مسیر پیشخوان
                <div className="bg-white rounded-2xl p-8 text-center border border-amber-200 text-amber-900 space-y-2 max-w-xl mx-auto">
                  <ShoppingBag className="w-8 h-8 text-amber-400 mx-auto" />
                  <p className="text-xs leading-relaxed">
                    محصولات ویژه از پیشخوان ← مستر دستباف ← محصولات فیزیکی انتخاب می‌شوند (تیک ستون «ویژه» برای نمایش در اپ الزامی است).
                  </p>
                </div>
              ) : (
                <div>
                  {/* v1.5.0: «انتخاب دسته‌بندی» — چیپ‌های فیلتر کاروسل */}
                  {shopCatNames.length > 0 && (
                    <div className="flex gap-2 overflow-x-auto pb-2 mb-4 [-ms-overflow-style:none] [scrollbar-width:none]">
                      <button
                        type="button"
                        onClick={() => setSelectedShopCat('all')}
                        className={shopChipClass(selectedShopCat === 'all')}
                      >
                        همه
                      </button>
                      {shopCatNames.map((name) => (
                        <button
                          key={name}
                          type="button"
                          onClick={() => setSelectedShopCat(name)}
                          className={shopChipClass(selectedShopCat === name)}
                        >
                          {name}
                        </button>
                      ))}
                    </div>
                  )}

                  {/* کاروسل افقی scroll-snap — فقط محصولات ویژه */}
                  {visibleFeatured.length === 0 ? (
                    <div className="bg-white rounded-2xl p-6 text-center border border-stone-200 text-stone-500 text-xs">
                      محصولی در این دسته‌بندی نیست.
                    </div>
                  ) : (
                    <div className="flex gap-3 overflow-x-auto pb-2 snap-x snap-mandatory [-ms-overflow-style:none] [scrollbar-width:thin]">
                      {visibleFeatured.map((p) => {
                        // همان گارد امنیتی configData این فایل — فقط http/https/mailto/tel
                        const safeProductUrl = safeExternalUrl(p.url);
                        const safeImageUrl = safeExternalUrl(p.imageUrl);
                        return (
                          <div
                            key={p.id}
                            className="w-40 sm:w-48 shrink-0 snap-start bg-white rounded-2xl border border-stone-200/90 overflow-hidden shadow-xs hover:shadow-md transition flex flex-col"
                          >
                            {safeImageUrl ? (
                              <img
                                src={safeImageUrl}
                                alt={p.title}
                                loading="lazy"
                                className="aspect-square w-full object-cover bg-stone-100"
                              />
                            ) : (
                              // کاشی حرف‌اول — دمو و محصولات بدون تصویر (بدون هیچ تصویر بیرونی)
                              <div className="aspect-square w-full bg-gradient-to-br from-amber-100 to-amber-200 flex items-center justify-center">
                                <span className="text-2xl font-black text-amber-800">
                                  {p.title.slice(0, 1)}
                                </span>
                              </div>
                            )}

                            <div className="p-3 flex-1 flex flex-col justify-between gap-2.5">
                              <h4 className="text-xs font-bold text-stone-900 leading-snug line-clamp-2">
                                {p.title}
                              </h4>
                              <div className="space-y-2">
                                <p className="text-[11px] text-stone-500">
                                  <span className="font-black text-amber-900 font-mono">
                                    {p.price.toLocaleString('fa-IR')}
                                  </span>{' '}
                                  تومان
                                </p>
                                <div className="flex flex-col gap-1.5">
                                  {/* v1.5.0: خرید درون‌اپ — سفارش واقعی ووکامرس (افزونهٔ ۱.۶.۰) */}
                                  {onBuyProduct && (
                                    <button
                                      type="button"
                                      onClick={() => onBuyProduct(p)}
                                      className="w-full bg-amber-800 hover:bg-amber-900 text-white text-[11px] font-semibold px-3 py-2 rounded-xl transition flex items-center justify-center gap-1.5"
                                    >
                                      <ShoppingBag className="w-3 h-3" />
                                      <span>خرید از اپ</span>
                                    </button>
                                  )}
                                  {safeProductUrl && (
                                    <a
                                      href={safeProductUrl}
                                      target="_blank"
                                      rel="noopener noreferrer"
                                      className="w-full border border-stone-300 hover:border-amber-800 hover:text-amber-900 text-stone-600 text-[11px] font-semibold px-3 py-2 rounded-xl transition flex items-center justify-center gap-1.5"
                                    >
                                      <ExternalLink className="w-3 h-3" />
                                      <span>مشاهده در سایت</span>
                                    </a>
                                  )}
                                </div>
                              </div>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  )}
                </div>
              )}
            </section>
          );
        }

        // 5. MEMBERSHIP PLANS PREVIEW
        if (sec.key === 'membership_plans') {
          return (
            <section key={sec.id} className="max-w-5xl mx-auto px-4">
              <div className="text-center max-w-xl mx-auto mb-6">
                <h2 className="text-lg sm:text-xl font-bold text-amber-950">{sec.title}</h2>
                <p className="text-xs text-stone-500 mt-1">{sec.subtitle}</p>
              </div>

              {isPlansLoading ? (
                // BUG-43f: minimal skeleton while the plans load
                <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                  {[0, 1, 2, 3].map((i) => (
                    <div
                      key={i}
                      className="rounded-2xl p-4 border border-stone-200 bg-white space-y-3"
                    >
                      <div className="h-4 w-2/3 bg-stone-200 rounded animate-pulse" />
                      <div className="h-3 w-full bg-stone-100 rounded animate-pulse" />
                      <div className="h-3 w-4/5 bg-stone-100 rounded animate-pulse" />
                      <div className="h-8 bg-stone-100 rounded-xl animate-pulse" />
                    </div>
                  ))}
                </div>
              ) : plans.length === 0 ? (
                // BUG-53: explicit empty-state card instead of an empty grid
                <div className="bg-white rounded-2xl p-10 text-center border border-stone-200 text-stone-500 space-y-2 max-w-md mx-auto">
                  <Crown className="w-10 h-10 text-stone-300 mx-auto" />
                  <h3 className="font-bold text-sm text-stone-800">پلنی برای نمایش وجود ندارد</h3>
                  <p className="text-xs text-stone-500">به‌زودی پلن‌های عضویت اعلام می‌شوند.</p>
                </div>
              ) : (
              <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                {plans.map((p) => (
                  <div
                    key={p.id}
                    className={`rounded-2xl p-4 border flex flex-col justify-between transition ${
                      p.isPopular
                        ? 'bg-amber-50/80 border-amber-600 shadow-md ring-1 ring-amber-600/30'
                        : 'bg-white border-stone-200'
                    }`}
                  >
                    <div>
                      {p.isPopular && (
                        <span className="bg-amber-700 text-white text-[10px] font-bold px-2 py-0.5 rounded-full inline-block mb-2">
                          پیشنهاد اکثر هنرجویان
                        </span>
                      )}
                      <h3 className="font-bold text-sm text-stone-900">{p.title}</h3>
                      <p className="text-[11px] text-stone-500 mt-1 leading-relaxed">{p.description}</p>

                      <div className="my-3 py-2 border-y border-stone-100 flex items-baseline justify-between">
                        <span className="text-[11px] text-stone-500">شهریه:</span>
                        <div className="flex items-baseline gap-1">
                          <span className="text-base font-black text-amber-900 font-mono">
                            {p.priceToman === 0 ? 'رایگان' : p.priceToman.toLocaleString('fa-IR')}
                          </span>
                          {p.priceToman > 0 && <span className="text-[10px] text-stone-500">تومان</span>}
                        </div>
                      </div>

                      <ul className="space-y-1.5 text-[11px] text-stone-600">
                        {p.features.slice(0, 3).map((f, i) => (
                          <li key={i} className="flex items-start gap-1.5">
                            <CheckCircle className="w-3.5 h-3.5 text-amber-700 shrink-0 mt-0.5" />
                            <span>{f}</span>
                          </li>
                        ))}
                      </ul>
                    </div>

                    <div className="pt-4">
                      <button
                        onClick={() => onSelectPlan(p)}
                        className={`w-full py-2 rounded-xl text-xs font-bold transition shadow-xs ${
                          p.isPopular
                            ? 'bg-amber-800 hover:bg-amber-900 text-white'
                            : 'bg-stone-100 hover:bg-stone-200 text-stone-800'
                        }`}
                      >
                        {p.priceToman === 0 ? 'شروع رایگان' : 'انتخاب و خرید دستی'}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
              )}
            </section>
          );
        }

        // 6. FAQ ACCORDION
        if (sec.key === 'faq') {
          return (
            <section key={sec.id} className="max-w-4xl mx-auto px-4">
              <div className="text-center mb-6">
                <h2 className="text-lg sm:text-xl font-bold text-amber-950">{sec.title}</h2>
                <p className="text-xs text-stone-500 mt-1">{sec.subtitle}</p>
              </div>

              <div className="space-y-2">
                {faqs.map((faq, idx) => (
                  <div
                    key={idx}
                    className="bg-white border border-stone-200 rounded-xl overflow-hidden shadow-xs"
                  >
                    <button
                      onClick={() => setExpandedFaq(expandedFaq === idx ? null : idx)}
                      className="w-full p-3.5 text-right font-semibold text-xs sm:text-sm text-stone-900 flex items-center justify-between gap-3 hover:bg-stone-50 transition"
                    >
                      <span>{faq.q}</span>
                      <ChevronDown
                        className={`w-4 h-4 text-stone-500 transition-transform ${
                          expandedFaq === idx ? 'rotate-180 text-amber-800' : ''
                        }`}
                      />
                    </button>
                    {expandedFaq === idx && (
                      <div className="p-3.5 pt-0 text-xs text-stone-600 leading-relaxed border-t border-stone-100 bg-[#fdfbf7]/50">
                        {faq.a}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </section>
          );
        }

        return null;
      })}
    </div>
  );
};
