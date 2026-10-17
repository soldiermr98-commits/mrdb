/**
 * @file views/CourseDetailView.tsx
 * @description Course overview, instructor details, learning outcomes, and syllabus access lock
 */

import React, { useState, useEffect, useCallback } from 'react';
import {
  ArrowRight,
  Clock,
  BookOpen,
  Lock,
  Play,
  CheckCircle,
  Award,
  Crown,
  ChevronDown,
  ShieldAlert,
  ShoppingBag,
} from 'lucide-react';
import { Course, Lesson, PaymentGatewayType, UserProfile } from '../types';
import { courseService } from '../services/courseService';
import { formatDurationFa } from '../utils/format';
import { AsyncState } from '../components/common/AsyncState';
// v1.5.7: آواتار برند شدهٔ مدرس — عکس قابل‌لود از پیشخوان، فال‌بک حرف اول نام
import { InstructorAvatar } from '../components/common/InstructorAvatar';
import { useOnlineRefetch } from '../hooks/useOnlineRefetch';

interface CourseDetailViewProps {
  courseId: string;
  onNavigate: (view: string, param?: any) => void;
  onPlayLesson: (course: Course, lesson: Lesson) => void;
  currentUser: UserProfile | null;
  onOpenMembership: () => void;
  onOpenLogin: () => void;
  /**
   * v1.4.0 — خرید مستقیم این دوره با درگاه زیبال (مودال پرداخت). وقتی از App پاس
   * نشده باشد (پیش‌فرض undefined) دکمهٔ خرید رندر نمی‌شود تا مسیرهای قدیمی نشکنند.
   */
  onBuyCourse?: (gateway: PaymentGatewayType, course: Course) => void;
}

export const CourseDetailView: React.FC<CourseDetailViewProps> = ({
  courseId,
  onNavigate,
  onPlayLesson,
  currentUser,
  onOpenMembership,
  onOpenLogin,
  onBuyCourse,
}) => {
  const [course, setCourse] = useState<Course | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [lockedModalLesson, setLockedModalLesson] = useState<Lesson | null>(null);

  const loadCourse = useCallback(() => {
    setIsLoading(true);
    setLoadError(false);
    // BUG-43a: the old effect had no catch — a rejected load spun forever. Also
    // a `null` course (unknown courseId) fell into the same spinner branch.
    courseService
      .getCourseById(courseId)
      .then((c) => {
        setCourse(c);
        setIsLoading(false);
      })
      .catch(() => {
        setLoadError(true);
        setIsLoading(false);
      });
  }, [courseId]);

  useEffect(() => {
    loadCourse();
  }, [loadCourse]);

  // v1.3.0 — بازگشت اتصال → یک refetch بی‌صدا وقتی آخرین بار خطا خورده بود
  useOnlineRefetch(loadError, loadCourse);

  if (isLoading) {
    return (
      <AsyncState status="loading" loadingLabel="در حال بارگذاری اطلاعات دوره..." />
    );
  }

  if (loadError) {
    // BUG-43a: explicit error card with retry
    return (
      <div className="max-w-4xl mx-auto px-4 py-6">
        <AsyncState
          status="error"
          error="اطلاعات دوره بارگذاری نشد. لطفاً دوباره تلاش کنید."
          onRetry={loadCourse}
        />
      </div>
    );
  }

  if (!course) {
    // BUG-43a: unknown courseId no longer spins forever — explicit not-found
    // card with a way back to the catalogue.
    return (
      <div className="max-w-4xl mx-auto px-4 py-6 space-y-4">
        <button
          onClick={() => onNavigate('catalogue')}
          className="flex items-center gap-1.5 text-xs font-semibold text-stone-600 hover:text-amber-850 transition"
        >
          <ArrowRight className="w-4 h-4" />
          <span>بازگشت به لیست دوره‌ها</span>
        </button>
        <AsyncState
          status="empty"
          emptyTitle="دوره یافت نشد"
          emptyDescription="دوره‌ای با این نشانی وجود ندارد یا حذف شده است. از فهرست دوره‌ها گزینه دیگری را انتخاب کنید."
        />
      </div>
    );
  }

  // DEV-4: completed-lesson checkmarks (live — currentUser comes from App state
  // and refreshes whenever the player marks a lesson complete).
  const completedLessonIds = currentUser?.completedLessonIds ?? [];

  const handleLessonClick = (lesson: Lesson) => {
    // v1.2.8: course به گیت دسترسی پاس داده می‌شود — دورهٔ خریداری‌شده از سایت
    // (owned=true از سرور) بدون اشتراک باز می‌شود.
    const access = courseService.canAccessLesson(currentUser, lesson, course);
    if (access.canAccess) {
      onPlayLesson(course, lesson);
    } else {
      setLockedModalLesson(lesson);
    }
  };

  const allLessons = course.sections.flatMap((s) => s.lessons);
  const freePreviews = allLessons.filter((l) => l.isFreePreview);

  // v1.4.0 — خرید مستقیم دوره: فقط برای دورهٔ ناخریده با قیمت مثبت و productId
  // شناخته‌شده (قرارداد product_id زیبال). مهمان هم دکمه را می‌بیند؛ مودال ورود را
  // مدیریت می‌کند (همان رفتار checkout پلن).
  const canBuyDirectly =
    !course.owned && course.priceToman > 0 && typeof course.productId === 'number' && course.productId != null;

  // v1.3.0 — مدت کل دوره: اولویت با دقیقهٔ صحیح سرور (duration)؛ فال‌بک به
  // totalDurationHours دمو/سرور قدیمی. هر دو نامشخص = چیپ کلاً حذف می‌شود.
  const courseDurationLabel =
    formatDurationFa(course.durationMinutes) ??
    (course.totalDurationHours > 0 ? `${course.totalDurationHours.toLocaleString('fa-IR')} ساعت آموزش` : null);

  return (
    <div className="max-w-4xl mx-auto px-4 py-6 space-y-8 pb-28">
      {/* Back button */}
      <button
        onClick={() => onNavigate('catalogue')}
        className="flex items-center gap-1.5 text-xs font-semibold text-stone-600 hover:text-amber-850 transition"
      >
        <ArrowRight className="w-4 h-4" />
        <span>بازگشت به لیست دوره‌ها</span>
      </button>

      {/* Hero Overview Card */}
      <div className="bg-white rounded-3xl border border-stone-200/90 overflow-hidden shadow-xs">
        <div className="relative aspect-video sm:aspect-21/9 bg-stone-900 overflow-hidden">
          <img
            src={course.coverImageUrl}
            alt={course.title}
            className="w-full h-full object-cover opacity-90"
          />
          <div className="absolute inset-0 bg-gradient-to-t from-stone-950 via-stone-950/40 to-transparent" />
          <div className="absolute bottom-4 right-4 left-4 text-white space-y-2">
            <span className="bg-amber-600/90 text-amber-50 text-[10px] font-bold px-2.5 py-0.5 rounded-full inline-block">
              {course.category} · سطح {course.level}
            </span>
            <h1 className="text-xl sm:text-3xl font-black text-amber-50 leading-tight">
              {course.title}
            </h1>
            <div className="flex flex-wrap items-center gap-4 text-xs text-stone-300 pt-1">
              {/* v1.3.0: چیپ مدت فقط وقتی مقدار مشخصی هست (هرگز «۰ دقیقه»/«۰ ساعت») */}
              {courseDurationLabel && (
                <span className="flex items-center gap-1">
                  <Clock className="w-3.5 h-3.5 text-amber-400" />
                  {courseDurationLabel}
                </span>
              )}
              <span className="flex items-center gap-1">
                <BookOpen className="w-3.5 h-3.5 text-amber-400" />
                {allLessons.length} درس ویدیویی
              </span>
              <span className="flex items-center gap-1">
                <Award className="w-3.5 h-3.5 text-amber-400" />
                استاد: {course.instructorName}
              </span>
            </div>
          </div>
        </div>

        {/* Short Summary & CTAs */}
        <div className="p-6 space-y-4">
          <p className="text-xs sm:text-sm text-stone-600 leading-relaxed">
            {course.fullDescription}
          </p>

          <div className="flex flex-wrap items-center gap-3 pt-2">
            {freePreviews.length > 0 && (
              <button
                onClick={() => onPlayLesson(course, freePreviews[0])}
                className="bg-amber-800 hover:bg-amber-900 text-white font-bold px-4 py-2.5 rounded-xl text-xs flex items-center gap-2 shadow-xs transition"
              >
                <Play className="w-4 h-4 fill-white" />
                <span>تماشای پیش‌نمایش رایگان (درس اول)</span>
              </button>
            )}

            {/* v1.2.8: دورهٔ خریداری‌شده از سایت — نشان فعال به‌جای CTA اشتراک */}
            {course.owned ? (
              <span className="inline-flex items-center gap-1.5 bg-emerald-50 text-emerald-800 border border-emerald-200 font-semibold px-4 py-2.5 rounded-xl text-xs">
                <CheckCircle className="w-4 h-4 text-emerald-600" />
                <span>این دوره در حساب شما فعال است — تمام جلسات باز است</span>
              </span>
            ) : (
              <>
                {/* v1.4.0: CTA اصلی خرید مستقیم دوره (زیبال) — amber توپر، بدون Crown */}
                {canBuyDirectly && onBuyCourse && (
                  <button
                    onClick={() => onBuyCourse('zibal', course)}
                    className="bg-amber-800 hover:bg-amber-900 text-white font-bold px-4 py-2.5 rounded-xl text-xs flex items-center gap-1.5 shadow-xs transition"
                  >
                    <ShoppingBag className="w-4 h-4" />
                    <span>خرید این دوره ({course.priceToman.toLocaleString('fa-IR')} تومان)</span>
                  </button>
                )}
                {/* v1.4.0: CTA اشتراک — حالا دکمهٔ دوم (outline) کنار خرید مستقیم */}
                <button
                  onClick={onOpenMembership}
                  className="bg-white hover:bg-amber-50 border border-amber-300 text-amber-900 font-semibold px-4 py-2.5 rounded-xl text-xs flex items-center gap-1.5 transition"
                >
                  <Crown className="w-4 h-4 text-amber-700" />
                  <span>خرید اشتراک جهت دسترسی کامل</span>
                </button>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Learning Outcomes & Prerequisites */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Outcomes */}
        <div className="bg-white p-5 rounded-2xl border border-stone-200/90 shadow-xs space-y-3">
          <h3 className="font-bold text-sm text-amber-950 flex items-center gap-2">
            <CheckCircle className="w-4 h-4 text-emerald-600" />
            آنچه در این دوره می‌آموزید:
          </h3>
          <ul className="space-y-2 text-xs text-stone-600">
            {course.learningOutcomes.map((item, idx) => (
              <li key={idx} className="flex items-start gap-2">
                <span className="w-1.5 h-1.5 rounded-full bg-amber-700 mt-1.5 shrink-0" />
                <span>{item}</span>
              </li>
            ))}
          </ul>
        </div>

        {/* Instructor Bio & Prerequisites */}
        <div className="bg-white p-5 rounded-2xl border border-stone-200/90 shadow-xs space-y-4">
          <div>
            <h3 className="font-bold text-sm text-amber-950 mb-2">مدرس دوره:</h3>
            <div className="flex items-center gap-3">
              <InstructorAvatar
                src={course.instructorAvatar}
                name={course.instructorName}
                className="w-12 h-12 rounded-2xl border border-amber-200"
              />
              <div>
                <p className="font-bold text-xs text-stone-900">{course.instructorName}</p>
                <p className="text-[11px] text-stone-500 mt-0.5 leading-relaxed">{course.instructorBio}</p>
              </div>
            </div>
          </div>

          <div className="pt-3 border-t border-stone-100">
            <h4 className="font-bold text-xs text-stone-800 mb-1.5">پیش‌نیازها و وسایل:</h4>
            <ul className="space-y-1 text-xs text-stone-600">
              {course.prerequisites.map((p, i) => (
                <li key={i} className="flex items-center gap-2">
                  <span className="text-amber-800">·</span>
                  <span>{p}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>

      {/* Syllabus / Lessons List */}
      <div className="space-y-4">
        <div className="flex items-center justify-between">
          <h2 className="text-lg font-bold text-amber-950 flex items-center gap-2">
            <BookOpen className="w-5 h-5 text-amber-800" />
            سرفصل‌ها و جلسات آموزشی
          </h2>
          <span className="text-xs text-stone-500">{allLessons.length} جلسه ویدیویی</span>
        </div>

        <div className="space-y-3">
          {course.sections.map((section) => (
            <div
              key={section.id}
              className="bg-white rounded-2xl border border-stone-200/90 overflow-hidden shadow-xs"
            >
              <div className="bg-stone-50/80 px-4 py-3 border-b border-stone-200/70 flex items-center justify-between">
                <span className="font-bold text-xs sm:text-sm text-stone-900">
                  {section.title}
                </span>
                <span className="text-[11px] text-stone-500 font-mono">
                  {section.lessons.length} درس
                </span>
              </div>

              <div className="divide-y divide-stone-100">
                {section.lessons.map((lesson) => {
                  // v1.2.8: course پاس داده می‌شود — جلسات دورهٔ خریداری‌شده باز است
                  const access = courseService.canAccessLesson(currentUser, lesson, course);
                  // DEV-4: ✓ mark for lessons already completed by this user
                  const isCompleted = completedLessonIds.includes(lesson.id);

                  return (
                    <div
                      key={lesson.id}
                      className={`p-3.5 flex items-center justify-between gap-3 hover:bg-stone-50/60 transition ${
                        isCompleted ? 'bg-emerald-50/40' : ''
                      }`}
                    >
                      <div className="flex items-center gap-3">
                        <span className="w-6 h-6 rounded-full bg-stone-100 text-stone-600 text-xs font-mono font-bold flex items-center justify-center shrink-0">
                          {lesson.order}
                        </span>
                        <div>
                          <p className="text-xs font-bold text-stone-800 flex items-center gap-1.5">
                            <span>{lesson.title}</span>
                            {isCompleted && (
                              <CheckCircle
                                className="w-3.5 h-3.5 text-emerald-600 shrink-0"
                                aria-label="تکمیل شده"
                              />
                            )}
                          </p>
                          <div className="flex items-center gap-3 text-[11px] text-stone-500 mt-0.5">
                            {/* v1.3.0: چیپ دقیقه فقط برای درس‌های با مدت مشخص (> 0) — هرگز «۰ دقیقه» */}
                            {lesson.durationMinutes > 0 && (
                              <span className="flex items-center gap-1 font-mono">
                                <Clock className="w-3 h-3" />
                                {lesson.durationMinutes.toLocaleString('fa-IR')} دقیقه
                              </span>
                            )}
                            {lesson.isFreePreview && (
                              <span className="text-emerald-700 font-semibold bg-emerald-50 px-1.5 py-0.2 rounded">
                                رایگان
                              </span>
                            )}
                          </div>
                        </div>
                      </div>

                      <div>
                        {access.canAccess ? (
                          <button
                            onClick={() => handleLessonClick(lesson)}
                            className="flex items-center gap-1.5 bg-amber-800 hover:bg-amber-900 text-white px-3 py-1.5 rounded-xl text-xs font-semibold transition shadow-xs"
                          >
                            <Play className="w-3.5 h-3.5 fill-white" />
                            <span>تماشا</span>
                          </button>
                        ) : (
                          <button
                            onClick={() => handleLessonClick(lesson)}
                            className="flex items-center gap-1 text-stone-400 hover:text-amber-800 bg-stone-100 hover:bg-amber-50 px-2.5 py-1.5 rounded-xl text-xs transition"
                            title="قفل - نیازمند اشتراک"
                          >
                            <Lock className="w-3.5 h-3.5" />
                            <span className="text-[11px]">قفل</span>
                          </button>
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Locked Lesson Modal Prompt */}
      {lockedModalLesson && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs animate-in fade-in">
          <div className="bg-white rounded-2xl max-w-sm w-full p-6 text-center space-y-4 shadow-2xl border border-stone-200">
            <div className="w-12 h-12 rounded-full bg-amber-100 text-amber-800 flex items-center justify-center mx-auto">
              <Lock className="w-6 h-6" />
            </div>

            <div className="space-y-1">
              <h3 className="font-bold text-base text-stone-900">محتوای اختصاصی اعضا</h3>
              <p className="text-xs text-stone-600 leading-relaxed">
                درس «{lockedModalLesson.title}» نیازمند اشتراک{' '}
                <strong className="text-amber-900">
                  {lockedModalLesson.requiredTier === 'gold'
                    ? 'طلایی'
                    : lockedModalLesson.requiredTier === 'silver'
                    ? 'نقره‌ای'
                    : 'برنزی'}
                </strong>{' '}
                یا بالاتر است.
              </p>
            </div>

            <div className="bg-stone-50 p-2.5 rounded-xl text-[11px] text-stone-500 leading-relaxed text-right">
              لینک‌های ویدیوی این درس برای امنیت اساتید بر روی سرور محافظت شده‌اند و با فعال‌سازی اشتراک در دسترس قرار می‌گیرند.
            </div>

            <div className="space-y-2 pt-2">
              <button
                onClick={() => {
                  setLockedModalLesson(null);
                  onOpenMembership();
                }}
                className="w-full bg-amber-800 hover:bg-amber-900 text-white font-bold py-2.5 rounded-xl text-xs transition shadow-xs"
              >
                مشاهده و انتخاب پلن‌های عضویت
              </button>
              {!currentUser && (
                <button
                  onClick={() => {
                    setLockedModalLesson(null);
                    onOpenLogin();
                  }}
                  className="w-full bg-stone-100 hover:bg-stone-200 text-stone-800 font-semibold py-2 rounded-xl text-xs transition"
                >
                  ورود به حساب موجود
                </button>
              )}
              <button
                onClick={() => setLockedModalLesson(null)}
                className="text-stone-400 hover:text-stone-700 text-xs py-1"
              >
                انصراف
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};
