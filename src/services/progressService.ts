/**
 * @file services/progressService.ts
 * @description همگام‌سازی پیشرفت یادگیری (تکمیل درس‌ها / نشان‌شده‌ها) با سرور — v1.2.0 (prelive 18-b)
 *
 * قرارداد افزونهٔ v1.2.0 (فریز‌شده):
 *  - GET  /me/progress (Bearer) → {completedLessonIds: string[], bookmarkedLessonIds: string[]}
 *  - PUT  /me/progress (Bearer) بدنهٔ {completedLessonIds?, bookmarkedLessonIds?} →
 *         همان شکل برمی‌گردد (معناشناسی replace؛ سرور سقف ۵۰۰ آیتم دارد).
 *
 * رفتار:
 *  - فقط در حالت Real-API فعال است؛ در دمو همهٔ متدها no-op فوری‌اند (رفتار v1.0.3).
 *  - pushProgress(partial): صف درون‌حافظه + debounce ۱۵۰۰ms به‌ازای هر نوع کلید؛
 *    آرایهٔ کامل از پروفایل کاربر فعلی خوانده می‌شود (قرارداد replace) و
 *    fire-and-forget ارسال می‌شود — شکست شبکه/سرور بی‌صدا نادیده گرفته می‌شود
 *    (پیشرفت محلی از قبل ثبت شده و در لاگین بعدی با syncProgressOnLogin یکپارچه می‌شود).
 *  - syncProgressOnLogin(): GET پیشرفت سرور + UNION order-stable با آرایه‌های سشن
 *    محلی → به‌روزرسانی سشن با authService.updateCurrentUser و یک push اولیه.
 *    هرگز throw نمی‌کند (try/catch کامل).
 */

import { apiFetch, isRealApiMode } from './apiClient';
import { authService } from './authService';

/** تاخیر debounce برای هر نوع کلید پیشرفت (ms). */
const PROGRESS_DEBOUNCE_MS = 1500;

type ProgressKey = 'completedLessonIds' | 'bookmarkedLessonIds';

interface ProgressPayloadShape {
  completedLessonIds?: unknown;
  bookmarkedLessonIds?: unknown;
}

/** آرایهٔ string تمیزشده — مقادیر غیررشته‌ای دور ریخته می‌شوند. */
function asStringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}

/**
 * UNION order-stable دو آرایه: ترتیب از «پایه» حفظ می‌شود؛ آیتم‌های جدیدِ
 * «incoming» به ترتیب خودشان به انتها اضافه می‌شوند. داپلیکیت کامل حذف می‌شود.
 */
function unionStable(base: readonly string[], incoming: readonly string[]): string[] {
  const seen = new Set(base);
  const merged: string[] = [...base];
  for (const item of incoming) {
    if (!seen.has(item)) {
      seen.add(item);
      merged.push(item);
    }
  }
  return merged;
}

/** خواندن آرایهٔ پیشرفت از پروفایل کاربر فعلی — منبع کامل‌سازی بدنهٔ PUT. */
function currentUserProgress(key: ProgressKey): string[] {
  const user = authService.getCurrentUser();
  const value = user ? user[key] : undefined;
  return Array.isArray(value) ? value : [];
}

/** صف debounce — به‌ازای هر کلید فقط یک تایمر فعال وجود دارد. */
const pendingTimers: Partial<Record<ProgressKey, ReturnType<typeof setTimeout>>> = {};
/** آخرین payload صف‌شده برای هر کلید (آخرین نوشتن برنده است). */
const pendingPayload: Partial<Record<ProgressKey, string[]>> = {};

/** ارسال واقعی PUT /me/progress با هر دو آرایهٔ کامل (قرارداد replace). */
function flushProgress(keys: ProgressKey[]): void {
  if (!isRealApiMode) return;
  if (!authService.getCurrentUser()) return;

  const body: { completedLessonIds?: string[]; bookmarkedLessonIds?: string[] } = {};
  for (const key of keys) {
    body[key] = pendingPayload[key] ?? currentUserProgress(key);
    delete pendingPayload[key];
  }

  // Fire-and-forget: شکست ارسال هرگز UI را نمی‌شکند و لاگ تولید نمی‌کند —
  // دادهٔ سروری در لاگین/سینک بعدی از طریق syncProgressOnLogin یکپارچه می‌شود.
  void apiFetch<ProgressPayloadShape>('/me/progress', {
    method: 'PUT',
    auth: true,
    body,
  }).catch(() => {
    // silent ignore — وضعیت محلی معتبر باقی می‌ماند.
  });
}

export const progressService = {
  /**
   * صف‌کردن به‌روزرسانی پیشرفت (Real فقط): آرایهٔ کاملِ همان کلید از پروفایل
   * فعلی خوانده و پس از debounce ۱۵۰۰ms یک‌جا PUT می‌شود (de-dup کلیک‌های سریع).
   * در دمو فوراً no-op است.
   */
  pushProgress(partial: { completedLessonIds?: string[]; bookmarkedLessonIds?: string[] }): void {
    if (!isRealApiMode) return;

    const keys: ProgressKey[] = [];
    if (partial.completedLessonIds !== undefined) {
      pendingPayload.completedLessonIds = partial.completedLessonIds;
      keys.push('completedLessonIds');
    }
    if (partial.bookmarkedLessonIds !== undefined) {
      pendingPayload.bookmarkedLessonIds = partial.bookmarkedLessonIds;
      keys.push('bookmarkedLessonIds');
    }
    if (keys.length === 0) return;

    for (const key of keys) {
      const existing = pendingTimers[key];
      if (existing) clearTimeout(existing);
      pendingTimers[key] = setTimeout(() => {
        delete pendingTimers[key];
        flushProgress([key]);
      }, PROGRESS_DEBOUNCE_MS);
    }
  },

  /**
   * همگام‌سازی پیشرفت پس از لاگین/سینک سشن (Real فقط):
   * GET /me/progress → UNION با آرایه‌های سشن محلی (ترتیب‌پایدار) →
   * به‌روزرسانی سشن با updateCurrentUser و یک push اولیهٔ آرایهٔ یکپارچه.
   * هرگز throw نمی‌کند؛ در دمو فوراً null برمی‌گرداند.
   */
  async syncProgressOnLogin(): Promise<void> {
    if (!isRealApiMode) return;
    try {
      if (!authService.getCurrentUser()) return;

      const remote = await apiFetch<ProgressPayloadShape>('/me/progress', { auth: true });

      const user = authService.getCurrentUser();
      if (!user) return;

      const mergedCompleted = unionStable(
        Array.isArray(user.completedLessonIds) ? user.completedLessonIds : [],
        asStringArray(remote.completedLessonIds)
      );
      const mergedBookmarked = unionStable(
        Array.isArray(user.bookmarkedLessonIds) ? user.bookmarkedLessonIds : [],
        asStringArray(remote.bookmarkedLessonIds)
      );

      // سشن + رجیستری محلی با اتحاد سرور/محلی تازه می‌شود (سرویس authService).
      authService.updateCurrentUser({
        completedLessonIds: mergedCompleted,
        bookmarkedLessonIds: mergedBookmarked,
      });

      // یک push یکپارچه تا سرور هم آیتم‌های محلیِ فقط-محلی را بگیرد (idempotent).
      this.pushProgress({
        completedLessonIds: mergedCompleted,
        bookmarkedLessonIds: mergedBookmarked,
      });
    } catch {
      // پیشرفت هرگز جریان لاگین را نمی‌شکند — شکست سینک بی‌صدا نادیده گرفته می‌شود.
    }
  },
};
