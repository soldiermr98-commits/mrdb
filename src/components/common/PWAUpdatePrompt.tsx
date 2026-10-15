/**
 * @file components/common/PWAUpdatePrompt.tsx
 * @description v1.5.1 — autoUpdate UX: به‌جای دکمهٔ تأیید (که کاربر می‌توانست X بزند
 * و SW جدید هیچ‌وقت فعال نشود)، یک نوار «در حال به‌روزرسانی…» غیرقابل رد نشان می‌دهیم.
 *
 * رفتار:
 *  - وقتی needRefresh درست شود (SW جدید منتظر است)، نوار به‌صورت خودکار ظاهر می‌شود.
 *  - در همان لحظه، updateServiceWorker(true) صدا زده می‌شود → پیام SKIP_WAITING ارسال می‌شود.
 *  - وقتی controllerChange رخ دهد، vite-plugin-pwa به‌صورت خودکار صفحه را reload می‌کند.
 *  - کاربر نمی‌تواند نوار را ببندد (بدون دکمهٔ X) — ولی نوار مزاحم نیست: بالای داک،
 *    نیمه‌شفاف با اسپینر کوچک و متن کوتاه.
 *  - اگر بروزرسانی به هر دلیلی شکست بخورد (offline سرور، رد SW توسط مرورگر)،
 *    بعد از ۶ ثانیه نوار خودش محو می‌شود تا UI قفل نشود.
 */

import { useEffect } from 'react';
import { RefreshCw } from 'lucide-react';
// v1.5.1: React flavor of the virtual:pwa-register module (vite-plugin-pwa 2.x);
// types come from tsconfig "types": ["vite-plugin-pwa/client"].
import { useRegisterSW } from 'virtual:pwa-register/react';

export const PWAUpdatePrompt: React.FC = () => {
  // autoUpdate: به‌محض needRefresh، خودمان updateServiceWorker را صدا می‌زنیم تا
  // بدون منتظر ماندن برای تعامل کاربر، SKIP_WAITING ارسال شود.
  const { needRefresh, updateServiceWorker } = useRegisterSW();

  // v1.5.1: به‌محض آماده‌شدن SW جدید، تراکنش آپدیت را آغاز کن — بدون کلیک کاربر.
  useEffect(() => {
    if (needRefresh[0]) {
      // true = forceReload; vite-plugin-pwa پس از controllerchange صفحه را reload می‌کند.
      // این فراخوانی fail-safe است؛ اگر SW نتواند activate شود، نوار خودش بعد از ۶ ثانیه محو می‌شود.
      void updateServiceWorker(true);
    }
  }, [needRefresh, updateServiceWorker]);

  // v1.5.1: اگر بروزرسانی گیر کرد (مثلاً SW جدید reject شد)، بعد از ۶ ثانیه
  // نوار را مخفی کن تا UI قفل نشود. این فقط در حالت خطا رخ می‌دهد؛ در حالت
  // موفق، reload صفحه قبل از این تایمر اتفاق می‌افتد.
  const [visible, setVisible] = needRefresh;
  useEffect(() => {
    if (!needRefresh[0]) return;
    const t = window.setTimeout(() => {
      // اگر هنوز بعد از ۶ ثانیه needRefresh بود و صفحه reload نشده بود، مخفی کن.
      // این یعنی SW جدید نتوانسته activate شود — کاربر در بار بعدی دوباره تلاش می‌کند.
      setVisible(false);
    }, 6000);
    return () => window.clearTimeout(t);
  }, [needRefresh, setVisible]);

  if (!needRefresh[0]) return null;

  return (
    // role="status" + aria-live=polite: صفحه‌خوان‌ها بدون قطع کاربر اعلام می‌کنند.
    // z-[60]: بالای toast (z-50) و BottomNav (z-40). base/styles هم‌‌الگوی OfflineIndicator.
    <div
      role="status"
      aria-live="polite"
      className="fixed bottom-20 left-4 right-4 md:left-auto md:right-4 md:bottom-6 z-[60] flex items-center gap-3 rounded-2xl border border-amber-300 bg-white/95 backdrop-blur-md px-4 py-3 shadow-2xl animate-in fade-in slide-in-from-bottom-2"
    >
      <span className="shrink-0 size-10 rounded-xl bg-amber-100 flex items-center justify-center">
        <RefreshCw className="size-5 text-amber-800 animate-spin" />
      </span>
      <div className="flex-1 min-w-0">
        <p className="text-xs font-bold text-stone-900">در حال بارگذاری نسخه جدید…</p>
        <p className="text-[11px] text-stone-500 mt-0.5 leading-relaxed">
          چند ثانیه طول می‌کشد؛ صفحه به‌صورت خودکار به‌روز می‌شود.
        </p>
      </div>
    </div>
  );
};
