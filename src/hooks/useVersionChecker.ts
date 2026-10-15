/**
 * @file src/hooks/useVersionChecker.ts
 * @description v1.5.4 → v1.5.6 — بررسی خودکار نسخهٔ PWA و محتوای سرور.
 *
 * ریشهٔ «کاربر نسخهٔ قدیمی می‌بیند»:
 *   - SW قدیمی چسبیده و autoUpdate کار نمی‌کند
 *   - یا LiteSpeed Cache پاسخ‌های API را کش کرده و قیمت‌ها آپدیت نمی‌شوند
 *
 * راه‌حل:
 *   ۱) هر ۲ دقیقه GET /version را صدا می‌زنیم (با cache-busting query param)
 *   ۲) pwa_version و content_hash را با نسخهٔ محلی مقایسه می‌کنیم
 *   ۳) اگه pwa_version «جدیدتر» بود → رویداد UPDATE_READY_EVENT منتشر می‌شود و
 *      بنر «نسخهٔ جدید آماده است» با دکمهٔ «بروزرسانی» نمایش داده می‌شود
 *      (UpdateReadyBanner) — v1.5.6: به‌جای reload خاموشِ v1.5.5، کاربر خودش
 *      کلیک می‌کند؛ دقیقاً همان UX نسخه‌های اولیهٔ محصول.
 *   ۴) اگه content_hash عوض شده بود → کارفرما چیزی در پیشخوان عوض کرده
 *      → رویداد CONTENT_CHANGED_EVENT منتشر می‌شود تا ویوها refetch کنند
 *      (بدون reload کامل صفحه — فقط refresh داده‌ها)
 *
 * ─── فیکس v1.5.5 (باگ حلقهٔ reload) ────────────────────────────────────────
 *   چکر v1.5.4 با «!==» مقایسه می‌کرد؛ اگر نسخهٔ سرور «قدیمی‌تر» از باندل
 *   کلاینت بود، کلاینت بی‌نهایت reload می‌شد. اکنون فقط «سرور جدیدتر» →
 *   رویداد/بنر (semver compare — utils/versionCompare).
 *
 * ─── فیکس v1.5.6 (حادثهٔ CORS «ارتباط با سرور برقرار نشد») ─────────────────
 *   - هدر «Pragma: no-cache» از این fetch حذف شد: Pragma در preflight CORS به
 *     Access-Control-Request-Headers می‌رفت و در فهرست allow-headers افزونه
 *     نبود → کل فچ‌های بین‌دامنه‌ای (شامل همین چکر نسخه!) با TypeError شکست
 *     می‌خوردند؛ یعنی مکانیزم آپدیت هم عملاً مرده بود. (پست‌مورتام در CHANGELOG)
 *   - reload خاموش → بنر مرئی با کلیک کاربر (خواستهٔ صریح کارفرما — UX نسخه‌های
 *     اولیه: «نسخه جدید آماده است؛ بروزرسانی»).
 */

import { useEffect, useRef } from 'react';
import { API_BASE } from '../services/apiClient';
import { shouldForcePwaReload } from '../utils/versionCompare';

const VERSION_CHECK_INTERVAL_MS = 2 * 60 * 1000; // ۲ دقیقه
const VERSION_CHECK_TIMEOUT_MS = 8000; // ۸ ثانیه — نباید کاربر را معطل کند

/** کلید sessionStorage برای «کاربر این نسخهٔ سرور را رد کرد» (بنر دیگر نیاید) */
const DISMISS_KEY_PREFIX = 'mrdastbaaf_update_dismissed_';

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

/** v1.5.6 — رویداد «نسخهٔ جدید روی سرور مستقر شده» → UpdateReadyBanner گوش می‌دهد */
export const UPDATE_READY_EVENT = 'mrdastbaaf:update-ready';

export interface UpdateReadyDetail {
  serverVersion: string;
}

let lastContentHash: string | null = null;
let lastEmittedVersion: string | null = null;

/** آیا کاربر بنر این نسخهٔ سرور را بسته است؟ (تا آخرین سشن دیگر مزاحم نشود) */
function wasDismissedByUser(serverVersion: string): boolean {
  try {
    return sessionStorage.getItem(DISMISS_KEY_PREFIX + serverVersion) === '1';
  } catch {
    return false;
  }
}

/** علامت‌گذاری «این نسخه رد شد» — توسط خود بنر صدا زده می‌شود */
export function dismissUpdateBanner(serverVersion: string): void {
  try {
    sessionStorage.setItem(DISMISS_KEY_PREFIX + serverVersion, '1');
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

/**
 * v1.5.6 — اجرای بروزرسانی پس از کلیک کاربر روی «بروزرسانی»:
 * SW را update می‌کند و با URL کش‌باست‌شده reload می‌کند (در همان هش فعلی).
 */
export async function triggerPwaUpdate(): Promise<void> {
  await kickServiceWorkerUpdate();
  window.location.href = `/?_=${Date.now()}#${window.location.hash}`;
}

export function useVersionChecker(localPwaVersion: string): void {
  const versionRef = useRef(localPwaVersion);
  versionRef.current = localPwaVersion;

  useEffect(() => {
    if (!API_BASE) return; // حالت دمو — چک نکن

    let cancelled = false;

    const checkVersion = async () => {
      if (cancelled) return;

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
            // v1.5.6: Pragma حذف شد — ریشهٔ حادثهٔ CORS؛ جزئیات بالای فایل
          },
          signal: controller.signal,
        });

        clearTimeout(timer);

        if (!res.ok) return; // خطا → بی‌صدا رد شو
        const data: ServerVersionInfo = await res.json();

        if (cancelled) return;

        // ۱) بررسی نسخهٔ PWA — فقط وقتی «سرور جدیدتر» است بنر بده (فیکس v1.5.5)
        const serverVersion = data.pwa_version?.trim();
        const localVersion = versionRef.current.trim();
        if (
          serverVersion
          && shouldForcePwaReload(serverVersion, localVersion)
          && lastEmittedVersion !== serverVersion
          && !wasDismissedByUser(serverVersion)
        ) {
          lastEmittedVersion = serverVersion;
          window.dispatchEvent(
            new CustomEvent<UpdateReadyDetail>(UPDATE_READY_EVENT, {
              detail: { serverVersion },
            })
          );
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
