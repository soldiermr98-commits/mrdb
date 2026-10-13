/**
 * @file utils/scrollLock.ts
 * @description Ref-counted body scroll lock for stacked overlays.
 *
 * BUG-55: previously every modal wrote `document.body.style.overflow` directly.
 * When two overlays were open and the top one unmounted, it restored
 * `overflow: auto` while the lower overlay was still visible — the page behind
 * the remaining modal started scrolling again. This util keeps a module-level
 * reference count: the lock is applied when the count goes 0 → 1 (saving the
 * original inline value) and is only restored when the count returns to 0.
 */

let lockCount = 0;
let originalOverflow: string | null = null;

/**
 * Acquire one level of body scroll-lock. Returns a release function —
 * call it exactly once (it is idempotent, a second call is a no-op).
 */
export function acquireScrollLock(): () => void {
  if (lockCount === 0) {
    originalOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }
  lockCount += 1;

  let released = false;
  return () => {
    if (released) return;
    released = true;
    lockCount = Math.max(0, lockCount - 1);
    if (lockCount === 0) {
      document.body.style.overflow = originalOverflow ?? '';
      originalOverflow = null;
    }
  };
}
