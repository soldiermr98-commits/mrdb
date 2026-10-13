/**
 * @file utils/format.ts
 * @description Shared formatting & date utilities.
 *
 * BUG-23/BUG-24 (Phase 2): previously every consumer rolled its own Jalali
 * conversion (or stored fake placeholders like «هم‌اکنون» / «امروز» on records).
 * One shared implementation now guarantees:
 *  - real Gregorian→Jalali conversion (Intl Persian calendar) with a safe fallback
 *  - live "days remaining" instead of values frozen at purchase time
 *  - Persian→English digit normalization for form validation
 */

/** Convert Persian/Arabic digits to English digits (for input validation). */
export function toEnglishDigits(input: string): string {
  if (!input) return '';
  const persian = '۰۱۲۳۴۵۶۷۸۹';
  const arabic = '٠١٢٣٤٥٦٧٨٩';
  return input.replace(/[۰-۹٠-٩]/g, (ch) => {
    const p = persian.indexOf(ch);
    if (p > -1) return String(p);
    const a = arabic.indexOf(ch);
    return a > -1 ? String(a) : ch;
  });
}

/** Format an ISO date(-time) as Jalali (Persian calendar). Returns «—» for invalid input. */
export function formatJalali(iso: string | null | undefined, withTime = false): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  try {
    return new Intl.DateTimeFormat(
      'fa-IR-u-ca-persian-nu-latn',
      withTime
        ? { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }
        : { year: 'numeric', month: '2-digit', day: '2-digit' }
    ).format(d);
  } catch {
    // approximate fallback for engines without Persian calendar support
    const yy = d.getFullYear() - 621;
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const base = `${yy}/${mm}/${dd}`;
    if (!withTime) return base;
    const hh = String(d.getHours()).padStart(2, '0');
    const mi = String(d.getMinutes()).padStart(2, '0');
    return `${base} ${hh}:${mi}`;
  }
}

/** Whole days between now and the given ISO date (negative = in the past). */
export function daysUntil(iso: string | null | undefined): number {
  if (!iso) return 0;
  const d = new Date(iso).getTime();
  if (isNaN(d)) return 0;
  return Math.ceil((d - Date.now()) / (1000 * 60 * 60 * 24));
}

/** True when the ISO date is still in the future. */
export function isLive(iso: string | null | undefined): boolean {
  if (!iso) return false;
  const t = new Date(iso).getTime();
  return !isNaN(t) && t > Date.now();
}

/** Format a Toman price with Persian locale digits. */
export function formatToman(value: number): string {
  return Number.isFinite(value) ? value.toLocaleString('fa-IR') : '—';
}
