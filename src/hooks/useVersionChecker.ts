/**
 * @file src/hooks/useVersionChecker.ts
 * @description v1.5.4 → v1.5.5 — بررسی خودکار نسخهٔ PWA و محتوای سرور.
 *
 * ریشهٔ «کاربر نسخهٔ قدیمی می‌بیند»:
 *   - SW قدیمی چسبیده و autoUpdate کار نمی‌کند
 *   - یا LiteSpeed Cache پاسخ‌های API را کش کرده و قیمت‌ها آپدیت نمی‌شوند
 *
 * راه‌حل:
 *   ۱) هر ۲ دقیقه GET /version را صدا می‌زنیم (با cache-busting query param)
 *   ۲) pwa_version و content_hash را با نسخهٔ محلی مقایسه می‌کنیم
 *   ۳) اگه pwa_version «جدیدتر» بود → نسخهٔ جدید PWA مستقر شده → force reload
 *   ۴) اگه content_hash عوض شده بود → کارفرما چیزی در پیشخوان عوض کرده
 *      → یک رویداد سفارشی emit می‌کنیم تا ویوها داده‌ها را refetch کنند
 *      (بدون reload کامل صفحه — فقط refresh داده‌ها)
 *
 * ─── فیکس v1.5.5 (باگ حلقهٔ reload) ────────────────────────────────────────
 *   چکر v1.5.4 با «!==» مقایسه می‌کرد؛ اگر گزینهٔ نسخهٔ سرور «قدیمی‌تر» از
 *   باندل کلاینت بود (مثلاً سرور 1.5.3 و باندل 1.5.4 — دقیقاً اتفاقی که در
 *   استقرار 1.5.4 افتاد چون option هنوز ساخته نشده بود)، کلاینت بی‌نهایت
 *   reload می‌شد و درخواست‌های نیمه‌کاره (verify-otp) قطع می‌شدند → پیام
 *   «ارتباط با سرور برقرار نشد» با اینترنت کاملاً سالم.
 *   - اکنون فقط «سرور جدیدتر» → reload (semver compare — utils/versionCompare)
 *   - گارد ضدحلقهٔ sessionStorage: برای هر نسخهٔ سرور حداکثر یک reload در
 *     ۹۰ ثانیه؛ حتی اگر منطق semver به هر دلیلی دور زده شود.
 *   - قبل از reload، registration.update() صدا زده می‌شود تا مرورگر در همان
 *     ناوبری sw.js جدید را بگیرد و precache تازه ساخته شود (یک سیکل به‌روزرسانی
 *     SW به‌جای دو سیکل).
 */

import { useEffect, useRef } from 'react';
import { API_BASE } from '../services/apiClient';
import { shouldForcePwaReload } from '../utils/versionCompare';

const VERSION_CHECK_INTERVAL_MS = 2 * 60 * 1000; // ۲ دقیقه
const VERSION_CHECK_TIMEOUT_MS = 8000; // ۸ ثانیه — نباید کاربر را معطل کند

const RELOAD_GUARD_KEY = 'mrdastbaaf_version_reload';
const RELOAD_GUARD_WINDOW_MS = 90_000; // ۹۰ ثانیه

interface ServerVersionInfo {
  pwa_version: string;
  plugin_version: string;
  db_version: string;
  server_time: string;
  timestamp: number;
  content_hash: string;
}

// رویداد داخلی اپ — ویوها به آن گوش می‌دهند تا داده‌ها را refetch کنند
export const CONTENT_CHANGED_EVENT = 'mrdastbaaf:content-changed';

let lastContentHash: string | null = null;
let isReloading = false;

/** گارد ضدحلقه: آیا اخیراً (۹۰ثانیه) برای همین نسخهٔ سرور reload کرده‌ایم؟ */
function wasRecentReloadFor(serverVersion: string): boolean {
  try {
    const raw = sessionStorage.getItem(RELOAD_GUARD_KEY);
    if (!raw) return false;
    const parsed = JSON.parse(raw) as { v?: string; t?: number };
    return parsed.v === serverVersion && typeof parsed.t === 'number'
      && Date.now() - parsed.t < RELOAD_GUARD_WINDOW_MS;
  } catch {
    return false;
  }
}

function markReloadFor(serverVersion: string): void {
  try {
    sessionStorage.setItem(RELOAD_GUARD_KEY, JSON.stringify({ v: serverVersion, t: Date.now() }));
  } catch {
    /* حالت private مرورگر — بی‌اهمیت */
  }
}

/**
 * پیش از reload، به مرورگر بگو sw.js را دوباره چک کند تا precache جدید در همان
 * ناوبری ساخته شود (با timeout کوتاه — هرگز کاربر را معطل نکن).
 */
async function kickServiceWorkerUpdate(): Promise<void> {
  try {
    if (!('serviceWorker' in navigator)) return;
    const registration = await Promise.race([
      navigator.serviceWorker.getRegistration(),
      new Promise<undefined>((resolve) => setTimeout(() => resolve(undefined), 1500)),
    ]);
    if (registration) {
      // fire-and-forget — منتظر install کامل نمی‌مانیم؛ reload با cache-bust
      // خودش باعث گرفتن sw.js تازه می‌شود.
      void registration.update();
    }
  } catch {
    /* بی‌صدا */
  }
}

export function useVersionChecker(localPwaVersion: string): void {
  const versionRef = useRef(localPwaVersion);
  versionRef.current = localPwaVersion;

  useEffect(() => {
    if (!API_BASE) return; // حالت دمو — چک نکن

    let cancelled = false;

    const checkVersion = async () => {
      if (cancelled || isReloading) return;

      try {
        // cache-busting: ?_=timestamp — LiteSpeed نتواند کش کند
        const url = `${API_BASE}/version?_=${Date.now()}`;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), VERSION_CHECK_TIMEOUT_MS);

        const res = await fetch(url, {
          method: 'GET',
          credentials: 'omit',
          headers: {
            Accept: 'application/json',
            'Cache-Control': 'no-cache',
            Pragma: 'no-cache',
          },
          signal: controller.signal,
        });

        clearTimeout(timer);

        if (!res.ok) return; // خطا → بی‌صدا رد شو
        const data: ServerVersionInfo = await res.json();

        if (cancelled) return;

        // ۱) بررسی نسخهٔ PWA — فقط وقتی «سرور جدیدتر» است reload کن (فیکس v1.5.5)
        const serverVersion = data.pwa_version?.trim();
        const localVersion = versionRef.current.trim();
        if (
          serverVersion
          && shouldForcePwaReload(serverVersion, localVersion)
          && !wasRecentReloadFor(serverVersion)
        ) {
          // نسخهٔ جدید روی سرور است — force reload
          // cache-busting URL تا SW نتواند از کش سرو کند
          isReloading = true;
          markReloadFor(serverVersion);
          void kickServiceWorkerUpdate();
          window.location.href = `/?_=${Date.now()}#${window.location.hash}`;
          return;
        }

        // ۲) بررسی content_hash — اگه عوض شده بود، رویداد emit کن
        const newHash = data.content_hash?.trim();
        if (newHash) {
          if (lastContentHash !== null && lastContentHash !== newHash) {
            // محتوا عوض شده — ویوها باید داده‌ها را refetch کنند
            window.dispatchEvent(new CustomEvent(CONTENT_CHANGED_EVENT));
          }
          lastContentHash = newHash;
        }
      } catch {
        // بی‌صدا رد شو — خطای شبکه نباید کاربر را آزار دهد
      }
    };

    // اولین چک بعد از ۵ ثانیه (تا صفحه کامل لود شود)
    const initialTimer = setTimeout(checkVersion, 5000);
    // سپس هر ۲ دقیقه
    const interval = setInterval(checkVersion, VERSION_CHECK_INTERVAL_MS);

    // وقتی برگشت به tab (visibilitychange) — فوراً چک کن
    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        checkVersion();
      }
    };
    document.addEventListener('visibilitychange', onVisible);

    return () => {
      cancelled = true;
      clearTimeout(initialTimer);
      clearInterval(interval);
      document.removeEventListener('visibilitychange', onVisible);
    };
  }, []);
}
