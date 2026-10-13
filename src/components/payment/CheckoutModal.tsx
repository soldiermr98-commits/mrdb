/**
 * @file components/payment/CheckoutModal.tsx
 * @description Membership checkout modal with payment gateway selection
 */

import React, { useState } from 'react';
import { CreditCard, Landmark, CheckCircle2, ShieldCheck, AlertCircle, ArrowLeft } from 'lucide-react';
import { Modal } from '../common/Modal';
import { MembershipPlan, PaymentGatewayType, UserProfile } from '../../types';

interface CheckoutModalProps {
  isOpen: boolean;
  onClose: () => void;
  plan: MembershipPlan | null;
  currentUser: UserProfile | null;
  onSelectGateway: (gateway: PaymentGatewayType, plan: MembershipPlan) => void;
  onOpenLogin: () => void;
}

export const CheckoutModal: React.FC<CheckoutModalProps> = ({
  isOpen,
  onClose,
  plan,
  currentUser,
  onSelectGateway,
  onOpenLogin,
}) => {
  const [selectedGateway, setSelectedGateway] = useState<PaymentGatewayType>('zibal');

  if (!isOpen || !plan) return null;

  const formatPrice = (price: number) => {
    return price.toLocaleString('fa-IR');
  };

  const handleProceed = () => {
    if (!currentUser) {
      onOpenLogin();
      return;
    }
    onSelectGateway(selectedGateway, plan);
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="ثبت سفارش و خرید اشتراک"
      subtitle={`پلن انتخابی: ${plan.title}`}
      maxWidth="md"
    >
      <div className="space-y-4 text-xs">
        {/* Order Summary Card */}
        <div className="bg-stone-50 border border-stone-200 rounded-xl p-3.5 space-y-2">
          <div className="flex items-center justify-between">
            <span className="font-bold text-amber-950 text-sm">{plan.title}</span>
            <span className="bg-amber-100 text-amber-900 px-2.5 py-0.5 rounded-full font-semibold">
              مدت: {plan.durationLabel}
            </span>
          </div>
          <p className="text-stone-600 text-xs leading-relaxed">{plan.description}</p>
          <div className="pt-2 border-t border-stone-200 flex items-center justify-between">
            <span className="text-stone-500 font-medium">مبلغ قابل پرداخت:</span>
            <div className="flex items-baseline gap-1">
              <span className="text-base font-black text-amber-800 font-mono">
                {formatPrice(plan.priceToman)}
              </span>
              <span className="text-[11px] text-stone-500">تومان</span>
            </div>
          </div>
        </div>

        {/* Manual Renewal Notice */}
        <div className="bg-amber-50/70 border border-amber-200/80 rounded-xl p-3 flex items-start gap-2.5 text-stone-700">
          <AlertCircle className="w-4 h-4 text-amber-700 shrink-0 mt-0.5" />
          <div className="leading-relaxed">
            <strong className="text-amber-900">شیوه تمدید دستی:</strong>
            <p className="mt-0.5 text-stone-600">
              این عضویت به صورت دستی و با اراده شما تمدید می‌شود. هیچ‌گونه کسر دوره‌ای یا خودکار از حساب بانکی شما صورت نخواهد گرفت.
            </p>
          </div>
        </div>

        {/* Payment Gateway Options */}
        <div>
          <label className="block font-semibold text-stone-800 mb-2">انتخاب شیوه پرداخت:</label>
          <div className="space-y-2">
            {/* 1. Zibal Gateway */}
            <label
              className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition ${
                selectedGateway === 'zibal'
                  ? 'border-amber-700 bg-amber-50/40 ring-1 ring-amber-700/20'
                  : 'border-stone-200 hover:border-stone-300 bg-white'
              }`}
            >
              <input
                type="radio"
                name="gateway"
                checked={selectedGateway === 'zibal'}
                onChange={() => setSelectedGateway('zibal')}
                className="mt-1 text-amber-700 focus:ring-amber-700"
              />
              <div className="flex-1">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-stone-900 flex items-center gap-1.5">
                    <CreditCard className="w-4 h-4 text-amber-700" />
                    درگاه پرداخت آنلاین زیبال (Zibal)
                  </span>
                  <span className="text-[10px] text-emerald-700 font-semibold bg-emerald-50 px-2 py-0.5 rounded-full">
                    فعال‌سازی آنی
                  </span>
                </div>
                <p className="text-stone-500 text-[11px] mt-0.5">
                  پرداخت با کلیه کارت‌های عضو شبکه شتاب (در این پروتوتایپ درگاه آزمایشی فعال است)
                </p>
              </div>
            </label>

            {/* 2. Manual Card to Card */}
            <label
              className={`flex items-start gap-3 p-3 rounded-xl border cursor-pointer transition ${
                selectedGateway === 'card_to_card'
                  ? 'border-amber-700 bg-amber-50/40 ring-1 ring-amber-700/20'
                  : 'border-stone-200 hover:border-stone-300 bg-white'
              }`}
            >
              <input
                type="radio"
                name="gateway"
                checked={selectedGateway === 'card_to_card'}
                onChange={() => setSelectedGateway('card_to_card')}
                className="mt-1 text-amber-700 focus:ring-amber-700"
              />
              <div className="flex-1">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-stone-900 flex items-center gap-1.5">
                    <Landmark className="w-4 h-4 text-stone-700" />
                    کارت به کارت / حواله پایا (واریز دستی)
                  </span>
                  <span className="text-[10px] text-amber-800 font-medium bg-amber-100/60 px-2 py-0.5 rounded-full">
                    بررسی توسط ادمین
                  </span>
                </div>
                <p className="text-stone-500 text-[11px] mt-0.5">
                  واریز به شماره کارت مستر دستباف و ثبت شماره پیگیری و تصویر فیش واریزی
                </p>
              </div>
            </label>
          </div>
        </div>

        {/* User state warning */}
        {!currentUser && (
          <div className="bg-stone-100 p-2.5 rounded-xl text-stone-600 text-[11px] flex items-center justify-between">
            <span>برای ثبت سفارش ابتدا باید شماره موبایل خود را تایید کنید.</span>
            <button
              onClick={onOpenLogin}
              className="text-amber-800 font-bold hover:underline"
            >
              ورود سریع
            </button>
          </div>
        )}

        {/* CTA Button */}
        <div className="pt-2">
          <button
            onClick={handleProceed}
            className="w-full bg-amber-800 hover:bg-amber-900 text-white font-bold py-3 rounded-xl text-xs transition shadow-sm flex items-center justify-center gap-2"
          >
            <span>
              {currentUser
                ? selectedGateway === 'zibal'
                  ? 'انتقال به درگاه آزمایشی زیبال'
                  : 'ادامه و ثبت فیش کارت به کارت'
                : 'ورود به حساب و ادامه خرید'}
            </span>
            <ArrowLeft className="w-4 h-4" />
          </button>
        </div>
      </div>
    </Modal>
  );
};
