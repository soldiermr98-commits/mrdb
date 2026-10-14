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

/**
 * LOW-SEVERITY FIX: inverse of `toEnglishDigits` — convert 0-9 to ۰-۹ so
 * runtime-formatted dates visually match the Persian-digit seed data.
 */
function toPersianDigits(input: string): string {
  const persian = '۰۱۲۳۴۵۶۷۸۹';
  return input.replace(/[0-9]/g, (d) => persian[Number(d)]);
}

export interface FormatJalaliOptions {
  /**
   * LOW-SEVERITY FIX: default `true` — اعداد خروجی فارسی (۰۱۲۳…) هستند تا با
   * داده‌های اولیه seed هم‌خوان باشند. `false` رفتار قدیمی (لاتین) را می‌دهد.
   */
  persianDigits?: boolean;
}

/**
 * Format an ISO date(-time) as Jalali (Persian calendar). Returns «—» for invalid input.
 * Backward-compatible: existing 1-2 arg calls keep working; digits are Persian by default.
 */
export function formatJalali(
  iso: string | null | undefined,
  withTime = false,
  options?: FormatJalaliOptions
): string {
  const usePersianDigits = options?.persianDigits !== false; // default: Persian
  if (!iso) return '—';
  const d = new Date(iso);
  if (isNaN(d.getTime())) return '—';
  try {
    // `nu-latn` keeps the raw output deterministic across engines; the digit
    // style is applied by our own toPersianDigits below.
    const formatted = new Intl.DateTimeFormat(
      'fa-IR-u-ca-persian-nu-latn',
      withTime
        ? { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }
        : { year: 'numeric', month: '2-digit', day: '2-digit' }
    ).format(d);
    return usePersianDigits ? toPersianDigits(formatted) : formatted;
  } catch {
    // approximate fallback for engines without Persian calendar support
    const yy = d.getFullYear() - 621;
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const base = `${yy}/${mm}/${dd}`;
    if (!withTime) return usePersianDigits ? toPersianDigits(base) : base;
    const hh = String(d.getHours()).padStart(2, '0');
    const mi = String(d.getMinutes()).padStart(2, '0');
    const withHm = `${base} ${hh}:${mi}`;
    return usePersianDigits ? toPersianDigits(withHm) : withHm;
  }
}

/** LOW-SEVERITY FIX: explicit wrapper — formatJalali با ارقام فارسی (هم‌ارز پیش‌فرض فعلی). */
export function formatJalaliPersianDigits(iso: string | null | undefined, withTime = false): string {
  return formatJalali(iso, withTime, { persianDigits: true });
}

/**
 * LOW-SEVERITY FIX: previously invalid/missing input returned 0 which read as
 * «امروز» and masked corrupted dates. It now returns `null` so callers can
 * render «—» and fail visibly. (Views render `null` as empty in JSX; the
 * upcoming UI pass should map null → «—».)
 */
export function daysUntil(iso: string | null | undefined): number | null {
  if (!iso) return null;
  const d = new Date(iso).getTime();
  if (isNaN(d)) return null;
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

/**
 * v1.3.0 — قالب مدت (دقیقهٔ صحیح) به فارسی:
 *  - ۰ / منفی / غیرمتناهی → null (نامشخص؛ caller چیپ را کلاً حذف می‌کند — هرگز «۰ دقیقه»)
 *  - کمتر از ۶۰ دقیقه → «۴۵ دقیقه»
 *  - ۶۰ دقیقه یا بیشتر → «۲ ساعت» یا «۱ ساعت و ۳۰ دقیقه» (دقیقهٔ صفر حذف می‌شود)
 */
export function formatDurationFa(totalMinutes: number | null | undefined): string | null {
  if (typeof totalMinutes !== 'number' || !Number.isFinite(totalMinutes) || totalMinutes <= 0) {
    return null;
  }
  const minutes = Math.round(totalMinutes);
  const faNum = (n: number) => n.toLocaleString('fa-IR');
  if (minutes < 60) return `${faNum(minutes)} دقیقه`;
  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;
  return rest > 0 ? `${faNum(hours)} ساعت و ${faNum(rest)} دقیقه` : `${faNum(hours)} ساعت`;
}
