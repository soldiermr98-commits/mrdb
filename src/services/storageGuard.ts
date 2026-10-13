/**
 * @file services/storageGuard.ts
 * @description BUG-29: لایه دفاعی خواندن آرایه‌ها از localStorage.
 *
 * قبلاً هر سرویس JSON.parse خام انجام می‌داد؛ داده خراب (غیر-JSON یا غیرآرایه)
 * یا ردیف‌های ناقص باعث کرش اپ در بوت می‌شد. این گارد:
 *  - خطای JSON.parse و ساختار غیرآرایه‌ای را می‌گیرد،
 *  - کلید خراب را با نوشتن fallback «ترمیم» (heal) می‌کند،
 *  - در صورت ارائه validate، ردیف‌های نامعتبر را قرنطینه (console.warn) می‌کند.
 */

/**
 * Read an array from localStorage without ever crashing the app.
 *
 * @param key       localStorage key
 * @param fallback  seed/default array returned (and written back) on corruption or absence
 * @param validate  optional per-item validator (e.g. require a string `id`)
 */
export function readJsonArray<T>(
  key: string,
  fallback: T[],
  validate?: (item: any) => boolean
): T[] {
  let parsed: unknown;
  try {
    const saved = localStorage.getItem(key);
    if (!saved) return [...fallback];
    parsed = JSON.parse(saved);
  } catch (e) {
    // BUG-29: corrupted JSON — heal the key with the fallback below.
    healKey(key, fallback);
    return [...fallback];
  }

  if (!Array.isArray(parsed)) {
    // BUG-29: wrong shape (e.g. an object written by an older build) — heal.
    healKey(key, fallback);
    return [...fallback];
  }

  if (!validate) return parsed as T[];

  // BUG-29: quarantine malformed rows instead of crashing on them later.
  return parsed.filter((item: any) => {
    if (validate(item)) return true;
    console.warn(`[storageGuard] ردیف نامعتبر در کلید «${key}» قرنطینه شد:`, item);
    return false;
  }) as T[];
}

/** BUG-29: heal a corrupted key by writing the fallback seed back. */
function healKey(key: string, fallback: unknown[]): void {
  try {
    localStorage.setItem(key, JSON.stringify(fallback));
  } catch (e) {
    // localStorage unavailable (private mode / quota) — nothing else to do here;
    // callers keep working with the in-memory fallback.
  }
}

/** BUG-29: shared per-item validator — every stored record must carry a string `id`. */
export function hasStringId(item: any): boolean {
  return typeof item?.id === 'string' && item.id.length > 0;
}
