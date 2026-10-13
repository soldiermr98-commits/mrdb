/**
 * @file components/payment/ZibalSimulatorModal.tsx
 * @description Mock simulator for Zibal IPG with test triggers and server-side verification explanation
 */

import React, { useState } from 'react';
import { ShieldAlert, CheckCircle, XCircle, RefreshCw, ArrowRight, ShieldCheck, Lock } from 'lucide-react';
import { Modal } from '../common/Modal';
import { PaymentOrder } from '../../types';
import { paymentService } from '../../services/paymentService';

interface ZibalSimulatorModalProps {
  isOpen: boolean;
  onClose: () => void;
  order: PaymentOrder | null;
  onPaymentComplete: (order: PaymentOrder, success: boolean) => void;
  /** BUG-65: fired when the gateway is abandoned while still idle (X / backdrop /
   * Esc) — no outcome was chosen yet, so App can park the order accordingly. */
  onAbandon?: () => void;
}

export const ZibalSimulatorModal: React.FC<ZibalSimulatorModalProps> = ({
  isOpen,
  onClose,
  order,
  onPaymentComplete,
  onAbandon,
}) => {
  const [isVerifying, setIsVerifying] = useState(false);
  const [outcomeState, setOutcomeState] = useState<'idle' | 'success' | 'failed' | 'cancelled'>('idle');
  const [feedbackMessage, setFeedbackMessage] = useState<string | null>(null);
  const [serverAuditLog, setServerAuditLog] = useState<string | null>(null);

  if (!isOpen || !order) return null;

  const handleSimulatePayment = async (outcome: 'success' | 'bank_error' | 'user_cancelled') => {
    setIsVerifying(true);
    setFeedbackMessage(null);
    setServerAuditLog(null);

    try {
      const res = await paymentService.verifyZibalPayment(order.id, outcome);
      setIsVerifying(false);

      if (res.success) {
        setOutcomeState('success');
        setFeedbackMessage(res.message);
        setServerAuditLog(res.serverLogExplanation);
      } else {
        setOutcomeState(outcome === 'user_cancelled' ? 'cancelled' : 'failed');
        setFeedbackMessage(res.message);
        setServerAuditLog(res.serverLogExplanation);
      }
    } catch (err: any) {
      setIsVerifying(false);
      setOutcomeState('failed');
      setFeedbackMessage(err.message || 'خطای غیرمنتظره در تایید سروری تراکنش');
    }
  };

  const handleFinish = () => {
    onPaymentComplete(order, outcomeState === 'success');
    onClose();
    setOutcomeState('idle');
  };

  // BUG-65: close path while the simulator is still idle (X button, backdrop
  // click, Esc → Modal onClose) — the user abandoned the gateway without
  // choosing an outcome. onAbandon is NOT called once an outcome was chosen
  // (success/failed/cancelled flows report through onPaymentComplete).
  const handleClose = () => {
    if (outcomeState === 'idle') {
      onAbandon?.();
    }
    onClose();
  };

  const formatPrice = (price: number) => {
    return price.toLocaleString('fa-IR');
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={outcomeState === 'idle' ? handleClose : handleFinish}
      title="درگاه آزمایشی پرداخت زیبال (Zibal Simulator)"
      subtitle="محیط تست تراکنش‌های مالی - بدون برداشت وجه واقعی"
      maxWidth="md"
    >
      <div className="space-y-4 text-xs">
        {/* Prominent Mock Badge */}
        <div className="bg-amber-500 text-stone-950 p-2.5 rounded-xl font-bold flex items-center justify-between text-xs shadow-xs">
          <div className="flex items-center gap-2">
            <ShieldAlert className="w-4 h-4 shrink-0 text-stone-900" />
            <span>درگاه آزمایشی / بدون پرداخت واقعی وجه</span>
          </div>
          <span className="font-mono text-[10px] bg-stone-900 text-amber-300 px-2 py-0.5 rounded">
            SANDBOX
          </span>
        </div>

        {/* Transaction Summary Box */}
        <div className="bg-stone-50 border border-stone-200 rounded-xl p-3.5 space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-stone-500">شماره سفارش مستر دستباف:</span>
            <span className="font-mono font-bold text-stone-800">{order.orderNumber}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-stone-500">شناسه پیگیری زیبال (TrackId):</span>
            <span className="font-mono text-stone-800">{order.zibalTrackId || 'TRK-ZIBAL-MOCK'}</span>
          </div>
          <div className="flex items-center justify-between">
            <span className="text-stone-500">پذیرنده:</span>
            <span className="font-semibold text-stone-800">مستر دستباف (mrdastbaaf.com)</span>
          </div>
          <div className="pt-2 border-t border-stone-200 flex items-center justify-between">
            <span className="text-stone-700 font-bold">مبلغ تراکنش:</span>
            <span className="text-sm font-black text-amber-800 font-mono">
              {formatPrice(order.amountToman)} تومان
            </span>
          </div>
        </div>

        {/* State: Idle / Ready to simulate */}
        {outcomeState === 'idle' && (
          <div className="space-y-3">
            <p className="text-stone-600 leading-relaxed text-[11px]">
              برای سنجش رفتار کامل سیستم و منطق سمت سرور، یکی از سناریوهای آزمایشی زیر را انتخاب کنید:
            </p>

            <div className="space-y-2">
              <button
                disabled={isVerifying}
                onClick={() => handleSimulatePayment('success')}
                className="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-semibold py-2.5 px-3 rounded-xl transition flex items-center justify-between shadow-xs disabled:opacity-50"
              >
                <div className="flex items-center gap-2">
                  <CheckCircle className="w-4 h-4 text-emerald-200" />
                  <span>۱. پرداخت موفقیت‌آمیز (شبیه‌سازی تراکنش معتبر)</span>
                </div>
                <span className="text-[10px] text-emerald-200">تایید در سرور + فعال‌سازی اشتراک</span>
              </button>

              <button
                disabled={isVerifying}
                onClick={() => handleSimulatePayment('bank_error')}
                className="w-full bg-stone-100 hover:bg-stone-200 border border-stone-300 text-stone-700 font-medium py-2.5 px-3 rounded-xl transition flex items-center justify-between disabled:opacity-50"
              >
                <div className="flex items-center gap-2">
                  <XCircle className="w-4 h-4 text-red-600" />
                  <span>۲. شبیه‌سازی خطای شاپرک (رمز اشتباه / موجودی ناکافی)</span>
                </div>
                <span className="text-[10px] text-red-600 font-medium">عدم فعال‌سازی</span>
              </button>

              <button
                disabled={isVerifying}
                onClick={() => handleSimulatePayment('user_cancelled')}
                className="w-full bg-stone-100 hover:bg-stone-200 border border-stone-300 text-stone-700 font-medium py-2.5 px-3 rounded-xl transition flex items-center justify-between disabled:opacity-50"
              >
                <div className="flex items-center gap-2">
                  <XCircle className="w-4 h-4 text-stone-500" />
                  <span>۳. انصراف کاربر در صفحه درگاه</span>
                </div>
                <span className="text-[10px] text-stone-500">لغو سفارش</span>
              </button>
            </div>

            {isVerifying && (
              <div className="flex items-center justify-center gap-2 py-3 text-amber-800">
                <RefreshCw className="w-4 h-4 animate-spin" />
                <span>در حال تایید دوطرفه با سرور زیبال (Server Verify Call)...</span>
              </div>
            )}
          </div>
        )}

        {/* State: Outcome Result Display */}
        {outcomeState !== 'idle' && (
          <div className="space-y-3 animate-in fade-in">
            {outcomeState === 'success' ? (
              <div className="bg-emerald-50 border border-emerald-200 rounded-xl p-4 text-center space-y-2">
                <div className="w-12 h-12 bg-emerald-100 text-emerald-700 rounded-full flex items-center justify-center mx-auto">
                  <CheckCircle className="w-6 h-6" />
                </div>
                <h4 className="font-bold text-emerald-900 text-sm">پرداخت با موفقیت انجام شد!</h4>
                <p className="text-stone-600 text-xs">{feedbackMessage}</p>
                {order.zibalRefNumber && (
                  <p className="text-xs font-mono text-emerald-800">
                    شماره مرجع شاپرک: <strong>{order.zibalRefNumber}</strong>
                  </p>
                )}
              </div>
            ) : (
              <div className="bg-red-50 border border-red-200 rounded-xl p-4 text-center space-y-2">
                <div className="w-12 h-12 bg-red-100 text-red-700 rounded-full flex items-center justify-center mx-auto">
                  <XCircle className="w-6 h-6" />
                </div>
                <h4 className="font-bold text-red-900 text-sm">
                  {outcomeState === 'cancelled' ? 'انصراف از پرداخت' : 'تراکنش ناموفق'}
                </h4>
                <p className="text-stone-600 text-xs">{feedbackMessage}</p>
              </div>
            )}

            {/* Server Audit Log Explanation Box */}
            {serverAuditLog && (
              <div className="bg-stone-900 text-stone-200 p-3 rounded-xl font-mono text-[11px] leading-relaxed border border-stone-800">
                <div className="flex items-center gap-1.5 text-amber-400 font-bold mb-1">
                  <Lock className="w-3.5 h-3.5" />
                  <span>لاگ امنیتی تایید سروری (Production Rule Verification):</span>
                </div>
                <p>{serverAuditLog}</p>
              </div>
            )}

            <button
              onClick={handleFinish}
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-semibold py-2.5 rounded-xl text-xs transition shadow-xs"
            >
              {outcomeState === 'success' ? 'مشاهده داشبورد و دسترسی‌ها' : 'بستن و تلاش مجدد'}
            </button>
          </div>
        )}

        {/* Production Architectural Warning */}
        <div className="pt-2 border-t border-stone-200 text-[11px] text-stone-500 leading-relaxed">
          <strong>الزام امنیتی زیبال در نسخه نهایی:</strong> فعال‌سازی اشتراک تنها پس از دریافت موفق پاسخ از متد <code className="bg-stone-100 px-1 rounded text-stone-800">https://gateway.zibal.ir/v1/verify</code> در بک‌اند وردپرس انجام خواهد شد. بازگشت ساده مرورگر بدون استعلام سروری در سطح تولید نامعتبر است.
        </div>
      </div>
    </Modal>
  );
};
