/**
 * @file components/payment/ManualTransferModal.tsx
 * @description Manual bank transfer (کارت به کارت / پایا) submission modal
 */

import React, { useState } from 'react';
import { Landmark, Upload, CheckCircle, AlertCircle, Copy, Check, FileText } from 'lucide-react';
import { Modal } from '../common/Modal';
import { MOCK_COMPANY_BANK_INFO } from '../../data/mockData';
import { MembershipPlan, PaymentOrder, UserProfile } from '../../types';
import { transferService } from '../../services/transferService';

interface ManualTransferModalProps {
  isOpen: boolean;
  onClose: () => void;
  plan: MembershipPlan | null;
  currentUser: UserProfile | null;
  onSubmitted: (order: PaymentOrder) => void;
}

export const ManualTransferModal: React.FC<ManualTransferModalProps> = ({
  isOpen,
  onClose,
  plan,
  currentUser,
  onSubmitted,
}) => {
  const [trackingCode, setTrackingCode] = useState('');
  const [lastFour, setLastFour] = useState('');
  const [depositDate, setDepositDate] = useState('۱۴۰۵/۰۷/۲۰');
  const [receiptImageName, setReceiptImageName] = useState('fish-variz.jpg');
  const [notes, setNotes] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [copiedField, setCopiedField] = useState<string | null>(null);
  const [successOrder, setSuccessOrder] = useState<PaymentOrder | null>(null);
  const [errorMsg, setErrorMsg] = useState<string | null>(null);

  if (!isOpen || !plan || !currentUser) return null;

  const copyToClipboard = (text: string, field: string) => {
    navigator.clipboard?.writeText(text);
    setCopiedField(field);
    setTimeout(() => setCopiedField(null), 2000);
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMsg(null);

    if (!trackingCode.trim() || !lastFour.trim()) {
      setErrorMsg('لطفاً شماره پیگیری و ۴ رقم آخر کارت را وارد نمایید.');
      return;
    }

    setIsSubmitting(true);
    try {
      const order = await transferService.submitTransferReceipt(currentUser, plan, {
        trackingCode: trackingCode.trim(),
        sourceCardLastFour: lastFour.trim(),
        depositDateJalali: depositDate,
        receiptImageName,
        senderNotes: notes.trim(),
      });
      setSuccessOrder(order);
      setIsSubmitting(false);
    } catch (err: any) {
      setIsSubmitting(false);
      setErrorMsg(err.message || 'خطا در ثبت فیش واریزی');
    }
  };

  const handleFinish = () => {
    if (successOrder) {
      onSubmitted(successOrder);
    }
    onClose();
    setSuccessOrder(null);
  };

  const formatPrice = (p: number) => p.toLocaleString('fa-IR');

  return (
    <Modal
      isOpen={isOpen}
      onClose={successOrder ? handleFinish : onClose}
      title="پرداخت کارت به کارت و ثبت فیش واریزی"
      subtitle={`خرید ${plan.title} (${formatPrice(plan.priceToman)} تومان)`}
      maxWidth="md"
    >
      <div className="space-y-4 text-xs">
        {successOrder ? (
          <div className="space-y-4 text-center py-3 animate-in fade-in">
            <div className="w-14 h-14 bg-amber-100 text-amber-800 rounded-full flex items-center justify-center mx-auto">
              <CheckCircle className="w-8 h-8" />
            </div>
            <div>
              <h4 className="text-base font-bold text-amber-950">فیش واریزی با موفقیت ثبت شد</h4>
              <p className="text-stone-600 text-xs mt-1">
                شماره پیگیری سفارش شما: <strong className="font-mono text-amber-900">{successOrder.orderNumber}</strong>
              </p>
            </div>

            <div className="bg-amber-50 border border-amber-200/80 rounded-xl p-3 text-right text-stone-700 leading-relaxed space-y-1">
              <div className="flex items-center gap-2 font-bold text-amber-900">
                <AlertCircle className="w-4 h-4 text-amber-700 shrink-0" />
                <span>وضعیت: «در انتظار بررسی توسط پشتیبانی»</span>
              </div>
              <p className="text-stone-600 text-[11px]">
                اطلاعات فیش شما برای بخش مالی ارسال شد. به محض بررسی و تطبیق صورت‌حساب بانکی، اشتراک شما فعال خواهد شد (معمولاً ظرف ۱ الی ۴ ساعت کاری).
              </p>
            </div>

            <button
              onClick={handleFinish}
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-semibold py-2.5 rounded-xl text-xs transition shadow-xs"
            >
              مشاهده وضعیت در داشبورد
            </button>
          </div>
        ) : (
          <form onSubmit={handleSubmit} className="space-y-4">
            {/* Company Bank Card Box */}
            <div className="bg-stone-900 text-stone-100 rounded-2xl p-4 space-y-3 shadow-md border border-stone-800">
              <div className="flex items-center justify-between text-[11px] text-stone-400">
                <span className="flex items-center gap-1.5 font-semibold text-amber-300">
                  <Landmark className="w-4 h-4 text-amber-400" />
                  حساب رسمی مستر دستباف
                </span>
                <span>{MOCK_COMPANY_BANK_INFO.bankName}</span>
              </div>

              {/* Card number big display */}
              <div className="bg-stone-800/80 p-2.5 rounded-xl flex items-center justify-between">
                <div>
                  <p className="text-[10px] text-stone-400">شماره کارت مقصد:</p>
                  <p className="text-sm sm:text-base font-mono font-bold tracking-wider text-amber-200 mt-0.5">
                    {MOCK_COMPANY_BANK_INFO.cardNumber}
                  </p>
                </div>
                <button
                  type="button"
                  onClick={() => copyToClipboard(MOCK_COMPANY_BANK_INFO.cardNumber, 'card')}
                  className="p-1.5 bg-stone-700 hover:bg-stone-600 rounded-lg text-stone-200 text-[10px] flex items-center gap-1 transition"
                >
                  {copiedField === 'card' ? <Check className="w-3.5 h-3.5 text-emerald-400" /> : <Copy className="w-3.5 h-3.5" />}
                  <span>کپی</span>
                </button>
              </div>

              {/* Account info */}
              <div className="flex items-center justify-between text-[11px] text-stone-300">
                <span>صاحب حساب: <strong>{MOCK_COMPANY_BANK_INFO.accountHolderName}</strong></span>
                <span className="font-mono text-stone-400">شبا: {MOCK_COMPANY_BANK_INFO.shabaNumber.slice(0, 10)}...</span>
              </div>
            </div>

            {errorMsg && (
              <div className="bg-red-50 border border-red-200 text-red-700 text-xs p-3 rounded-xl">
                {errorMsg}
              </div>
            )}

            {/* Inputs */}
            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block font-semibold text-stone-700 mb-1">
                  شماره پیگیری یا ارجاع بانکی *
                </label>
                <input
                  type="text"
                  dir="ltr"
                  value={trackingCode}
                  onChange={(e) => setTrackingCode(e.target.value)}
                  placeholder="مثال: 98124012"
                  className="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl font-mono text-xs focus:outline-none focus:ring-2 focus:ring-amber-700/30"
                  required
                />
              </div>

              <div>
                <label className="block font-semibold text-stone-700 mb-1">
                  ۴ رقم آخر کارت واریزکننده *
                </label>
                <input
                  type="text"
                  dir="ltr"
                  maxLength={4}
                  value={lastFour}
                  onChange={(e) => setLastFour(e.target.value)}
                  placeholder="مثال: 4589"
                  className="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl font-mono text-xs focus:outline-none focus:ring-2 focus:ring-amber-700/30"
                  required
                />
              </div>
            </div>

            <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div>
                <label className="block font-semibold text-stone-700 mb-1">
                  تاریخ واریز (شمسی)
                </label>
                <input
                  type="text"
                  value={depositDate}
                  onChange={(e) => setDepositDate(e.target.value)}
                  className="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-amber-700/30"
                />
              </div>

              <div>
                <label className="block font-semibold text-stone-700 mb-1">
                  فایل تصویر فیش (نمادین)
                </label>
                <div className="flex items-center gap-2 px-3 py-1.5 bg-stone-50 border border-stone-200 rounded-xl text-stone-600">
                  <Upload className="w-4 h-4 text-stone-400 shrink-0" />
                  <span className="truncate font-mono text-[11px]">{receiptImageName}</span>
                </div>
              </div>
            </div>

            <div>
              <label className="block font-semibold text-stone-700 mb-1">
                توضیحات و نام واریزکننده (اختیاری)
              </label>
              <textarea
                rows={2}
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="توضیحاتی مانند نام بانک مبدا یا نام درج‌شده در فیش..."
                className="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-xs focus:outline-none focus:ring-2 focus:ring-amber-700/30"
              />
            </div>

            {/* Warning rule */}
            <p className="text-[11px] text-stone-500 leading-relaxed">
              ⚠️ <strong>قانون بررسی:</strong> فیش‌های بانکی بلافاصله فعال نمی‌شوند. پس از تایید مدیر، اشتراک به شکل خودکار روی شماره همراه شما فعال خواهد شد.
            </p>

            <button
              type="submit"
              disabled={isSubmitting}
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-bold py-3 rounded-xl text-xs transition shadow-xs flex items-center justify-center gap-2"
            >
              {isSubmitting ? 'در حال ثبت فیش...' : 'ثبت فیش و ارسال جهت بررسی'}
            </button>
          </form>
        )}
      </div>
    </Modal>
  );
};
