/**
 * @file views/MembershipView.tsx
 * @description Configurable membership plans view with manual renewal transparency and tier matrix
 */

import React, { useState, useEffect } from 'react';
import { Crown, CheckCircle, ShieldCheck, HelpCircle, ArrowLeft, AlertCircle, RefreshCw } from 'lucide-react';
import { MembershipPlan, UserProfile } from '../types';
import { membershipService } from '../services/membershipService';
import { formatJalali, daysUntil } from '../utils/format';

interface MembershipViewProps {
  onSelectPlan: (plan: MembershipPlan) => void;
  currentUser: UserProfile | null;
  onNavigate: (view: string) => void;
}

export const MembershipView: React.FC<MembershipViewProps> = ({
  onSelectPlan,
  currentUser,
  onNavigate,
}) => {
  const [plans, setPlans] = useState<MembershipPlan[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    membershipService.getActivePlans().then((res) => {
      setPlans(res);
      setIsLoading(false);
    });
  }, []);

  const formatPrice = (p: number) => p.toLocaleString('fa-IR');

  return (
    <div className="max-w-5xl mx-auto px-4 py-6 space-y-10 pb-28">
      {/* Page Header */}
      <div className="text-center max-w-2xl mx-auto space-y-3">
        <div className="inline-flex items-center gap-2 bg-amber-100 text-amber-900 px-3 py-1 rounded-full text-xs font-semibold">
          <Crown className="w-3.5 h-3.5 text-amber-700" />
          <span>پلن‌های عضویت و دسترسی به ویدیوها</span>
        </div>
        <h1 className="text-2xl sm:text-3xl font-black text-amber-950">
          سرمایه‌گذاری روی هنر دست و مهارت فردی
        </h1>
        <p className="text-xs sm:text-sm text-stone-600 leading-relaxed">
          با انتخاب یکی از پلن‌های زیر، دسترسی نامحدود به درس‌های ویدیویی، فایل‌های نقشه‌ها و پشتیبانی اساتید را دریافت کنید.
        </p>
      </div>

      {/* Manual Renewal Transparency Banner */}
      <div className="bg-amber-50/80 border border-amber-300/80 rounded-2xl p-4 flex flex-col sm:flex-row items-center justify-between gap-3 shadow-xs">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-xl bg-amber-800 text-white flex items-center justify-center shrink-0">
            <RefreshCw className="w-5 h-5" />
          </div>
          <div>
            <h3 className="font-bold text-xs sm:text-sm text-amber-950">
              قانون تمدید دستی مستر دستباف (بدون تمدید خودکار)
            </h3>
            <p className="text-[11px] sm:text-xs text-stone-600 mt-0.5 leading-relaxed">
              تمدید عضویت کاملاً وابسته به اراده شماست. هیچ وجهی پس از پایان دوره به صورت خودکار از کارت شما برداشت نخواهد شد.
            </p>
          </div>
        </div>
        <button
          onClick={() => onNavigate('editorial')}
          className="text-xs font-semibold text-amber-900 underline whitespace-nowrap"
        >
          مطالعه راهنما
        </button>
      </div>

      {/* Active User Status Banner if already subscribed */}
      {currentUser?.membership && (
        <div className="bg-white border border-stone-200 rounded-2xl p-4 flex items-center justify-between shadow-xs">
          <div className="flex items-center gap-3">
            {/* LIVE STATUS FIX (BUG-18): expired memberships no longer show a
                pulsing green dot and «فعال» — they show an amber expired state. */}
            {membershipService.isMembershipLive(currentUser) ? (
              <span className="w-3 h-3 rounded-full bg-emerald-500 animate-pulse" />
            ) : (
              <span className="w-3 h-3 rounded-full bg-amber-500" />
            )}
            <div>
              <p className="font-bold text-xs text-stone-900">
                اشتراک فعلی شما: <span className="text-amber-800">{currentUser.membership.planName}</span>
              </p>
              {membershipService.isMembershipLive(currentUser) ? (
                <p className="text-[11px] text-stone-500">
                  تاریخ انقضا: {formatJalali(currentUser.membership.expiresAt)} ({daysUntil(currentUser.membership.expiresAt)} روز باقی‌مانده)
                </p>
              ) : (
                <p className="text-[11px] text-amber-700 font-semibold">
                  این اشتراک منقضی شده است — برای دسترسی مجدد تمدید کنید.
                </p>
              )}
            </div>
          </div>
          <button
            onClick={() => onNavigate('dashboard')}
            className="text-xs text-stone-700 bg-stone-100 hover:bg-stone-200 px-3 py-1.5 rounded-xl transition font-medium"
          >
            مشاهده داشبورد
          </button>
        </div>
      )}

      {/* Plans Grid */}
      {isLoading ? (
        <div className="py-12 text-center text-xs text-stone-500">در حال بارگذاری پلن‌ها...</div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
          {plans.map((plan) => {
            const isFree = plan.priceToman === 0;
            const isUserCurrentTier = currentUser?.membership?.tier === plan.tier;

            return (
              <div
                key={plan.id}
                className={`bg-white rounded-3xl p-5 border flex flex-col justify-between transition duration-200 ${
                  plan.isPopular
                    ? 'border-amber-600 shadow-lg ring-1 ring-amber-600/30'
                    : 'border-stone-200 hover:border-stone-300 shadow-xs'
                }`}
              >
                <div>
                  {plan.isPopular && (
                    <div className="bg-amber-700 text-white text-[10px] font-bold px-2.5 py-0.5 rounded-full inline-block mb-3">
                      محبوب‌ترین انتخاب هنرجویان
                    </div>
                  )}

                  <div className="flex items-center justify-between">
                    <h3 className="font-bold text-base text-amber-950">{plan.title}</h3>
                    <span className="text-xs text-stone-500 font-medium bg-stone-100 px-2 py-0.5 rounded-md">
                      {plan.durationLabel}
                    </span>
                  </div>

                  <p className="text-xs text-stone-500 mt-2 min-h-10 leading-relaxed">
                    {plan.description}
                  </p>

                  {/* Price */}
                  <div className="my-4 py-3 border-y border-stone-100">
                    {plan.originalPriceToman && (
                      <div className="text-[11px] text-stone-400 line-through font-mono">
                        {formatPrice(plan.originalPriceToman)} تومان
                      </div>
                    )}
                    <div className="flex items-baseline gap-1 mt-0.5">
                      <span className="text-xl sm:text-2xl font-black text-amber-900 font-mono">
                        {isFree ? 'رایگان' : formatPrice(plan.priceToman)}
                      </span>
                      {!isFree && <span className="text-xs text-stone-500">تومان</span>}
                    </div>
                  </div>

                  {/* Feature Checklist */}
                  <div className="space-y-2 text-xs text-stone-700">
                    <p className="font-semibold text-[11px] text-stone-500">امکانات دسترسی:</p>
                    {plan.features.map((feat, idx) => (
                      <div key={idx} className="flex items-start gap-2">
                        <CheckCircle className="w-3.5 h-3.5 text-amber-700 shrink-0 mt-0.5" />
                        <span className="leading-snug">{feat}</span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Purchase Button */}
                <div className="pt-6">
                  <button
                    onClick={() => onSelectPlan(plan)}
                    className={`w-full py-2.5 rounded-xl text-xs font-bold transition shadow-xs ${
                      plan.isPopular
                        ? 'bg-amber-800 hover:bg-amber-900 text-white'
                        : 'bg-stone-900 hover:bg-amber-900 text-white'
                    }`}
                  >
                    {isFree
                      ? 'عضویت رایگان'
                      : isUserCurrentTier
                      ? 'تمدید مجدد دستی'
                      : 'خرید و ارتقای عضویت'}
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Comparison Matrix Explanatory Table */}
      <div className="bg-white rounded-3xl border border-stone-200/90 p-6 shadow-xs space-y-4">
        <h3 className="font-bold text-base text-amber-950 flex items-center gap-2">
          <ShieldCheck className="w-5 h-5 text-amber-800" />
          جدول مقایسه دسترسی به دوره‌ها در پلن‌های مختلف
        </h3>

        <div className="overflow-x-auto">
          <table className="w-full text-xs text-right">
            <thead>
              <tr className="border-b border-stone-200 text-stone-500">
                <th className="py-2.5 font-semibold">ویژگی یا سطح دوره</th>
                <th className="py-2.5 font-semibold text-center">رایگان</th>
                <th className="py-2.5 font-semibold text-center">برنزی</th>
                <th className="py-2.5 font-semibold text-center">نقره‌ای</th>
                <th className="py-2.5 font-semibold text-center text-amber-900">طلایی</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-stone-100 text-stone-700">
              <tr>
                <td className="py-2.5">درس‌های پیش‌نمایش اولیه</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
              </tr>
              <tr>
                <td className="py-2.5">دوره‌های سطح مقدماتی (قالی‌بافی و مکرمه)</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
              </tr>
              <tr>
                <td className="py-2.5">دوره‌های متوسط (گلیم قشقایی و پته کرمان)</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
              </tr>
              <tr>
                <td className="py-2.5">دوره‌های پیشرفته (رنگرزی گیاهی و طراحی نقشه)</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
              </tr>
              <tr>
                <td className="py-2.5">ارسال بسته هدیه الیاف و پشم طبیعی به منزل</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-stone-300">-</td>
                <td className="py-2.5 text-center text-emerald-600 font-bold">✓</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};
