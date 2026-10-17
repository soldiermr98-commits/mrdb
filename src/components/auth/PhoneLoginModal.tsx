/**
 * @file components/auth/PhoneLoginModal.tsx
 * @description Phone login modal — OTP authentication against the WordPress bridge
 *
 * v1.5.7 (بازطراحی موبایل — گزارش کارفرما «کیبورد روی پاپ‌آپ می‌افتد؛ مدرن‌تر باشد»):
 *  - مودال لایه (Modal.tsx) در موبایل Bottom Sheet شد + interactive-widget=resizes-content
 *    ⇒ کیبورد هرگز ورودی را نمی‌پوشاند.
 *  - گام شماره: دیگر auto-focus ندارد (باز شدن ناگهانی کیبورد روی صورت کاربر نبود)؛
 *    ورودی ۱۶px (ضد zoom جهش‌ی iOS)، هدف لمسی بزرگ‌تر، CTA گرادیان برند.
 *  - گام کد: نمایش سلولی ۶خانه‌ای (الگوی استاندارد OTP) با یک input واقعی نامرئی
 *    روی خانه‌ها — autoComplete="one-time-code" و پیست/کیبورد سیستم سرِ جایشان‌اند؛
 *    ارسال خودکار وقتی کد کامل شد؛ فوکوس فقط بعد از رفتن به گام کد می‌آید.
 *  - منطق قبلی دست‌نخورده: پرچم isRegistered و ثبت‌نام نام‌دار (v1.3.0)، ارسال مجدد
 *    واقعی BUG-63، ورود سریع دمو، بازنشانی کامل فرم بین بازشدن‌ها.
 */

import React, { useState, useEffect, useRef } from 'react';
import { Smartphone, KeyRound, ShieldAlert, RefreshCw, ArrowRight } from 'lucide-react';
import { Modal } from '../common/Modal';
import { authService } from '../../services/authService';
import { isRealApiMode } from '../../services/apiClient';
import { toEnglishDigits } from '../../utils/format';
import { UserProfile } from '../../types';

// v1.2.2: سرور از افزونهٔ 1.2.0 کد ۶رقمی می‌فرستد (wp_rand(100000,999999))؛
// دمو روی کد آزمایشی ۵رقمی 12345 می‌ماند — طول ورودی بر اساس حالت انتخاب می‌شود.
const OTP_LENGTH = isRealApiMode ? 6 : 5;

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
  const [phone, setPhone] = useState(isRealApiMode ? '' : '09123456789');
  const [otp, setOtp] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [timer, setTimer] = useState(90);
  // v1.3.0 — تکمیل ثبت‌نام شمارهٔ جدید: پرچم از پاسخ requestOtp می‌آید
  // (true = کاربر موجود → هیچ فیلد نامی نشان داده نمی‌شود؛ undefined = سرور
  // قدیمی/دموی شناخته‌شده — همان رفتار بدون اصطکاک؛ false = ثبت‌نام جدید →
  // دو ورودی الزامی «نام» و «نام خانوادگی» در گام OTP).
  const [isRegistered, setIsRegistered] = useState<boolean | undefined>(undefined);
  const [firstName, setFirstName] = useState('');
  const [lastName, setLastName] = useState('');
  const [nameError, setNameError] = useState<string | null>(null);
  // v1.5.7: ارسال خودکار کد کامل‌شده — گارد ضد دوباره‌ارسال
  const autoSubmitRef = useRef<HTMLFormElement | null>(null);

  useEffect(() => {
    let interval: any;
    if (step === 'otp' && timer > 0) {
      interval = setInterval(() => setTimer((t) => t - 1), 1000);
    }
    return () => clearInterval(interval);
  }, [step, timer]);

  // v1.5.7: وقتی کد به طول کامل رسید خودکار verify می‌شود (بدون دکمهٔ اضافی).
  const handleOtpChange = (value: string) => {
    const next = toEnglishDigits(value).replace(/\D+/g, '').slice(0, OTP_LENGTH);
    setOtp(next);
  };

  useEffect(() => {
    if (step === 'otp' && otp.length === OTP_LENGTH && !isLoading) {
      // یک تیک تاخیر کوتاه برای اینکه آخرین خانه پرشده رندر شود
      const t = window.setTimeout(() => {
        autoSubmitRef.current?.requestSubmit();
      }, 150);
      return () => window.clearTimeout(t);
    }
  }, [step, otp, isLoading]);

  const handleSendOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);
    setNameError(null);
    setIsLoading(true);

    try {
      const res = await authService.requestOtp(phone);
      setIsRegistered(res.isRegistered);
      setFirstName('');
      setLastName('');
      setStep('otp');
      setTimer(90);
      setOtp(isRealApiMode ? '' : '12345'); // Pre-fill mock code (demo mode only)
    } catch (err: any) {
      setErrorMessage(err.message || 'خطا در ارسال کد تایید');
    } finally {
      setIsLoading(false);
    }
  };

  const handleVerifyOtp = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrorMessage(null);

    // v1.3.0: ثبت‌نام شمارهٔ جدید — نام و نام خانوادگی الزامی (اعتبارسنجی ملایم)
    const cleanFirstName = firstName.trim();
    const cleanLastName = lastName.trim();
    if (isRegistered === false && (cleanFirstName === '' || cleanLastName === '')) {
      setNameError('نام و نام خانوادگی را وارد کنید.');
      return;
    }
    setNameError(null);
    setIsLoading(true);

    try {
      const { user } = await authService.verifyOtp(phone, otp, {
        firstName: cleanFirstName,
        lastName: cleanLastName,
      });
      onSuccess(user);
      onClose();
    } catch (err: any) {
      setErrorMessage(err.message || 'کد تایید نادرست است');
      // v1.5.7: با خطای کد، سلول‌ها خالی می‌شوند تا کاربر همان‌جا دوباره بنویسد
      setOtp('');
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

  // BUG-63: resend actually re-requests the OTP from authService (and restarts
  // the 90-second timer) instead of only resetting the countdown.
  const handleResendOtp = async () => {
    setErrorMessage(null);
    setNameError(null);
    setIsLoading(true);
    try {
      const res = await authService.requestOtp(phone);
      // پرچم ثبت‌نام با ارسال مجدد هم تازه می‌شود (وضعیت شماره ممکن است تغییر کرده باشد)
      setIsRegistered(res.isRegistered);
      setTimer(90);
      setOtp(isRealApiMode ? '' : '12345'); // Pre-fill mock code (demo mode only)
    } catch (err: any) {
      setErrorMessage(err.message || 'خطا در ارسال مجدد کد تایید');
    } finally {
      setIsLoading(false);
    }
  };

  const resetForm = () => {
    setStep('phone');
    setOtp('');
    setErrorMessage(null);
    setIsRegistered(undefined);
    setFirstName('');
    setLastName('');
    setNameError(null);
  };

  // v1.5.7: سلول‌های نمایشی OTP — خانهٔ فعال (جایی که عدد بعدی می‌نشیند) هایلایت است
  const otpCells = Array.from({ length: OTP_LENGTH }, (_, i) => otp[i] ?? '');

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
      // v1.2.7 (فیکس کارفرما «فرم ورود دیده نمی‌شود»): مودال ورود باید همیشه بالای
      // مودال‌های خرید (Checkout/Transfer/Zibal — z-50 و دیرتر در DOM) بیفتد.
      overlayClassName="z-[70]"
    >
      <div className="space-y-4">
        {/* Prototype Mock Warning Banner (demo mode only) */}
        {!isRealApiMode && (
        <div className="bg-amber-50 border border-amber-200/80 rounded-xl p-3 text-xs text-amber-900 flex items-start gap-2.5">
          <ShieldAlert className="w-4 h-4 text-amber-700 shrink-0 mt-0.5" />
          <div className="leading-relaxed">
            <span className="font-bold">محیط دِمو (بدون ارسال پیامک واقعی):</span>
            <p className="mt-0.5 text-stone-600">
              کد تایید پیامکی تستی <strong className="font-mono text-amber-900 font-bold">12345</strong> است.
            </p>
          </div>
        </div>
        )}

        {errorMessage && (
          <div className="bg-red-50 border border-red-200 text-red-700 text-xs p-3 rounded-xl animate-in fade-in">
            {errorMessage}
          </div>
        )}

        {step === 'phone' ? (
          <form onSubmit={handleSendOtp} className="space-y-4">
            <div>
              <label className="flex items-center gap-1.5 text-xs font-semibold text-stone-700 mb-2">
                <Smartphone className="w-3.5 h-3.5 text-amber-800" />
                شماره تلفن همراه (فرمت ۰۹xxxxxxxxx)
              </label>
              {/* v1.5.7: بدون autoFocus — کیبورد به اختیار کاربر باز می‌شود؛ ۱۶px ضد iOS-zoom */}
              <input
                type="tel"
                dir="ltr"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="09123456789"
                inputMode="tel"
                autoComplete="tel"
                className="w-full px-4 py-3 text-base bg-stone-50 border border-stone-200 rounded-2xl font-mono text-stone-800 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 focus:bg-white transition shadow-inner"
                required
              />
              <p className="text-[11px] text-stone-500 mt-1.5">
                کد تایید ورود {OTP_LENGTH} رقمی به این شماره ارسال خواهد شد.
              </p>
            </div>

            <button
              type="submit"
              disabled={isLoading}
              className="w-full bg-gradient-to-l from-amber-800 to-amber-700 hover:from-amber-900 hover:to-amber-800 text-white font-bold py-3.5 rounded-2xl text-sm transition shadow-md active:scale-[0.98] flex items-center justify-center gap-2"
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

            {/* Quick Demo Shortcuts (demo mode only) */}
            {!isRealApiMode && (
            <div className="pt-3 border-t border-stone-100">
              <p className="text-[11px] font-medium text-stone-500 mb-2">ورود سریع برای بازبینی و تست:</p>
              <div className="grid grid-cols-2 gap-2 text-xs">
                <button
                  type="button"
                  onClick={() => handleQuickLogin('09123456789')}
                  disabled={isLoading}
                  className="p-2 rounded-xl border border-stone-200 hover:border-amber-700 hover:bg-amber-50/50 text-right transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <p className="font-bold text-amber-900">سارا احمدی</p>
                  <p className="text-[10px] text-stone-500">هنرجو (اشتراک برنزی)</p>
                </button>
                <button
                  type="button"
                  onClick={() => handleQuickLogin('09998887766')}
                  disabled={isLoading}
                  className="p-2 rounded-xl border border-stone-200 hover:border-red-700 hover:bg-red-50/50 text-right transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <p className="font-bold text-red-900">محمدرضا خاموشیان</p>
                  <p className="text-[10px] text-stone-500">مدیر ارشد پلتفرم</p>
                </button>
              </div>
            </div>
            )}
          </form>
        ) : (
          <form onSubmit={handleVerifyOtp} ref={autoSubmitRef} className="space-y-4">
            <div className="flex items-center justify-between text-xs text-stone-600 bg-stone-50 p-2.5 rounded-xl">
              <span>شماره مقصد: <strong dir="ltr" className="font-mono">{phone}</strong></span>
              <button
                type="button"
                onClick={() => setStep('phone')}
                className="flex items-center gap-1 text-amber-800 hover:underline text-[11px] font-medium"
              >
                <ArrowRight className="w-3 h-3" />
                ویرایش شماره
              </button>
            </div>

            {/* v1.3.0 — تکمیل ثبت‌نام: فقط برای شمارهٔ بدون حساب (isRegistered === false).
                کاربران موجود (پرچم true/undefined از سرور قدیمی) بدون هیچ فیلد اضافی وارد می‌شوند. */}
            {isRegistered === false && (
              <div className="space-y-2.5 bg-amber-50/50 border border-amber-100 rounded-xl p-3">
                <p className="text-[11px] text-amber-900 leading-relaxed">
                  این شماره عضو مستر دستباف نیست؛ برای ساخت حساب کاربری، نام و نام خانوادگی خود را وارد کنید.
                </p>
                <div className="grid grid-cols-2 gap-2">
                  <div>
                    <label className="block text-xs font-semibold text-stone-700 mb-1.5">نام</label>
                    <input
                      type="text"
                      dir="rtl"
                      value={firstName}
                      onChange={(e) => setFirstName(e.target.value)}
                      autoComplete="off"
                      maxLength={60}
                      placeholder="مثلاً سارا"
                      className={`w-full px-3 py-2.5 text-base bg-stone-50 border rounded-xl text-stone-800 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 transition ${
                        nameError ? 'border-red-300' : 'border-stone-200'
                      }`}
                    />
                  </div>
                  <div>
                    <label className="block text-xs font-semibold text-stone-700 mb-1.5">نام خانوادگی</label>
                    <input
                      type="text"
                      dir="rtl"
                      value={lastName}
                      onChange={(e) => setLastName(e.target.value)}
                      autoComplete="off"
                      maxLength={60}
                      placeholder="مثلاً احمدی"
                      className={`w-full px-3 py-2.5 text-base bg-stone-50 border rounded-xl text-stone-800 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 transition ${
                        nameError ? 'border-red-300' : 'border-stone-200'
                      }`}
                    />
                  </div>
                </div>
                {nameError && (
                  <p className="text-[11px] text-red-700 bg-red-50 border border-red-100 rounded-lg px-2.5 py-1.5">
                    {nameError}
                  </p>
                )}
              </div>
            )}

            {/* v1.5.7: نمایش سلولی OTP — input واقعی نامرئی روی سلول‌هاست تا
                autoComplete="one-time-code" سیستم و کیبورد عددی سرِ کارشان بمانند */}
            <div>
              <label className="block text-xs font-semibold text-stone-700 mb-2">
                کد تایید پیامکی {isRealApiMode ? '(۶ رقمی پیامک‌شده)' : '(آزمایشی: ۱۲۳۴۵)'}
              </label>
              <div className="relative" dir="ltr">
                <div className="flex gap-1.5 justify-center pointer-events-none select-none" aria-hidden="true">
                  {otpCells.map((digit, i) => (
                    <div
                      key={i}
                      className={`flex-1 h-12 rounded-xl border flex items-center justify-center text-lg font-mono font-bold transition-all duration-150 ${
                        digit
                          ? 'bg-amber-50/70 border-amber-700/50 text-amber-900 shadow-xs'
                          : i === otp.length
                            ? 'bg-white border-amber-700 ring-2 ring-amber-700/25 text-stone-400'
                            : 'bg-stone-50 border-stone-200 text-stone-300'
                      }`}
                    >
                      {digit || (i === otp.length ? <KeyRound className="w-3.5 h-3.5 animate-pulse" /> : '•')}
                    </div>
                  ))}
                </div>
                {/* input واقعی: شفاف روی سلول‌ها، فونت ۱۶px ضد iOS-zoom */}
                <input
                  type="text"
                  dir="ltr"
                  value={otp}
                  onChange={(e) => handleOtpChange(e.target.value)}
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  maxLength={OTP_LENGTH}
                  placeholder=""
                  aria-label={`کد تایید ${OTP_LENGTH} رقمی`}
                  className="absolute inset-0 w-full h-full opacity-0 cursor-text text-base focus:outline-none"
                  autoFocus
                  required
                />
              </div>
              <p className="text-[11px] text-stone-500 mt-2 text-center">
                {otp.length}/{OTP_LENGTH} رقم وارد شده
                {otp.length === OTP_LENGTH && !isLoading ? ' — در حال تایید خودکار...' : ''}
              </p>
            </div>

            <div className="flex items-center justify-center text-xs">
              {timer > 0 ? (
                <span className="inline-flex items-center gap-1.5 bg-stone-100 text-stone-600 rounded-full px-3 py-1.5">
                  <RefreshCw className="w-3 h-3" />
                  ارسال مجدد کد پس از {timer} ثانیه
                </span>
              ) : (
                <button
                  type="button"
                  onClick={handleResendOtp}
                  disabled={isLoading}
                  className="text-amber-800 hover:underline font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  ارسال مجدد کد تایید
                </button>
              )}
            </div>

            <button
              type="submit"
              disabled={isLoading || otp.length < OTP_LENGTH}
              className="w-full bg-gradient-to-l from-amber-800 to-amber-700 hover:from-amber-900 hover:to-amber-800 text-white font-bold py-3.5 rounded-2xl text-sm transition shadow-md active:scale-[0.98] flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed"
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
