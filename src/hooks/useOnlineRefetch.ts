/**
 * @file hooks/useOnlineRefetch.ts
 * @description v1.3.0 — یک refetch بی‌صدا پس از بازگشت اتصال («خطای اتصال» گاه‌به‌گاه)
 *
 * App.tsx رویداد استاندارد «online» مرورگر را می‌شنود و رویداد داخلی
 * mrdastbaaf:online-refetch را یک بار dispatch می‌کند. هر نما که آخرین بارگذاری‌اش
 * با خطا روبه‌رو شده (loadError) با این هوک به همان رویداد گوش می‌دهد و لودر خودش
 * را صدا می‌زند — بدون پیام/اسپینر اضافه و فقط یک بار تا خطای بعدی.
 */

import { useEffect, useRef } from 'react';

/** رویداد داخلی اپ — App.tsx روی «online» مرورگر آن را ارسال می‌کند. */
export const ONLINE_REFETCH_EVENT = 'mrdastbaaf:online-refetch';

/**
 * وقتی loadError فعال است به رویداد refetch گوش می‌دهد؛ عمداً بدون پارامتر اضافه
 * (avoid over-engineering) — loader همیشه از آخرین رندر (fresh closure) خوانده می‌شود.
 */
export function useOnlineRefetch(loadError: boolean, refetch: () => void): void {
  const refetchRef = useRef(refetch);
  refetchRef.current = refetch;

  useEffect(() => {
    if (!loadError) return;
    const handler = () => refetchRef.current();
    window.addEventListener(ONLINE_REFETCH_EVENT, handler);
    return () => window.removeEventListener(ONLINE_REFETCH_EVENT, handler);
  }, [loadError]);
}
