/**
 * @file components/auth/PhoneLoginModal.tsx
 * @description Phone login modal simulating WordPress & Otino SMS OTP authentication
 */

import React, { useState, useEffect } from 'react';
import { Smartphone, KeyRound, ArrowRight, ShieldAlert, Sparkles, Check, RefreshCw } from 'lucide-react';
import { Modal } from '../common/Modal';
import { authService } from '../../services/authService';
import { UserProfile } from '../../types';

interface PhoneLoginModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: (user: UserProfile) => void;
}

export const PhoneLoginModal: React.FC<PhoneLoginModalProps> = ({
  isOpen,
  onClose,
  onSuccess,
}) => {
  const [step, setStep] = useState<'phone' | 'otp'>('phone');
  const [phone, setPhone] = useState('09123456789');
  const [otp, setOtp] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [timer, setTimer] = useState(90);

  useEffect(() => {
    let interval: any;
    if (step === 'otp' && timer > 0) {
      interval = setInterval(() => setTimer((t) => t - 1), 1000);
    }
    return () => clearInterval(interval);
  }, [step, timer]);

  const handleSendOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setIsLoading(true);

    try {
      await authService.requestOtp(phone);
      setStep('otp');
      setTimer(90);
      setOtp('12345'); // Pre-fill mock code for ultimate demo convenience!
    } catch (err: any) {
      setErrorMessage(err.message || 'خطا در ارسال کد تایید');
    } finally {
      setIsLoading(false);
    }
  };

  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setIsLoading(true);

    try {
      const { user } = await authService.verifyOtp(phone, otp);
      onSuccess(user);
      onClose();
    } catch (err: any) {
      setErrorMessage(err.message || 'کد تایید نادرست است');
    } finally {
      setIsLoading(false);
    }
  };

  const handleQuickLogin = async (presetPhone: string) => {
    setPhone(presetPhone);
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const { user } = await authService.verifyOtp(presetPhone, '12345');
      onSuccess(user);
      onClose();
    } catch (err: any) {
      setErrorMessage(err.message);
    } finally {
      setIsLoading(false);
    }
  };

  const resetForm = () => {
    setStep('phone');
    setOtp('');
    setErrorMessage(null);
  };

  return (
    <Modal
      isOpen={isOpen}
      onClose={() => {
        resetForm();
        onClose();
      }}
      title={step === 'phone' ? 'ورود به مستر دستباف' : 'تایید شماره موبایل'}
      subtitle="احراز هویت پیامکی از طریق سیستم وردپرس و اوتینو"
      maxWidth="sm"
    >
      <div className="space-y-4">
        {/* Prototype Mock Warning Banner */}
        <div className="bg-amber-50 border border-amber-200/80 rounded-xl p-3 text-xs text-amber-900 flex items-start gap-2.5">
          <ShieldAlert className="w-4 h-4 text-amber-700 shrink-0 mt-0.5" />
          <div className="leading-relaxed">
            <span className="font-bold">محیط دِمو (بدون ارسال پیامک واقعی):</span>
            <p className="mt-0.5 text-stone-600">
              کد تایید پیامکی تستی <strong className="font-mono text-amber-900 font-bold">12345</strong> است.
            </p>
          </div>
        </div>

        {errorMessage && (
          <div className="bg-red-50 border border-red-200 text-red-700 text-xs p-3 rounded-xl animate-in fade-in">
            {errorMessage}
          </div>
        )}

        {step === 'phone' ? (
          <form onSubmit={handleSendOtp} className="space-y-4">
            <div>
              <label className="block text-xs font-semibold text-stone-700 mb-1.5">
                شماره تلفن همراه (فرمت ۰۹xxxxxxxxx)
              </label>
              <div className="relative">
                <input
                  type="tel"
                  dir="ltr"
                  value={phone}
                  onChange={(e) => setPhone(e.target.value)}
                  placeholder="09123456789"
                  className="w-full pl-10 pr-3 py-2.5 bg-stone-50 border border-stone-200 rounded-xl text-sm font-mono text-stone-800 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 transition"
                  required
                />
                <Smartphone className="w-4 h-4 text-stone-400 absolute left-3 top-3" />
              </div>
              <p className="text-[11px] text-stone-500 mt-1">
                کد تایید ورود ۵ رقمی به این شماره ارسال خواهد شد.
              </p>
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-semibold py-2.5 rounded-xl text-xs transition shadow-xs flex items-center justify-center gap-2"
            >
              {isLoading ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  در حال بررسی...
                </>
              ) : (
                'دریافت کد تایید'
              )}
            </button>

            {/* Quick Demo Shortcuts */}
            <div className="pt-3 border-t border-stone-100">
              <p className="text-[11px] font-medium text-stone-500 mb-2">ورود سریع برای بازبینی و تست:</p>
              <div className="grid grid-cols-2 gap-2 text-xs">
                <button
                  type="button"
                  onClick={() => handleQuickLogin('09123456789')}
                  className="p-2 rounded-xl border border-stone-200 hover:border-amber-700 hover:bg-amber-50/50 text-right transition"
                >
                  <p className="font-bold text-amber-900">سارا احمدی</p>
                  <p className="text-[10px] text-stone-500">هنرجو (اشتراک برنزی)</p>
                </button>
                <button
                  type="button"
                  onClick={() => handleQuickLogin('09998887766')}
                  className="p-2 rounded-xl border border-stone-200 hover:border-red-700 hover:bg-red-50/50 text-right transition"
                >
                  <p className="font-bold text-red-900">علیرضا بافنده</p>
                  <p className="text-[10px] text-stone-500">مدیر ارشد پلتفرم</p>
                </button>
              </div>
            </div>
          </form>
        ) : (
          <form onSubmit={handleVerifyOtp} className="space-y-4">
            <div className="flex items-center justify-between text-xs text-stone-600 bg-stone-50 p-2.5 rounded-xl">
              <span>شماره مقصد: <strong dir="ltr" className="font-mono">{phone}</strong></span>
              <button
                type="button"
                onClick={() => setStep('phone')}
                className="text-amber-800 hover:underline text-[11px] font-medium"
              >
                ویرایش شماره
              </button>
            </div>

            <div>
              <label className="block text-xs font-semibold text-stone-700 mb-1.5">
                کد تایید پیامکی (آزمایشی: ۱۲۳۴۵)
              </label>
              <div className="relative">
                <input
                  type="text"
                  dir="ltr"
                  value={otp}
                  onChange={(e) => setOtp(e.target.value)}
                  placeholder="12345"
                  maxLength={5}
                  className="w-full text-center tracking-widest text-lg font-mono py-2 bg-stone-50 border border-stone-200 rounded-xl text-stone-800 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 transition"
                  autoFocus
                  required
                />
                <KeyRound className="w-4 h-4 text-stone-400 absolute left-3 top-3.5" />
              </div>
            </div>

            <div className="flex items-center justify-between text-xs text-stone-500">
              {timer > 0 ? (
                <span>ارسال مجدد کد پس از {timer} ثانیه</span>
              ) : (
                <button
                  type="button"
                  onClick={() => setTimer(90)}
                  className="text-amber-800 hover:underline font-medium"
                >
                  ارسال مجدد کد تایید
                </button>
              )}
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-semibold py-2.5 rounded-xl text-xs transition shadow-xs flex items-center justify-center gap-2"
            >
              {isLoading ? (
                <>
                  <RefreshCw className="w-4 h-4 animate-spin" />
                  در حال تایید...
                </>
              ) : (
                'ورود به حساب کاربری'
              )}
            </button>
          </form>
        )}
      </div>
    </Modal>
  );
};
