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
} from 'lucide-react';
// BUG-52: unused icon imports removed (Award, HelpCircle, Layers). BookOpen
// stays — it is used by the DEV/BUG-53 featured empty-state card.
import { Course, CourseCategory, HomepageSectionConfig, MembershipPlan } from '../types';
import { INITIAL_HOMEPAGE_SECTIONS } from '../data/mockData';
import { adminService } from '../services/adminService';
import { courseService } from '../services/courseService';
import { membershipService } from '../services/membershipService';
import { PWAInstallButton } from '../components/common/PWAInstallButton';

interface HomeViewProps {
  onNavigate: (view: string, param?: any) => void;
  onOpenLessonPreview: (course: Course) => void;
  onSelectPlan: (plan: MembershipPlan) => void;
}

export const HomeView: React.FC<HomeViewProps> = ({
  onNavigate,
  onOpenLessonPreview,
  onSelectPlan,
}) => {
  const [sections, setSections] = useState<HomepageSectionConfig[]>(INITIAL_HOMEPAGE_SECTIONS);
  const [featuredCourses, setFeaturedCourses] = useState<Course[]>([]);
  // BUG-53: the full course list feeds the derived category counts (no more
  // hardcoded cat.count values drifting from the real catalogue).
  const [allCourses, setAllCourses] = useState<Course[]>([]);
  const [plans, setPlans] = useState<MembershipPlan[]>([]);
  const [isCoursesLoading, setIsCoursesLoading] = useState(true);
  const [isPlansLoading, setIsPlansLoading] = useState(true);
  const [expandedFaq, setExpandedFaq] = useState<number | null>(null);

  useEffect(() => {
    let stale = false;
    // BUG-43f: all three home loads previously ran bare (unhandled rejection on
    // failure, no loading flags). Each now has .catch + minimal skeletons.
    adminService
      .getHomepageSections()
      .then((s) => {
        if (!stale) setSections(s);
      })
      .catch(() => {
        // graceful: the INITIAL_HOMEPAGE_SECTIONS fallback stays on screen
      });

    courseService
      .getCourses()
      .then((c) => {
        if (stale) return;
        setAllCourses(c);
        setFeaturedCourses(c.filter((item) => item.featured));
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

    return () => {
      stale = true;
    };
  }, []);

  // BUG-53: live per-category counts derived from courseService data
  const categoryCountByTitle = new Map<string, number>();
  allCourses.forEach((c) => {
    categoryCountByTitle.set(c.category, (categoryCountByTitle.get(c.category) ?? 0) + 1);
  });

  const categories: Array<{ title: CourseCategory; desc: string; icon: string; count: number }> = [
    { title: 'قالی‌بافی و گلیم', desc: 'گره ترکی، چله‌کشی تبریز و نقوش ایلیاتی', icon: '🧶', count: categoryCountByTitle.get('قالی‌بافی و گلیم') ?? 0 },
    { title: 'رودوزی‌های سنتی', desc: 'پته‌دوزی کرمان و سوزن‌دوزی‌های اصیل', icon: '🪡', count: categoryCountByTitle.get('رودوزی‌های سنتی') ?? 0 },
    { title: 'مکرمه و منسوجات', desc: 'گره‌بافی مدرن و دکوراتیو دست‌ساز', icon: '🧵', count: categoryCountByTitle.get('مکرمه و منسوجات') ?? 0 },
    { title: 'رنگرزی طبیعی', desc: 'استخراج رنگ گیاهی از روناس و پوست گردو', icon: '🌿', count: categoryCountByTitle.get('رنگرزی طبیعی') ?? 0 },
  ];

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
                        'مجموعه مسترکلاس‌های تصویری قالی‌بافی، گلیم، پته و منسوجات دستباف، همراه با هدایت گام‌به‌گام استادکاران نام‌آور'}
                    </p>

                    <div className="pt-2 flex flex-wrap items-center gap-3">
                      <button
                        onClick={() => onNavigate('catalogue')}
                        className="bg-amber-500 hover:bg-amber-400 text-stone-950 font-bold px-5 py-2.5 rounded-xl text-xs sm:text-sm transition shadow-md flex items-center gap-2"
                      >
                        <span>مشاهده دوره‌ها و شروع یادگیری</span>
                        <ArrowLeft className="w-4 h-4" />
                      </button>

                      <button
                        onClick={() => onNavigate('membership')}
                        className="bg-stone-800/80 hover:bg-stone-800 text-amber-200 border border-amber-700/50 font-semibold px-4 py-2.5 rounded-xl text-xs sm:text-sm transition flex items-center gap-1.5"
                      >
                        <Crown className="w-4 h-4 text-amber-400" />
                        <span>پلن‌های عضویت و اشتراک</span>
                      </button>
                    </div>

                    {/* Highlights stats row */}
                    <div className="pt-4 border-t border-amber-800/50 grid grid-cols-3 gap-3 text-center sm:text-right">
                      <div>
                        <p className="text-lg sm:text-xl font-bold font-mono text-amber-300">+۱,۴۰۰</p>
                        <p className="text-[11px] text-stone-400">هنرجوی فعال در سراسر ایران</p>
                      </div>
                      <div>
                        <p className="text-lg sm:text-xl font-bold font-mono text-amber-300">۶ دوره</p>
                        <p className="text-[11px] text-stone-400">مسترکلاس ویدیویی گام‌به‌گام</p>
                      </div>
                      <div>
                        <p className="text-lg sm:text-xl font-bold font-mono text-amber-300">۱۰۰٪ دستی</p>
                        <p className="text-[11px] text-stone-400">تمدید اشتراک بدون کسر خودکار</p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          );
        }

        // 2. CATEGORIES
        if (sec.key === 'categories') {
          return (
            <section key={sec.id} className="max-w-5xl mx-auto px-4">
              <div className="flex items-end justify-between mb-4">
                <div>
                  <h2 className="text-lg sm:text-xl font-bold text-amber-950">{sec.title}</h2>
                  <p className="text-xs text-stone-500 mt-0.5">{sec.subtitle}</p>
                </div>
                <button
                  onClick={() => onNavigate('catalogue')}
                  className="text-xs font-semibold text-amber-800 hover:text-amber-900 flex items-center gap-1"
                >
                  همه شاخه‌ها
                  <ArrowLeft className="w-3.5 h-3.5" />
                </button>
              </div>

              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3">
                {categories.map((cat, idx) => (
                  <button
                    key={idx}
                    onClick={() => onNavigate('catalogue', { category: cat.title })}
                    className="p-3.5 rounded-2xl bg-white border border-stone-200/90 hover:border-amber-700/60 hover:shadow-md text-right transition group"
                  >
                    <span className="text-2xl mb-2 block">{cat.icon}</span>
                    <h3 className="font-bold text-xs sm:text-sm text-stone-900 group-hover:text-amber-900 transition">
                      {cat.title}
                    </h3>
                    <p className="text-[11px] text-stone-500 mt-1 line-clamp-2 leading-relaxed">
                      {cat.desc}
                    </p>
                    <span className="inline-block mt-2 text-[10px] text-amber-800 font-medium">
                      {/* BUG-53: derived from live courseService data — an empty
                          category reads «به‌زودی» instead of a wrong «۰ دوره». */}
                      {cat.count > 0 ? `${cat.count.toLocaleString('fa-IR')} دوره تخصصی` : 'به‌زودی'}
                    </span>
                  </button>
                ))}
              </div>
            </section>
          );
        }

        // 3. FEATURED COURSES
        if (sec.key === 'featured_courses') {
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
              ) : featuredCourses.length === 0 ? (
                // BUG-53: explicit empty-state card instead of an empty grid
                <div className="bg-white rounded-2xl p-10 text-center border border-stone-200 text-stone-500 space-y-2">
                  <BookOpen className="w-10 h-10 text-stone-300 mx-auto" />
                  <h3 className="font-bold text-sm text-stone-800">هنوز دوره ویژه‌ای منتشر نشده است</h3>
                  <p className="text-xs text-stone-500">به‌زودی مسترکلاس‌های ویژه در این بخش قرار می‌گیرند.</p>
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
                          <img
                            src={course.instructorAvatar}
                            alt={course.instructorName}
                            className="w-6 h-6 rounded-full object-cover"
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
                    {storyData.author || 'علیرضا بافنده - بنیان‌گذار مستر دستباف'}
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
