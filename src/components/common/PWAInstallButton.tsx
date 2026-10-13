/**
 * @file components/common/PWAInstallButton.tsx
 * @description PWA install button with iOS Safari guide and Android/Desktop support
 */

import React, { useState } from 'react';
import { Download, Share2, X, Smartphone } from 'lucide-react';
import { usePWAInstall } from '../../hooks/usePWAInstall';

interface Props {
  variant?: 'header' | 'banner' | 'compact';
}

export const PWAInstallButton: React.FC<Props> = ({ variant = 'header' }) => {
  const { isInstallable, isInstalled, isIOS, install } = usePWAInstall();
  const [showIOSGuide, setShowIOSGuide] = useState(false);

  // If already installed in standalone PWA mode, suppress button
  if (isInstalled) {
    return null;
  }

  // Render for Chromium / Android / Desktop
  if (isInstallable) {
    if (variant === 'banner') {
      return (
        <div className="bg-amber-900/90 text-amber-50 px-4 py-3 rounded-2xl flex items-center justify-between shadow-md border border-amber-700/50 my-3">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-xl bg-amber-800 flex items-center justify-center shrink-0">
              <Smartphone className="w-5 h-5 text-amber-200" />
            </div>
            <div>
              <p className="text-sm font-semibold">نصب اپلیکیشن مستر دستباف</p>
              <p className="text-xs text-amber-200/80">دسترسی آفلاین و تماشای سریع‌تر ویدیوها در موبایل</p>
            </div>
          </div>
          <button
            onClick={install}
            className="flex items-center gap-1.5 bg-amber-500 hover:bg-amber-400 text-stone-900 font-medium px-3.5 py-1.5 rounded-xl text-xs transition shadow"
          >
            <Download className="w-3.5 h-3.5" />
            نصب سریع
          </button>
        </div>
      );
    }

    return (
      <button
        onClick={install}
        className="flex items-center gap-1.5 bg-amber-700 hover:bg-amber-680 text-amber-50 px-3 py-1.5 rounded-lg text-xs font-medium transition shadow-sm"
        title="نصب اپلیکیشن روی گوشی یا دسکتاپ"
      >
        <Download className="w-3.5 h-3.5" />
        <span>نصب اپلیکیشن</span>
      </button>
    );
  }

  // Render for iOS Safari
  if (isIOS) {
    return (
      <>
        {variant === 'banner' ? (
          <div className="bg-amber-900/90 text-amber-50 px-4 py-3 rounded-2xl flex items-center justify-between shadow-md border border-amber-700/50 my-3">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-xl bg-amber-800 flex items-center justify-center shrink-0">
                <Smartphone className="w-5 h-5 text-amber-200" />
              </div>
              <div>
                <p className="text-sm font-semibold">نصب روی آیفون / آیپد</p>
                <p className="text-xs text-amber-200/80">اجرا تمام‌صفحه بدون نوار مرورگر</p>
              </div>
            </div>
            <button
              onClick={() => setShowIOSGuide(true)}
              className="flex items-center gap-1.5 bg-amber-600 hover:bg-amber-500 text-white font-medium px-3 py-1.5 rounded-xl text-xs transition shadow"
            >
              <Download className="w-3.5 h-3.5" />
              راهنمای نصب
            </button>
          </div>
        ) : (
          <button
            onClick={() => setShowIOSGuide(true)}
            className="flex items-center gap-1.5 border border-amber-700/40 text-amber-800 dark:text-amber-200 hover:bg-amber-50 px-2.5 py-1.5 rounded-lg text-xs font-medium transition"
          >
            <Smartphone className="w-3.5 h-3.5 text-amber-700" />
            <span>نصب در iOS</span>
          </button>
        )}

        {showIOSGuide && (
          <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-xs p-4 animate-in fade-in duration-200">
            <div className="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl text-slate-800 border border-stone-200">
              <div className="flex items-center justify-between mb-4 pb-2 border-b border-stone-100">
                <h3 className="text-base font-bold text-amber-900 flex items-center gap-2">
                  <Smartphone className="w-5 h-5 text-amber-700" />
                  نصب مستر دستباف روی آیفون
                </h3>
                <button
                  onClick={() => setShowIOSGuide(false)}
                  className="p-1 rounded-lg text-stone-400 hover:text-stone-700 hover:bg-stone-100"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>

              <div className="space-y-3 text-xs leading-relaxed text-stone-600">
                <div className="flex items-start gap-3 bg-amber-50 p-2.5 rounded-xl border border-amber-100">
                  <span className="w-6 h-6 rounded-full bg-amber-700 text-white flex items-center justify-center font-bold shrink-0 text-xs">
                    ۱
                  </span>
                  <p>
                    در پایین مرورگر سافاری، روی آیکون <strong className="text-amber-900">اشتراک‌گذاری (Share <Share2 className="w-3 h-3 inline" />)</strong> ضربه بزنید.
                  </p>
                </div>

                <div className="flex items-start gap-3 bg-amber-50 p-2.5 rounded-xl border border-amber-100">
                  <span className="w-6 h-6 rounded-full bg-amber-700 text-white flex items-center justify-center font-bold shrink-0 text-xs">
                    ۲
                  </span>
                  <p>
                    منو را به پایین اسکرول کرده و گزینه <strong className="text-amber-900">«Add to Home Screen»</strong> (افزودن به صفحه اصلی) را انتخاب کنید.
                  </p>
                </div>

                <div className="flex items-start gap-3 bg-amber-50 p-2.5 rounded-xl border border-amber-100">
                  <span className="w-6 h-6 rounded-full bg-amber-700 text-white flex items-center justify-center font-bold shrink-0 text-xs">
                    ۳
                  </span>
                  <p>
                    در گوشه بالا روی <strong className="text-amber-900">Add</strong> بزنید. آیکون مستر دستباف مانند یک اپلیکیشن بومی به گوشی شما اضافه می‌شود.
                  </p>
                </div>
              </div>

              <button
                onClick={() => setShowIOSGuide(false)}
                className="mt-5 w-full rounded-xl bg-amber-800 hover:bg-amber-900 py-2.5 text-xs font-semibold text-white transition shadow"
              >
                متوجه شدم
              </button>
            </div>
          </div>
        )}
      </>
    );
  }

  return null;
};
