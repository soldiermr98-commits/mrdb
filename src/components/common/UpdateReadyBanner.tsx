/**
 * @file components/common/UpdateReadyBanner.tsx
 * @description v1.5.6 — بنر مرئی «نسخهٔ جدید آماده است» با دکمهٔ «بروزرسانی».
 *
 * پیشینه (خواستهٔ صریح کارفرما): در نسخه‌های اولیه، کاربر پیام ساده‌ای می‌دید:
 * «نسخه جدید آماده است؛ روی بروزرسانی کلیک کنید». v1.5.5 این UX را با reload
 * خاموش عوض کرده بود که کاربر نه متوجه آپدیت می‌شد نه کنترلی داشت.
 *
 * رفتار:
 *  - useVersionChecker وقتی نسخهٔ سرور اکیداً جدیدتر باشد، UPDATE_READY_EVENT
 *    منتشر می‌کند → این بنر ظاهر می‌شود.
 *  - کلیک روی «بروزرسانی» → triggerPwaUpdate(): registration.update() + reload
 *    کش‌باست‌شده (در همان هش/صفحهٔ فعلی).
 *  - دکمهٔ X («بعداً») → برای «همین نسخهٔ سرور» تا آخر سشن مخفی (sessionStorage)
 *    — دفعهٔ بعد که اپ باز شود دوباره بررسی می‌شود.
 *  - برای هر نسخهٔ سرور فقط یک‌بار رویداد emit می‌شود؛ بنر تا کلیک/بستن می‌ماند.
 */

import { useEffect, useState } from 'react';
import { ArrowUpCircle, X } from 'lucide-react';
import {
  UPDATE_READY_EVENT,
  UpdateReadyDetail,
  dismissUpdateBanner,
  triggerPwaUpdate,
} from '../../hooks/useVersionChecker';

export const UpdateReadyBanner: React.FC = () => {
  const [serverVersion, setServerVersion] = useState<string | null>(null);
  const [isUpdating, setIsUpdating] = useState(false);

  useEffect(() => {
    const onUpdateReady = (e: Event) => {
      const detail = (e as CustomEvent<UpdateReadyDetail>).detail;
      if (detail?.serverVersion) {
        setServerVersion(detail.serverVersion);
      }
    };
    window.addEventListener(UPDATE_READY_EVENT, onUpdateReady);
    return () => window.removeEventListener(UPDATE_READY_EVENT, onUpdateReady);
  }, []);

  if (!serverVersion) return null;

  const handleUpdate = () => {
    if (isUpdating) return;
    setIsUpdating(true);
    void triggerPwaUpdate();
  };

  const handleDismiss = () => {
    dismissUpdateBanner(serverVersion);
    setServerVersion(null);
  };

  return (
    <div
      role="alertdialog"
      aria-label="بروزرسانی نسخهٔ جدید"
      className="fixed bottom-20 left-4 right-4 md:left-auto md:right-4 md:bottom-6 md:w-96 z-[70] flex items-center gap-3 rounded-2xl border border-amber-400 bg-white shadow-2xl px-4 py-3.5 animate-in fade-in slide-in-from-bottom-2"
    >
      <span className="shrink-0 size-11 rounded-xl bg-amber-100 flex items-center justify-center">
        <ArrowUpCircle className="size-6 text-amber-800" />
      </span>
      <div className="flex-1 min-w-0">
        <p className="text-sm font-bold text-stone-900">نسخهٔ جدید آماده است</p>
        <p className="text-[11px] text-stone-500 mt-0.5 leading-relaxed">
          برای دریافت آخرین تغییرات، بروزرسانی کنید.
        </p>
        <button
          type="button"
          onClick={handleUpdate}
          disabled={isUpdating}
          className="mt-2 inline-flex items-center gap-1.5 rounded-xl bg-amber-700 px-3.5 py-1.5 text-xs font-bold text-white transition hover:bg-amber-800 disabled:opacity-60"
        >
          {isUpdating ? 'در حال بروزرسانی…' : 'بروزرسانی'}
        </button>
      </div>
      <button
        type="button"
        onClick={handleDismiss}
        aria-label="بعداً بروزرسانی می‌کنم"
        className="shrink-0 self-start p-1 rounded-lg text-stone-400 transition hover:bg-stone-100 hover:text-stone-600"
      >
        <X className="size-4" />
      </button>
    </div>
  );
};
