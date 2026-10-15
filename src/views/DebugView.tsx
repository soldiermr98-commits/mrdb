/**
 * @file views/DebugView.tsx
 * @description v1.5.1 — صفحهٔ عیب‌یابی #/debug برای کاربر نهایی و پشتیبانی.
 *
 * هدف: ریشه‌یابی «خطای اتصال» روی دستگاه کاربر بدون نیاز به DevTools.
 * کاربر کافیست به آدرس app.mrdastbaaf.com/#/debug برود تا ببیند:
 *   - نسخهٔ PWA فعلی و تاریخ بیلد
 *   - آیا Service Worker ثبت شده؟ آیا در حالت «waiting» است؟
 *   - API base URL واقعی embed شده در باندل
 *   - آیا توکن Bearer در localStorage هست؟
 *   - تست زندهٔ اتصال به اندپوینت عمومی (GET /memberships/plans)
 *   - دکمهٔ «پاک کردن کش و بارگذاری مجدد» — تمام cache های SW و localStorage
 *     را پاک می‌کند و سپس hard reload می‌زند.
 *
 * صفحه کاملاً عمومی است (بدون نیاز به لاگین) و هیچ دادهٔ حساسی نشان نمی‌دهد.
 * نمایش توکن ماسک‌شده است (فقط ۸ کاراکتر اول + …).
 */

import React, { useEffect, useState, useCallback } from 'react';
import {
  Bug,
  RefreshCw,
  CheckCircle2,
  XCircle,
  AlertTriangle,
  Loader2,
  Smartphone,
  Server,
  Database,
  Clock,
  Trash2,
  ChevronLeft,
} from 'lucide-react';
import { PWA_VERSION, PWA_BUILD_TIME } from '../version';
import { API_BASE, isRealApiMode, getStoredAuthToken, clearStoredAuthToken } from '../services/apiClient';

interface SWStatus {
  supported: boolean;
  registered: boolean | null; // null = unknown
  waiting: boolean;
  controller: boolean;
  scriptURL: string | null;
}

interface ApiTestResult {
  status: 'idle' | 'pending' | 'success' | 'fail';
  httpStatus?: number;
  durationMs?: number;
  error?: string;
}

export const DebugView: React.FC<{ onNavigate: (view: string) => void }> = ({ onNavigate }) => {
  const [swStatus, setSwStatus] = useState<SWStatus>({
    supported: typeof navigator !== 'undefined' && 'serviceWorker' in navigator,
    registered: null,
    waiting: false,
    controller: false,
    scriptURL: null,
  });

  const [apiTest, setApiTest] = useState<ApiTestResult>({ status: 'idle' });
  const [isPurging, setIsPurging] = useState(false);
  const [purgeDone, setPurgeDone] = useState(false);

  // خواندن وضعیت SW — async چون navigator.serviceWorker.ready یک promise است.
  useEffect(() => {
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker
      .getRegistration()
      .then((reg) => {
        setSwStatus({
          supported: true,
          registered: !!reg,
          waiting: !!(reg?.waiting),
          controller: !!navigator.serviceWorker.controller,
          scriptURL: reg?.active?.scriptURL ?? reg?.installing?.scriptURL ?? null,
        });
      })
      .catch(() => {
        setSwStatus({ supported: true, registered: false, waiting: false, controller: false, scriptURL: null });
      });
  }, []);

  const runApiTest = useCallback(async () => {
    setApiTest({ status: 'pending' });
    const start = performance.now();
    try {
      const res = await fetch(`${API_BASE}/memberships/plans`, {
        method: 'GET',
        credentials: 'omit',
        headers: { Accept: 'application/json' },
      });
      const durationMs = Math.round(performance.now() - start);
      if (res.ok) {
        setApiTest({ status: 'success', httpStatus: res.status, durationMs });
      } else {
        setApiTest({ status: 'fail', httpStatus: res.status, durationMs, error: `HTTP ${res.status}` });
      }
    } catch (e) {
      const durationMs = Math.round(performance.now() - start);
      setApiTest({
        status: 'fail',
        durationMs,
        error: e instanceof Error ? e.message : String(e),
      });
    }
  }, []);

  // اجرای اولیه تست API هنگام mount.
  useEffect(() => {
    void runApiTest();
  }, [runApiTest]);

  // پاک‌سازی کامل — SW unregister + caches.clear + localStorage پاک‌سازی
  const purgeAndReload = useCallback(async () => {
    setIsPurging(true);
    try {
      // ۱) تمام registrations های SW را unregister کن
      if ('serviceWorker' in navigator) {
        const regs = await navigator.serviceWorker.getRegistrations();
        await Promise.all(regs.map((r) => r.unregister()));
      }
      // ۲) تمام cache های Cache API را پاک کن
      if ('caches' in window) {
        const keys = await caches.keys();
        await Promise.all(keys.map((k) => caches.delete(k)));
      }
      // ۳) توکن احراز هویت را پاک کن (localStorage کلید دیگر را نگه نمی‌داریم —
      //    سرویس‌ها دیگر localStorage را برای state کاربردی استفاده نمی‌کنند)
      clearStoredAuthToken();
      // ۴) sessionStorage را پاک کن
      try {
        sessionStorage.clear();
      } catch {
        // ignore
      }
      setPurgeDone(true);
      // ۵) بعد از ۸۰۰ms (تا کاربر پیام موفقیت را ببیند)، hard reload
      window.setTimeout(() => {
        // cache-busting query — حتی اگر SW هنوز زنده باشد، نمی‌تواند از cache سرو کند
        window.location.href = `/?_=${Date.now()}#/`;
      }, 800);
    } catch {
      // حتی اگر شکست خورد، reload بزن — مهم نیست cache پاک شد یا نه
      setIsPurging(false);
      window.setTimeout(() => window.location.reload(), 300);
    }
  }, []);

  const authToken = getStoredAuthToken();
  const maskedToken = authToken ? `${authToken.substring(0, 8)}…${authToken.substring(authToken.length - 4)}` : null;

  const buildTimeFa = new Date(PWA_BUILD_TIME).toLocaleString('en-GB', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Asia/Tehran',
  });

  return (
    <div dir="rtl" className="min-h-screen bg-stone-50 px-4 py-6 pb-28 max-w-3xl mx-auto">
      {/* Header */}
      <div className="flex items-center gap-3 mb-6">
        <button
          onClick={() => onNavigate('home')}
          className="size-10 rounded-xl bg-white border border-stone-200 flex items-center justify-center hover:bg-stone-100 transition"
          aria-label="بازگشت به خانه"
        >
          <ChevronLeft className="size-5" />
        </button>
        <div className="flex items-center gap-2 flex-1">
          <Bug className="size-5 text-amber-700" />
          <div>
            <h1 className="text-base font-bold">عیب‌یابی اپ</h1>
            <p className="text-xs text-stone-500">برای پشتیبانی — اطلاعات فنی نسخهٔ روی دستگاه شما</p>
          </div>
        </div>
      </div>

      {/* Version section */}
      <Section title="نسخهٔ بیلد" icon={<Smartphone className="size-4 text-amber-700" />}>
        <Row label="نسخهٔ PWA" value={`v${PWA_VERSION}`} mono highlight />
        <Row label="زمان بیلد" value={buildTimeFa} mono />
        <Row label="حالت اجرا" value={isRealApiMode ? 'Real-API (واقعی)' : 'دمو'} />
      </Section>

      {/* Service Worker section */}
      <Section title="Service Worker" icon={<RefreshCw className="size-4 text-amber-700" />}>
        <Row
          label="پشتیبانی مرورگر"
          value={
            swStatus.supported ? (
              <span className="inline-flex items-center gap-1 text-emerald-700">
                <CheckCircle2 className="size-3.5" /> بله
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-red-700">
                <XCircle className="size-3.5" /> خیر
              </span>
            )
          }
        />
        <Row
          label="ثبت شده"
          value={
            swStatus.registered === null ? (
              <span className="inline-flex items-center gap-1 text-stone-500">
                <Loader2 className="size-3.5 animate-spin" /> بررسی…
              </span>
            ) : swStatus.registered ? (
              <span className="inline-flex items-center gap-1 text-emerald-700">
                <CheckCircle2 className="size-3.5" /> بله
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-amber-700">
                <AlertTriangle className="size-3.5" /> خیر
              </span>
            )
          }
        />
        <Row
          label="کنترل‌کنندهٔ فعال"
          value={
            swStatus.controller ? (
              <span className="inline-flex items-center gap-1 text-emerald-700">
                <CheckCircle2 className="size-3.5" /> فعال
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-amber-700">
                <AlertTriangle className="size-3.5" /> غیرفعال
              </span>
            )
          }
        />
        <Row
          label="در انتظار فعال‌سازی"
          value={
            swStatus.waiting ? (
              <span className="inline-flex items-center gap-1 text-amber-700">
                <AlertTriangle className="size-3.5" /> بله (SW جدید منتظر است)
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-emerald-700">
                <CheckCircle2 className="size-3.5" /> خیر
              </span>
            )
          }
        />
        {swStatus.scriptURL && (
          <Row label="آدرس اسکریپت" value={<code className="text-[10px] break-all" dir="ltr">{swStatus.scriptURL}</code>} />
        )}
      </Section>

      {/* API connection section */}
      <Section title="اتصال به سرور" icon={<Server className="size-4 text-amber-700" />}>
        <Row label="آدرس API" value={<code className="text-[10px] break-all" dir="ltr">{API_BASE}</code>} />
        <Row label="تست زندهٔ /memberships/plans" value={<ApiTestIndicator result={apiTest} />} />
        {apiTest.durationMs !== undefined && (
          <Row label="زمان پاسخ" value={`${apiTest.durationMs}ms`} mono />
        )}
        {apiTest.httpStatus !== undefined && (
          <Row label="کد HTTP" value={String(apiTest.httpStatus)} mono />
        )}
        {apiTest.error && (
          <div className="mt-2 rounded-md bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900 p-2">
            <p className="text-[11px] text-red-800 dark:text-red-200" dir="ltr">
              {apiTest.error}
            </p>
          </div>
        )}
        <button
          onClick={runApiTest}
          disabled={apiTest.status === 'pending'}
          className="mt-2 w-full inline-flex items-center justify-center gap-1.5 rounded-lg border border-stone-300 bg-white px-3 py-2 text-xs font-semibold hover:bg-stone-50 disabled:opacity-50 transition"
        >
          {apiTest.status === 'pending' ? <Loader2 className="size-3.5 animate-spin" /> : <RefreshCw className="size-3.5" />}
          تکرار تست
        </button>
      </Section>

      {/* Auth token section */}
      <Section title="توکن احراز هویت" icon={<Database className="size-4 text-amber-700" />}>
        <Row
          label="وجود توکن"
          value={
            maskedToken ? (
              <span className="inline-flex items-center gap-1 text-emerald-700">
                <CheckCircle2 className="size-3.5" /> بله
              </span>
            ) : (
              <span className="inline-flex items-center gap-1 text-stone-500">
                <XCircle className="size-3.5" /> خیر (لاگین نشده)
              </span>
            )
          }
        />
        {maskedToken && (
          <Row label="توکن (ماسک‌شده)" value={<code className="text-[10px]" dir="ltr">{maskedToken}</code>} />
        )}
      </Section>

      {/* Danger zone */}
      <Section title="بازنشانی کش (پشتیبانی)" icon={<Trash2 className="size-4 text-red-700" />}>
        <p className="text-xs text-stone-600 dark:text-stone-400 leading-relaxed mb-3">
          اگر اپ در حالت اشتباه گیر کرده (مثلاً «خطای اتصال» مداوم در حالی که اینترنت سالم است)،
          این دکمه تمام کش‌های محلی، Service Worker و توکن احراز هویت را پاک می‌کند و سپس
          صفحه را به‌صورت خودکار بارگذاری مجدد می‌کند. بعد از آن باید دوباره وارد شوید.
        </p>
        <button
          onClick={purgeAndReload}
          disabled={isPurging || purgeDone}
          className="w-full inline-flex items-center justify-center gap-2 rounded-lg bg-red-700 text-white px-3 py-2.5 text-xs font-semibold hover:bg-red-800 disabled:opacity-60 transition"
        >
          {isPurging ? (
            <>
              <Loader2 className="size-4 animate-spin" />
              در حال پاک‌سازی…
            </>
          ) : purgeDone ? (
            <>
              <CheckCircle2 className="size-4" />
              انجام شد — در حال بارگذاری مجدد…
            </>
          ) : (
            <>
              <Trash2 className="size-4" />
              پاک‌سازی کامل و بارگذاری مجدد
            </>
          )}
        </button>
      </Section>

      {/* Footer */}
      <div className="mt-6 flex items-center gap-2 text-[10px] text-stone-400">
        <Clock className="size-3" />
        <span>برای پشتیبانی، اسکرین‌شات این صفحه را ارسال کنید.</span>
      </div>
    </div>
  );
};

// ---------------------------------------------------------------------------
// زیرکامپوننت‌ها
// ---------------------------------------------------------------------------

const Section: React.FC<{ title: string; icon: React.ReactNode; children: React.ReactNode }> = ({ title, icon, children }) => (
  <div className="mb-4 rounded-2xl border border-stone-200 dark:border-stone-800 bg-white dark:bg-stone-900 overflow-hidden">
    <div className="px-4 py-3 border-b border-stone-100 dark:border-stone-800 flex items-center gap-2">
      {icon}
      <h2 className="text-sm font-semibold">{title}</h2>
    </div>
    <div className="p-4 space-y-2.5">{children}</div>
  </div>
);

const Row: React.FC<{
  label: string;
  value: React.ReactNode;
  mono?: boolean;
  highlight?: boolean;
}> = ({ label, value, mono, highlight }) => (
  <div className="flex items-center justify-between gap-3 text-xs">
    <span className="text-stone-500 dark:text-stone-400">{label}</span>
    <span
      className={
        mono
          ? `font-mono ${highlight ? 'text-amber-900 dark:text-amber-300 font-bold' : ''}`
          : highlight
            ? 'text-amber-900 dark:text-amber-300 font-bold'
            : 'text-stone-800 dark:text-stone-200'
      }
    >
      {value}
    </span>
  </div>
);

const ApiTestIndicator: React.FC<{ result: ApiTestResult }> = ({ result }) => {
  if (result.status === 'idle') return <span className="text-stone-400 text-xs">انتظار</span>;
  if (result.status === 'pending')
    return (
      <span className="inline-flex items-center gap-1 text-stone-500">
        <Loader2 className="size-3.5 animate-spin" /> در حال تست…
      </span>
    );
  if (result.status === 'success')
    return (
      <span className="inline-flex items-center gap-1 text-emerald-700">
        <CheckCircle2 className="size-3.5" /> موفق
      </span>
    );
  return (
    <span className="inline-flex items-center gap-1 text-red-700">
      <XCircle className="size-3.5" /> ناموفق
    </span>
  );
};
