/**
 * @file hooks/useVersionChecker.ts
 * @description v1.5.4 — بررسی خودکار نسخهٔ PWA و محتوای سرور.
 *
 * ریشهٔ «کاربر نسخهٔ قدیمی می‌بیند»:
 *   - SW قدیمی چسبیده و autoUpdate کار نمی‌کند
 *   - یا LiteSpeed Cache پاسخ‌های API را کش کرده و قیمت‌ها آپدیت نمی‌شوند
 *
 * راه‌حل:
 *   ۱) هر ۲ دقیقه GET /version را صدا می‌زنیم (با cache-busting query param)
 *   ۲) pwa_version و content_hash را با نسخهٔ محلی مقایسه می‌کنیم
 *   ۳) اگه pwa_version عوض شده بود → نسخهٔ جدید PWA مستقر شده → force reload
 *   ۴) اگه content_hash عوض شده بود → کارفرما چیزی در پیشخوان عوض کرده
 *      → یک رویداد سفارشی emit می‌کنیم تا ویوها داده‌ها را refetch کنند
 *      (بدون reload کامل صفحه — فقط refresh داده‌ها)
 */

import { useEffect, useRef } from 'react';
import { API_BASE } from '../services/apiClient';

const VERSION_CHECK_INTERVAL_MS = 2 * 60 * 1000; // ۲ دقیقه
const VERSION_CHECK_TIMEOUT_MS = 8000; // ۸ ثانیه — نباید کاربر را معطل کند

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

        // ۱) بررسی نسخهٔ PWA — اگه عوض شده بود، reload کن
        const serverVersion = data.pwa_version?.trim();
        const localVersion = versionRef.current.trim();
        if (serverVersion && serverVersion !== localVersion) {
          // نسخهٔ جدید روی سرور است — force reload
          // cache-busting URL تا SW نتواند از کش سرو کند
          isReloading = true;
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
