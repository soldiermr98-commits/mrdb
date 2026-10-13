/**
 * @file views/UserDashboardView.tsx
 * @description User student dashboard with membership status, learning progress, and order history
 */

import React, { useState, useEffect, useCallback, useMemo } from 'react';
import {
  Crown,
  BookOpen,
  Clock,
  CheckCircle,
  CreditCard,
  LogOut,
  Play,
  Bookmark,
  Calendar,
  RefreshCw,
} from 'lucide-react';
// BUG-52: unused imports removed (User, FileText, Landmark, AlertTriangle,
// Sparkles). Play + Bookmark stay — they are used by the DEV-4 «ادامه یادگیری»
// and DEV-5 «نشان‌شده‌ها» features below.
import { Course, Lesson, PaymentOrder, UserProfile } from '../types';
import { paymentService } from '../services/paymentService';
import { courseService } from '../services/courseService';
import { membershipService } from '../services/membershipService';
import { formatJalali, daysUntil, formatToman } from '../utils/format';
import { AsyncState } from '../components/common/AsyncState';

interface UserDashboardViewProps {
  currentUser: UserProfile;
  onLogout: () => void;
  onNavigate: (view: string, param?: any) => void;
  onOpenMembership: () => void;
  onPlayLesson: (course: Course, lesson: any) => void;
}

export const UserDashboardView: React.FC<UserDashboardViewProps> = ({
  currentUser,
  onLogout,
  onNavigate,
  onOpenMembership,
  onPlayLesson,
}) => {
  const [orders, setOrders] = useState<PaymentOrder[]>([]);
  const [accessibleCourses, setAccessibleCourses] = useState<Course[]>([]);
  const [allCourses, setAllCourses] = useState<Course[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);

  const loadData = useCallback(() => {
    setLoadError(false);
    // BUG-43d: the loads previously had no catch (a rejection left the view
    // silently stale) and isLoading was never rendered, so the orders section
    // falsely showed «تاکنون سفارشی ثبت نکرده‌اید» while loading.
    Promise.all([
      paymentService.getUserOrders(currentUser.id),
      courseService.getCourses(),
    ])
      .then(([ords, loadedCourses]) => {
        setOrders(ords);
        setAllCourses(loadedCourses);

        // Filter courses where user can access lessons
        // ACCESS GATE FIX (BUG-18): mirrors courseService.canAccessLesson — the
        // previous filter only checked membership.status, so an EXPIRED membership
        // (status still 'active' in localStorage) listed all tier courses here.
        const membershipIsLive = membershipService.isMembershipLive(currentUser);
        const accessible = loadedCourses.filter((c) => {
          if (currentUser.role === 'admin') return true;
          if (!membershipIsLive) return false;
          if (currentUser.membership!.tier === 'gold') return true;
          if (currentUser.membership!.tier === 'silver') return c.requiredTier !== 'gold';
          if (currentUser.membership!.tier === 'bronze') return c.requiredTier === 'bronze';
          return false;
        });

        setAccessibleCourses(accessible);
        setIsLoading(false);
      })
      .catch(() => {
        // BUG-43d: explicit error state with retry instead of a silent blank view
        setLoadError(true);
        setIsLoading(false);
      });
  }, [currentUser]);

  useEffect(() => {
    loadData();
  }, [loadData]);

  const membership = currentUser.membership;
  // LIVE STATUS FIX (BUG-18/BUG-24): «فعال» فقط وقتی status=active و انقضا در آینده باشد؛
  // و روز باقی‌مانده به‌جای مقدار منجمدِ زمان خرید، زنده محاسبه می‌شود.
  const isMembershipActive = membershipService.isMembershipLive(currentUser);
  // BUG-48: daysUntil returns number|null — null must render as «—», never «۰ روز».
  const liveDaysRemaining = membership ? daysUntil(membership.expiresAt) : null;

  const formatPrice = (p: number) => formatToman(p);

  // ------------------------------------------------------------------
  // DEV-4: course progress + continue watching
  // ------------------------------------------------------------------
  const completedLessonIds = currentUser.completedLessonIds ?? [];

  const lessonsOf = (c: Course) => c.sections.flatMap((s) => s.lessons);

  const progressFor = (c: Course) => {
    const lessons = lessonsOf(c);
    const completed = lessons.filter((l) => completedLessonIds.includes(l.id)).length;
    const pct = lessons.length > 0 ? Math.round((completed / lessons.length) * 100) : 0;
    return { completed, total: lessons.length, pct };
  };

  /** DEV-4: first not-yet-completed lesson — the target of «ادامه یادگیری». */
  const firstIncompleteLesson = (c: Course): Lesson | null =>
    lessonsOf(c).find((l) => !completedLessonIds.includes(l.id)) ?? null;

  const faNum = (n: number) => n.toLocaleString('fa-IR');

  const inProgressCourses = accessibleCourses.filter((c) => {
    const prog = progressFor(c);
    return prog.completed > 0 && prog.completed < prog.total;
  });

  // ------------------------------------------------------------------
  // DEV-5: bookmarks («نشان‌شده‌ها») — resolve ids to course/lesson pairs
  // ------------------------------------------------------------------
  const bookmarkedLessons: Array<{ course: Course; lesson: Lesson }> = useMemo(() => {
    const found: Array<{ course: Course; lesson: Lesson }> = [];
    for (const id of currentUser.bookmarkedLessonIds ?? []) {
      for (const c of allCourses) {
        const lesson = lessonsOf(c).find((l) => l.id === id);
        if (lesson) {
          found.push({ course: c, lesson });
          break;
        }
      }
    }
    return found;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [currentUser.bookmarkedLessonIds, allCourses]);

  return (
    <div className="max-w-4xl mx-auto px-4 py-6 space-y-6 pb-28">
      {/* User Header Profile */}
      <div className="bg-white rounded-3xl p-5 sm:p-6 border border-stone-200/90 shadow-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div className="flex items-center gap-4">
          <div className="w-16 h-16 rounded-2xl bg-gradient-to-br from-amber-700 to-amber-900 text-white flex items-center justify-center font-bold text-xl shadow-xs">
            {currentUser.fullName.slice(0, 1)}
          </div>
          <div>
            <div className="flex items-center gap-2">
              <h1 className="text-lg font-black text-amber-950">{currentUser.fullName}</h1>
              <span className="text-[11px] font-mono text-stone-500 bg-stone-100 px-2 py-0.5 rounded-md">
                شناسه: {currentUser.id.slice(0, 10)}
              </span>
            </div>
            <p className="text-xs text-stone-500 font-mono mt-0.5" dir="ltr">
              {currentUser.phoneNumber}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2 w-full sm:w-auto justify-end pt-2 sm:pt-0 border-t sm:border-t-0 border-stone-100">
          <button
            onClick={onLogout}
            className="flex items-center gap-1.5 text-xs text-red-700 hover:bg-red-50 border border-red-200 px-3.5 py-1.5 rounded-xl transition font-medium"
          >
            <LogOut className="w-3.5 h-3.5" />
            <span>خروج از حساب</span>
          </button>
        </div>
      </div>

      {/* Membership Status Card */}
      <div className="bg-gradient-to-br from-stone-900 to-amber-950 text-white rounded-3xl p-5 sm:p-6 border border-amber-900/40 shadow-lg relative overflow-hidden">
        <div className="absolute left-0 bottom-0 opacity-10 pointer-events-none p-4">
          <Crown className="w-48 h-48 text-amber-300" />
        </div>

        <div className="relative z-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
          <div className="space-y-2">
            <div className="flex items-center gap-2">
              <Crown className="w-5 h-5 text-amber-400" />
              <span className="text-xs text-amber-200 font-semibold">وضعیت عضویت و دسترسی ویدیوها:</span>
            </div>

            <div className="flex items-center gap-3">
              <h2 className="text-xl sm:text-2xl font-black text-white">
                {membership ? membership.planName : 'عضویت رایگان (مهمان)'}
              </h2>
              {isMembershipActive ? (
                <span className="bg-emerald-500 text-stone-950 text-xs font-bold px-2.5 py-0.5 rounded-full">
                  فعال
                </span>
              ) : (
                <span className="bg-amber-500/80 text-stone-950 text-xs font-bold px-2.5 py-0.5 rounded-full">
                  غیرفعال / منقضی
                </span>
              )}
            </div>

            {membership && isMembershipActive && (
              <div className="flex flex-wrap items-center gap-4 text-xs text-stone-300 pt-1">
                <span className="flex items-center gap-1.5">
                  <Calendar className="w-3.5 h-3.5 text-amber-400" />
                  اعتبار تا: {formatJalali(membership.expiresAt)}
                </span>
                <span className="text-amber-300 font-bold">
                  {/* BUG-48: daysUntil → number|null; null renders «—», never «۰ روز». */}
                  ({liveDaysRemaining ?? '—'} روز باقی‌مانده)
                </span>
              </div>
            )}
          </div>

          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full md:w-auto">
            <button
              onClick={onOpenMembership}
              className="bg-amber-500 hover:bg-amber-400 text-stone-950 font-bold px-4 py-2.5 rounded-xl text-xs transition shadow-md flex items-center justify-center gap-1.5"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              <span>{membership ? 'تمدید دستی یا ارتقای اشتراک' : 'خرید اشتراک دوره'}</span>
            </button>
          </div>
        </div>
      </div>

      {/* BUG-43d: explicit error state with retry instead of a silent blank view */}
      {loadError && (
        <AsyncState
          status="error"
          error="بارگذاری داشبورد با خطا مواجه شد؛ داده‌های شما سالم است، فقط نمایش به‌روز نشد."
          onRetry={loadData}
        />
      )}

      {/* BUG-43d: skeleton/loading block while first load is in flight — the old
          code flashed «تاکنون سفارشی ثبت نکرده‌اید» during the fetch. */}
      {!loadError && isLoading && (
        <div className="bg-white rounded-3xl p-5 sm:p-6 border border-stone-200/90 shadow-xs space-y-4">
          <div className="h-4 w-44 bg-stone-200 rounded animate-pulse" />
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="h-16 rounded-2xl bg-stone-100 animate-pulse" />
            ))}
          </div>
          <div className="h-4 w-52 bg-stone-200 rounded animate-pulse" />
          <div className="h-20 rounded-2xl bg-stone-100 animate-pulse" />
        </div>
      )}

      {/* DEV-4: «ادامه یادگیری» — in-progress courses with progress bars; wired
          to the previously-unused onPlayLesson prop (first incomplete lesson). */}
      {!loadError && !isLoading && inProgressCourses.length > 0 && (
        <div className="bg-white rounded-3xl p-5 sm:p-6 border border-stone-200/90 shadow-xs space-y-4">
          <div>
            <h3 className="font-bold text-sm sm:text-base text-amber-950 flex items-center gap-2">
              <Play className="w-4 h-4 text-amber-800" />
              ادامه یادگیری
            </h3>
            <p className="text-xs text-stone-500 mt-0.5">از همان‌جایی که رها کردید ادامه دهید</p>
          </div>

          <div className="space-y-3">
            {inProgressCourses.map((c) => {
              const prog = progressFor(c);
              const nextLesson = firstIncompleteLesson(c);
              return (
                <div
                  key={c.id}
                  className="p-3.5 rounded-2xl border border-stone-200 bg-[#fdfbf7]/60 space-y-2.5"
                >
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-3 min-w-0">
                      <img
                        src={c.thumbnailUrl}
                        alt={c.title}
                        className="w-10 h-10 rounded-xl object-cover shrink-0"
                      />
                      <div className="min-w-0">
                        <h4 className="font-bold text-xs text-stone-900 truncate">{c.title}</h4>
                        <p className="text-[11px] text-stone-500 mt-0.5">
                          {faNum(prog.completed)} از {faNum(prog.total)} درس تکمیل شده ({faNum(prog.pct)}٪)
                        </p>
                      </div>
                    </div>

                    <button
                      onClick={() => nextLesson && onPlayLesson(c, nextLesson)}
                      disabled={!nextLesson}
                      className="bg-amber-800 hover:bg-amber-900 disabled:opacity-50 text-white text-xs font-semibold px-3.5 py-2.5 rounded-xl transition shrink-0 flex items-center gap-1.5 min-h-11"
                    >
                      <Play className="w-3.5 h-3.5 fill-white" />
                      <span>ادامه یادگیری</span>
                    </button>
                  </div>

                  {/* DEV-4: per-course progress bar (completed/total lessons) */}
                  <div className="h-2 rounded-full bg-stone-200/80 overflow-hidden">
                    <div
                      className="h-full rounded-full bg-amber-700 transition-all"
                      style={{ width: `${prog.pct}%` }}
                    />
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* Accessible Courses */}
      {!loadError && !isLoading && (
      <div className="bg-white rounded-3xl p-5 sm:p-6 border border-stone-200/90 shadow-xs space-y-4">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="font-bold text-sm sm:text-base text-amber-950 flex items-center gap-2">
              <BookOpen className="w-4 h-4 text-amber-800" />
              دوره‌های در دسترس شما
            </h3>
            <p className="text-xs text-stone-500 mt-0.5">
              محتواهایی که مطابق اشتراک فعلی شما قفل آنها باز شده است
            </p>
          </div>
          <button
            onClick={() => onNavigate('catalogue')}
            className="text-xs text-amber-800 font-semibold hover:underline"
          >
            مشاهده کل کاتالوگ
          </button>
        </div>

        {accessibleCourses.length === 0 ? (
          <div className="p-8 text-center text-xs text-stone-500 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
            <BookOpen className="w-8 h-8 text-stone-400 mx-auto" />
            <p>در حال حاضر اشتراک فعالی برای دسترسی به دوره‌های قفل‌شده ندارید.</p>
            <button
              onClick={onOpenMembership}
              className="text-amber-800 font-bold hover:underline"
            >
              مشاهده پلن‌های عضویت
            </button>
          </div>
        ) : (
          <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
            {accessibleCourses.map((c) => {
              // DEV-4: per-course progress (completed/total) on every enrolled card
              const prog = progressFor(c);
              return (
              <div
                key={c.id}
                className="p-3.5 rounded-2xl border border-stone-200 hover:border-amber-700/60 transition space-y-2.5 bg-[#fdfbf7]/60"
              >
                <div className="flex items-center justify-between gap-3">
                  <div className="flex items-center gap-3 truncate">
                    <img
                      src={c.thumbnailUrl}
                      alt={c.title}
                      className="w-12 h-12 rounded-xl object-cover shrink-0"
                    />
                    <div className="truncate">
                      <h4 className="font-bold text-xs text-stone-900 truncate">{c.title}</h4>
                      <p className="text-[11px] text-stone-500 mt-0.5">استاد: {c.instructorName}</p>
                    </div>
                  </div>

                  <button
                    onClick={() => onNavigate('course_detail', { courseId: c.id })}
                    className="bg-amber-800 hover:bg-amber-900 text-white text-xs font-semibold px-3 py-1.5 rounded-xl transition shrink-0"
                  >
                    ورود
                  </button>
                </div>

                {/* DEV-4: compact progress bar per enrolled course */}
                <div className="flex items-center gap-2">
                  <div className="flex-1 h-1.5 rounded-full bg-stone-200/80 overflow-hidden">
                    <div
                      className="h-full rounded-full bg-emerald-600 transition-all"
                      style={{ width: `${prog.pct}%` }}
                    />
                  </div>
                  <span className="text-[10px] text-stone-500 font-mono shrink-0">
                    {faNum(prog.completed)}/{faNum(prog.total)} درس
                  </span>
                </div>
              </div>
              );
            })}
          </div>
        )}
      </div>
      )}

      {/* Orders and Payment History */}
      {!loadError && !isLoading && (
      <div className="bg-white rounded-3xl p-5 sm:p-6 border border-stone-200/90 shadow-xs space-y-4">
        <h3 className="font-bold text-sm sm:text-base text-amber-950 flex items-center gap-2">
          <CreditCard className="w-4 h-4 text-amber-800" />
          تاریخچه تراکنش‌ها و سفارش‌های شما
        </h3>

        {orders.length === 0 ? (
          <p className="text-center text-xs text-stone-500 py-6">
            تاکنون سفارشی در سیستم ثبت نکرده‌اید.
          </p>
        ) : (
          <div className="space-y-2.5">
            {orders.map((ord) => {
              const isCompleted = ord.status === 'completed';
              const isPending = ord.status === 'pending';
              const isRejected = ord.status === 'rejected' || ord.status === 'failed';
              // PHASE-3: وضعیت‌های جدید فاز ۳ — سفارش لغوشده/در حال بررسی بی‌برچسب نماند
              const isCancelled = ord.status === 'cancelled';
              const isProcessing = ord.status === 'verifying' || ord.status === 'processing';

              return (
                <div
                  key={ord.id}
                  className="p-3.5 rounded-2xl border border-stone-200 text-xs flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 bg-stone-50/60"
                >
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="font-bold text-stone-900">{ord.itemTitle}</span>
                      <span className="font-mono text-[10px] text-stone-500">
                        ({ord.orderNumber})
                      </span>
                    </div>

                    <div className="flex items-center gap-3 text-[11px] text-stone-500">
                      {/* LIVE-DATE FIX (BUG-23): render from the ISO timestamp; the
                          stored createdAtJalali is only a fallback for legacy records. */}
                      <span>{ord.createdAt ? formatJalali(ord.createdAt, true) : ord.createdAtJalali}</span>
                      <span>·</span>
                      <span>
                        روش: {ord.gateway === 'zibal' ? 'درگاه زیبال' : 'کارت به کارت'}
                      </span>
                      {ord.zibalRefNumber && (
                        <span>· پیگیری: {ord.zibalRefNumber}</span>
                      )}
                    </div>

                    {ord.manualTransferDetails?.adminReviewNote && (
                      <p className="text-[11px] text-amber-900 bg-amber-100/60 px-2 py-0.5 rounded-md mt-1 inline-block">
                        پیام پشتیبانی: {ord.manualTransferDetails.adminReviewNote}
                      </p>
                    )}
                  </div>

                  <div className="flex items-center gap-3 w-full sm:w-auto justify-between sm:justify-end pt-2 sm:pt-0 border-t sm:border-t-0 border-stone-200">
                    <span className="font-bold font-mono text-amber-900">
                      {formatPrice(ord.amountToman)} تومان
                    </span>

                    {isCompleted && (
                      <span className="bg-emerald-100 text-emerald-800 text-[10px] font-bold px-2.5 py-1 rounded-full flex items-center gap-1">
                        <CheckCircle className="w-3 h-3 text-emerald-600" />
                        تایید شده
                      </span>
                    )}

                    {isPending && (
                      <span className="bg-amber-100 text-amber-900 text-[10px] font-bold px-2.5 py-1 rounded-full flex items-center gap-1">
                        <Clock className="w-3 h-3 text-amber-700" />
                        در انتظار بررسی
                      </span>
                    )}

                    {isRejected && (
                      <span className="bg-red-100 text-red-800 text-[10px] font-bold px-2.5 py-1 rounded-full">
                        رد شده / ناموفق
                      </span>
                    )}

                    {isCancelled && (
                      <span className="bg-stone-200 text-stone-700 text-[10px] font-bold px-2.5 py-1 rounded-full">
                        لغو شده
                      </span>
                    )}

                    {isProcessing && (
                      <span className="bg-amber-50 text-amber-800 border border-amber-200 text-[10px] font-bold px-2.5 py-1 rounded-full">
                        در حال بررسی
                      </span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
      )}

      {/* DEV-5: Bookmarks («نشان‌شده‌ها») — lessons the student saved from the
          player (toggle itself is wired by agent 3-b inside LessonPlayerModal). */}
      {!loadError && !isLoading && (
      <div className="bg-white rounded-3xl p-5 sm:p-6 border border-stone-200/90 shadow-xs space-y-4">
        <div>
          <h3 className="font-bold text-sm sm:text-base text-amber-950 flex items-center gap-2">
            <Bookmark className="w-4 h-4 text-amber-800" />
            نشان‌شده‌ها
          </h3>
          <p className="text-xs text-stone-500 mt-0.5">درس‌هایی که برای مرور دوباره نشان کرده‌اید</p>
        </div>

        {bookmarkedLessons.length === 0 ? (
          <div className="p-8 text-center text-xs text-stone-500 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
            <Bookmark className="w-8 h-8 text-stone-400 mx-auto" />
            <p>هنوز درسی نشان نکرده‌اید؛ در پخش‌کننده هر درس، دکمه نشان کردن را بزنید.</p>
          </div>
        ) : (
          <div className="space-y-2.5">
            {bookmarkedLessons.map(({ course, lesson }) => (
              <div
                key={lesson.id}
                className="p-3.5 rounded-2xl border border-stone-200 text-xs flex items-center justify-between gap-3 bg-stone-50/60"
              >
                <div className="min-w-0">
                  <p className="font-bold text-stone-900 truncate">{lesson.title}</p>
                  <p className="text-[11px] text-stone-500 mt-0.5 truncate">
                    {course.title} · {faNum(lesson.durationMinutes)} دقیقه
                  </p>
                </div>

                <button
                  onClick={() => onPlayLesson(course, lesson)}
                  className="bg-amber-800 hover:bg-amber-900 text-white text-xs font-semibold px-3.5 py-2.5 rounded-xl transition shrink-0 flex items-center gap-1.5 min-h-11"
                >
                  <Play className="w-3.5 h-3.5 fill-white" />
                  <span>پخش درس</span>
                </button>
              </div>
            ))}
          </div>
        )}
      </div>
      )}
    </div>
  );
};
