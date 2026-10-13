/**
 * @file views/UserDashboardView.tsx
 * @description User student dashboard with membership status, learning progress, and order history
 */

import React, { useState, useEffect } from 'react';
import {
  User,
  Crown,
  BookOpen,
  Clock,
  CheckCircle,
  FileText,
  CreditCard,
  Landmark,
  LogOut,
  Play,
  Bookmark,
  Calendar,
  AlertTriangle,
  RefreshCw,
  Sparkles,
} from 'lucide-react';
import { Course, PaymentOrder, UserProfile } from '../types';
import { paymentService } from '../services/paymentService';
import { courseService } from '../services/courseService';
import { membershipService } from '../services/membershipService';
import { formatJalali, daysUntil, formatToman } from '../utils/format';

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
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    Promise.all([
      paymentService.getUserOrders(currentUser.id),
      courseService.getCourses(),
    ]).then(([ords, allCourses]) => {
      setOrders(ords);

      // Filter courses where user can access lessons
      // ACCESS GATE FIX (BUG-18): mirrors courseService.canAccessLesson — the
      // previous filter only checked membership.status, so an EXPIRED membership
      // (status still 'active' in localStorage) listed all tier courses here.
      const membershipIsLive = membershipService.isMembershipLive(currentUser);
      const accessible = allCourses.filter((c) => {
        if (currentUser.role === 'admin') return true;
        if (!membershipIsLive) return false;
        if (currentUser.membership!.tier === 'gold') return true;
        if (currentUser.membership!.tier === 'silver') return c.requiredTier !== 'gold';
        if (currentUser.membership!.tier === 'bronze') return c.requiredTier === 'bronze';
        return false;
      });

      setAccessibleCourses(accessible);
      setIsLoading(false);
    });
  }, [currentUser]);

  const membership = currentUser.membership;
  // LIVE STATUS FIX (BUG-18/BUG-24): «فعال» فقط وقتی status=active و انقضا در آینده باشد؛
  // و روز باقی‌مانده به‌جای مقدار منجمدِ زمان خرید، زنده محاسبه می‌شود.
  const isMembershipActive = membershipService.isMembershipLive(currentUser);
  const liveDaysRemaining = membership ? daysUntil(membership.expiresAt) : 0;

  const formatPrice = (p: number) => formatToman(p);

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
                  ({liveDaysRemaining} روز باقی‌مانده)
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

      {/* Accessible Courses */}
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
            {accessibleCourses.map((c) => (
              <div
                key={c.id}
                className="p-3.5 rounded-2xl border border-stone-200 hover:border-amber-700/60 transition flex items-center justify-between gap-3 bg-[#fdfbf7]/60"
              >
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
            ))}
          </div>
        )}
      </div>

      {/* Orders and Payment History */}
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
                  </div>
                </div>
              );
            })}
          </div>
        )}
      </div>
    </div>
  );
};
