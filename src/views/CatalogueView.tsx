/**
 * @file views/CatalogueView.tsx
 * @description Course catalogue view with search, category filters, and access status badges
 */

import React, { useState, useEffect, useRef } from 'react';
import { Search, Filter, BookOpen, Clock, User, Play, ArrowLeft, Layers, X, AlertTriangle } from 'lucide-react';
import { Course, CourseCategory, UserProfile } from '../types';
import { courseService } from '../services/courseService';
import { membershipService } from '../services/membershipService';

interface CatalogueViewProps {
  initialCategory?: CourseCategory | 'all';
  onNavigate: (view: string, param?: any) => void;
  onOpenLessonPreview: (course: Course) => void;
  currentUser: UserProfile | null;
}

export const CatalogueView: React.FC<CatalogueViewProps> = ({
  initialCategory = 'all',
  onNavigate,
  onOpenLessonPreview,
  currentUser,
}) => {
  const [courses, setCourses] = useState<Course[]>([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [selectedCategory, setSelectedCategory] = useState<CourseCategory | 'all'>(initialCategory);
  const [selectedLevel, setSelectedLevel] = useState<string>('all');
  const [isLoading, setIsLoading] = useState(true);
  // BUG-42: explicit error state + retry tick (the old effect had no catch →
  // an rejected getCourses left the spinner spinning forever)
  const [loadError, setLoadError] = useState(false);
  const [reloadTick, setReloadTick] = useState(0);
  const requestSeqRef = useRef(0);

  const categories: Array<CourseCategory | 'all'> = [
    'all',
    'قالی‌بافی و گلیم',
    'رودوزی‌های سنتی',
    'مکرمه و منسوجات',
    'رنگرزی طبیعی',
  ];

  const levels = ['all', 'مقدماتی', 'متوسط', 'پیشرفته'];

  // BUG-42: the old effect fired getCourses on every keystroke (200ms artificial
  // delay each) with no catch and no stale-response guard. Now: a 300ms debounce
  // timer (cleaned up on re-render), a request counter so a slower older response
  // can never overwrite a newer one, and try/catch → error card with retry.
  useEffect(() => {
    const myRequest = ++requestSeqRef.current;
    const timer = window.setTimeout(() => {
      setIsLoading(true);
      setLoadError(false);
      courseService
        .getCourses({
          category: selectedCategory,
          searchTerm,
          level: selectedLevel,
        })
        .then((res) => {
          if (requestSeqRef.current !== myRequest) return; // stale response
          setCourses(res);
          setIsLoading(false);
        })
        .catch(() => {
          if (requestSeqRef.current !== myRequest) return; // stale failure
          setLoadError(true);
          setIsLoading(false);
        });
    }, 300);

    return () => window.clearTimeout(timer);
  }, [selectedCategory, searchTerm, selectedLevel, reloadTick]);

  return (
    <div className="max-w-5xl mx-auto px-4 py-6 space-y-6 pb-20">
      {/* Header */}
      <div className="space-y-1">
        <h1 className="text-xl sm:text-2xl font-black text-amber-950">کاتالوگ دوره‌های آموزشی</h1>
        <p className="text-xs sm:text-sm text-stone-600">
          مجموعه جامع مسترکلاس‌های تخصصی صنایع دستی، قالی‌بافی و هنرهای اصیل دستباف
        </p>
      </div>

      {/* Filter and Search Bar */}
      <div className="bg-white p-4 rounded-2xl border border-stone-200/90 shadow-xs space-y-3">
        {/* Search Input */}
        <div className="relative">
          <input
            type="text"
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
            placeholder="جستجو بر اساس نام دوره، تکنیک، یا نام استاد..."
            className="w-full pl-9 pr-10 py-2.5 bg-stone-50 border border-stone-200 rounded-xl text-xs sm:text-sm text-stone-800 placeholder-stone-400 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 transition"
          />
          <Search className="w-4 h-4 text-stone-400 absolute right-3.5 top-3.5" />
          {searchTerm && (
            <button
              onClick={() => setSearchTerm('')}
              className="absolute left-3 top-3 text-stone-400 hover:text-stone-700"
            >
              <X className="w-4 h-4" />
            </button>
          )}
        </div>

        {/* Category Tabs (Segmented Buttons) */}
        <div>
          <div className="flex items-center gap-1.5 overflow-x-auto pb-1 text-xs no-scrollbar">
            {categories.map((cat) => (
              <button
                key={cat}
                onClick={() => setSelectedCategory(cat)}
                className={`px-3 py-1.5 rounded-xl font-medium shrink-0 transition ${
                  selectedCategory === cat
                    ? 'bg-amber-800 text-white shadow-xs'
                    : 'bg-stone-100 text-stone-700 hover:bg-stone-200'
                }`}
              >
                {cat === 'all' ? 'همه شاخه‌ها' : cat}
              </button>
            ))}
          </div>
        </div>

        {/* Level Filter */}
        <div className="flex items-center gap-2 pt-2 border-t border-stone-100 text-xs">
          <span className="text-stone-500 font-medium shrink-0">سطح آموزشی:</span>
          <div className="flex items-center gap-1">
            {levels.map((lvl) => (
              <button
                key={lvl}
                onClick={() => setSelectedLevel(lvl)}
                className={`px-2.5 py-1 rounded-lg transition text-[11px] ${
                  selectedLevel === lvl
                    ? 'bg-stone-800 text-white font-bold'
                    : 'text-stone-600 hover:bg-stone-100'
                }`}
              >
                {lvl === 'all' ? 'همه سطوح' : lvl}
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Courses List */}
      {isLoading ? (
        <div className="py-16 text-center text-stone-500 text-xs">
          <div className="w-8 h-8 border-3 border-amber-800 border-t-transparent rounded-full animate-spin mx-auto mb-3" />
          در حال بارگذاری دوره‌ها...
        </div>
      ) : loadError ? (
        // BUG-42: rejection is no longer an infinite spinner — error card + retry
        <div className="bg-white rounded-2xl p-12 text-center border border-red-200 text-stone-500 space-y-3">
          <AlertTriangle className="w-12 h-12 text-red-400 mx-auto" />
          <h3 className="font-bold text-sm text-stone-800">بارگذاری دوره‌ها با خطا مواجه شد</h3>
          <p className="text-xs text-stone-500">لطفاً اتصال خود را بررسی کنید و دوباره تلاش کنید.</p>
          <button
            onClick={() => setReloadTick((t) => t + 1)}
            className="inline-flex min-h-11 items-center gap-1.5 bg-amber-800 hover:bg-amber-900 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition"
          >
            تلاش مجدد
          </button>
        </div>
      ) : courses.length === 0 ? (
        <div className="bg-white rounded-2xl p-12 text-center border border-stone-200 text-stone-500 space-y-3">
          <BookOpen className="w-12 h-12 text-stone-300 mx-auto" />
          <h3 className="font-bold text-sm text-stone-800">هیچ دوره‌ای با این مشخصات یافت نشد</h3>
          <p className="text-xs text-stone-500">عبارت جستجو یا فیلتر دسته‌بندی را تغییر دهید.</p>
          <button
            onClick={() => {
              setSearchTerm('');
              setSelectedCategory('all');
              setSelectedLevel('all');
            }}
            className="text-xs text-amber-800 font-semibold underline"
          >
            پاک کردن فیلترها
          </button>
        </div>
      ) : (
        <div className="space-y-3">
          {/* BUG-42: results count (Persian digits) */}
          <p className="text-[11px] text-stone-500">
            {courses.length.toLocaleString('fa-IR')} دوره یافت شد
          </p>

          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {courses.map((course) => {
            // BUG-41: «دسترسی کامل فعال» previously only checked membership.status —
            // an EXPIRED membership still showed the green badge. The shared
            // isMembershipLive() (status + live expiry date) closes that gap.
            const userHasAccess =
              currentUser?.role === 'admin' ||
              (membershipService.isMembershipLive(currentUser) &&
                (course.requiredTier === 'bronze' ||
                  (course.requiredTier === 'silver' && currentUser?.membership?.tier !== 'bronze') ||
                  (course.requiredTier === 'gold' && currentUser?.membership?.tier === 'gold')));

            return (
              <div
                key={course.id}
                className="bg-white rounded-2xl border border-stone-200/90 overflow-hidden shadow-xs hover:shadow-md transition flex flex-col justify-between group"
              >
                <div>
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

                    {/* Entitlement badge */}
                    <div className="absolute bottom-2.5 left-2.5">
                      {userHasAccess ? (
                        <span className="bg-emerald-900/90 text-emerald-200 text-[10px] px-2 py-0.5 rounded-md font-bold shadow-xs">
                          دسترسی کامل فعال
                        </span>
                      ) : (
                        <span className="bg-amber-900/90 text-amber-200 text-[10px] px-2 py-0.5 rounded-md font-semibold shadow-xs">
                          {course.requiredTier === 'gold'
                            ? 'نیازمند اشتراک طلایی'
                            : course.requiredTier === 'silver'
                            ? 'نیازمند اشتراک نقره‌ای'
                            : 'نیازمند اشتراک برنزی'}
                        </span>
                      )}
                    </div>
                  </div>

                  <div className="p-4 space-y-2">
                    <div className="flex items-center justify-between text-[11px] text-stone-500">
                      <span className="font-semibold text-amber-800">{course.category}</span>
                      <div className="flex items-center gap-1 font-mono">
                        <Clock className="w-3 h-3 text-stone-400" />
                        <span>{course.totalDurationHours} ساعت آموزش</span>
                      </div>
                    </div>

                    <h3 className="font-bold text-sm text-stone-900 leading-snug line-clamp-2">
                      {course.title}
                    </h3>

                    <p className="text-xs text-stone-500 line-clamp-2 leading-relaxed">
                      {course.shortDescription}
                    </p>
                  </div>
                </div>

                <div className="p-4 pt-0">
                  <div className="pt-3 border-t border-stone-100 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <img
                        src={course.instructorAvatar}
                        alt={course.instructorName}
                        className="w-6 h-6 rounded-full object-cover"
                      />
                      <span className="text-[11px] text-stone-700 font-medium truncate max-w-[110px]">
                        {course.instructorName}
                      </span>
                    </div>

                    <div className="flex items-center gap-1.5">
                      <button
                        onClick={() => onOpenLessonPreview(course)}
                        className="p-1.5 text-amber-800 hover:bg-amber-50 rounded-lg text-xs font-semibold flex items-center gap-1 transition"
                        title="مشاهده پیش‌نمایش درس"
                      >
                        <Play className="w-3.5 h-3.5 fill-amber-800" />
                        <span className="hidden sm:inline">پیش‌نمایش</span>
                      </button>

                      <button
                        onClick={() => onNavigate('course_detail', { courseId: course.id })}
                        className="bg-stone-900 hover:bg-amber-900 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition"
                      >
                        سرفصل‌ها
                      </button>
                    </div>
                  </div>
                </div>
              </div>
            );
          })}
          </div>
        </div>
      )}
    </div>
  );
};
