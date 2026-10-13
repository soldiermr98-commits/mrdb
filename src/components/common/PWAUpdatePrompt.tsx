/**
 * @file components/common/PWAUpdatePrompt.tsx
 * @description DEV-7: service-worker update prompt for PWA prompt mode.
 * When a new precached SW is waiting (onNeedRefresh → needRefresh state), a
 * fixed bottom card offers «بارگذاری مجدد» which sends SKIP_WAITING to the
 * waiting worker (the runtime reloads the window once it takes control).
 * Unlike modals, this card must NOT lock scroll — the user can keep browsing
 * until they decide to reload. No blue/indigo: amber card + emerald action.
 */

import React, { useState } from 'react';
import { RefreshCw, X } from 'lucide-react';
// DEV-7: React flavor of the virtual:pwa-register module (vite-plugin-pwa 2.x);
// types come from tsconfig "types": ["vite-plugin-pwa/client"].
import { useRegisterSW } from 'virtual:pwa-register/react';

export const PWAUpdatePrompt: React.FC = () => {
  // DEV-7: registration is handled entirely by the hook; failures (insecure
  // context, etc.) are swallowed — the app keeps working without the prompt.
  const { needRefresh, updateServiceWorker } = useRegisterSW();
  const [dismissed, setDismissed] = useState(false);

  if (!needRefresh[0] || dismissed) return null;

  return (
    // DEV-7: polite live region (role="status" implies it); z-[60] sits above
    // the toast (z-50) and BottomNav (z-40); mirrors OfflineIndicator offsets.
    <div
      role="status"
      aria-live="polite"
      className="fixed bottom-20 left-4 right-4 md:left-auto md:right-4 md:bottom-6 z-[60] flex items-center gap-3 rounded-2xl border border-amber-300 bg-white/95 backdrop-blur-md px-4 py-3 shadow-2xl animate-in fade-in slide-in-from-bottom-2"
    >
      <span className="shrink-0 w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center">
        <RefreshCw className="w-5 h-5 text-amber-800" />
      </span>
      <div className="flex-1 min-w-0">
        <p className="text-xs font-bold text-stone-900">بروزرسانی جدید آماده است</p>
        <p className="text-[11px] text-stone-500 mt-0.5 leading-relaxed">
          برای اعمال امکانات جدید و رفع اشکال‌ها، برنامه را بارگذاری مجدد کنید.
        </p>
      </div>
      <button
        onClick={() => updateServiceWorker(true)}
        className="shrink-0 min-h-[44px] px-3 rounded-xl bg-emerald-700 hover:bg-emerald-800 text-white text-xs font-semibold transition"
      >
        بارگذاری مجدد
      </button>
      <button
        onClick={() => setDismissed(true)}
        aria-label="بستن پیام بروزرسانی"
        className="shrink-0 w-11 h-11 flex items-center justify-center rounded-xl text-stone-400 hover:text-stone-700 hover:bg-stone-100 transition"
      >
        <X className="w-4 h-4" />
      </button>
    </div>
  );
};
