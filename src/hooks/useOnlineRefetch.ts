/**
 * @file hooks/useOnlineRefetch.ts
 * @description v1.3.0 — یک refetch بی‌صدا پس از بازگشت اتصال («خطای اتصال» گاه‌به‌گاه)
 *
 * App.tsx رویداد استاندارد «online» مرورگر را می‌شنود و رویداد داخلی
 * mrdastbaaf:online-refetch را یک بار dispatch می‌کند. هر نما که آخرین بارگذاری‌اش
 * با خطا روبه‌رو شده (loadError) با این هوک به همان رویداد گوش می‌دهد و لودر خودش
 * را صدا می‌زند — بدون پیام/اسپینر اضافه و فقط یک بار تا خطای بعدی.
 *
 * v1.5.4: گزینهٔ forceRefetch اضافه شد. وقتی محتوا در پیشخوان عوض می‌شود
 * (content_hash تغییر می‌کند)، می‌خواهیم ویوها حتی بدون خطا داده‌ها را refetch
 * کنند. در این حالت، forceRefetch=true پاس می‌دهیم تا بدون توجه به loadError،
 * refetch انجام شود.
 */

import { useEffect, useRef } from 'react';

/** رویداد داخلی اپ — App.tsx روی «online» مرورگر آن را ارسال می‌کند. */
export const ONLINE_REFETCH_EVENT = 'mrdastbaaf:online-refetch';

/**
 * وقتی loadError فعال است به رویداد refetch گوش می‌دهد؛ عمداً بدون پارامتر اضافه
 * (avoid over-engineering) — loader همیشه از آخرین رندر (fresh closure) خوانده می‌شود.
 *
 * v1.5.4: گزینهٔ forceRefetch اضافه شد. وقتی محتوا در پیشخوان عوض می‌شود
 * (content_hash تغییر می‌کند)، می‌خواهیم ویوها حتی بدون خطا داده‌ها را refetch
 * کنند. در این حالت، forceRefetch=true پاس می‌دهیم تا بدون توجه به loadError،
 * refetch انجام شود.
 *
 * v1.5.4 (اصلاح نهایی): دو رویداد جدا داریم:
 *   - ONLINE_REFETCH_EVENT: فقط وقتی loadError فعال است (رفتار v1.3.0 دست‌نخورده)
 *   - CONTENT_CHANGED_EVENT: همیشه فعال (حتی بدون خطا) — وقتی کارفرما چیزی در
 *     پیشخوان ذخیره می‌کند، همه ویوها داده‌ها را refetch می‌کنند.
 * این طراحی جلوی refresh بی‌دلیل (وقتی فقط «online» می‌شود) را می‌گیرد، ولی
 * تغییرات محتوای پیشخوان را فوراً اعمال می‌کند.
 */
import { CONTENT_CHANGED_EVENT } from './useVersionChecker';

export function useOnlineRefetch(
  loadError: boolean,
  refetch: () => void
): void {
  const refetchRef = useRef(refetch);
  refetchRef.current = refetch;

  // ۱) ONLINE_REFETCH_EVENT: فقط وقتی loadError فعال است
  useEffect(() => {
    if (!loadError) return;
    const handler = () => refetchRef.current();
    window.addEventListener(ONLINE_REFETCH_EVENT, handler);
    return () => window.removeEventListener(ONLINE_REFETCH_EVENT, handler);
  }, [loadError]);

  // ۲) CONTENT_CHANGED_EVENT: همیشه فعال — وقتی محتوا در پیشخوان عوض می‌شود
  useEffect(() => {
    const handler = () => refetchRef.current();
    window.addEventListener(CONTENT_CHANGED_EVENT, handler);
    return () => window.removeEventListener(CONTENT_CHANGED_EVENT, handler);
  }, []);
}
