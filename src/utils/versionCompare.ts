/**
 * @file src/utils/versionCompare.ts
 * @description v1.5.5 — مقایسهٔ معنایی نسخه (semver سبک) برای چکر به‌روزرسانی.
 *
 * ریشهٔ باگ v1.5.4 (حلقهٔ reload بی‌نهایت):
 *   چکر قبلی با «!==» مقایسه می‌کرد؛ یعنی وقتی گزینهٔ «نسخهٔ PWA» روی سرور
 *   قدیمی‌تر از باندل کلاینت بود (مثلاً سرور 1.5.3 و باندل 1.5.4)، کلاینت
 *   تشخیص «نسخهٔ جدید» می‌داد و خودش را بی‌نهایت بارگذاری مجدد می‌کرد.
 *   نتیجهٔ ظاهری: قطع‌شدن درخواست‌های نیمه‌کاره (مثل verify-otp) با پیام
 *   «ارتباط با سرور برقرار نشد؛ اتصال اینترنت خود را بررسی کنید» و
 *   «خطای بارگذاری»های پراکنده — در حالی که اینترنت سالم بود.
 *
 * قانون صحیح: فقط وقتی «سرور جدیدتر از باندل فعلی» است باید reload forcé
 *   انجام شود (یعنی باندل جدید واقعاً مستقر شده است). سرورِ قدیمی‌تر یعنی
 *   کارفرما هنوز گزینهٔ نسخه را به‌روز نکرده — در این حالت هیچ کاری نمی‌کنیم
 *   تا حلقهٔ reload شکل نگیرد.
 */

/**
 * مقایسهٔ دو رشتهٔ نسخه به فرمت x.y.z (هر بخش عدد صحیح؛ بخش‌های اضافه/ناقص مجاز).
 * خروجی: -1 یعنی a < b، 0 یعنی برابر، 1 یعنی a > b.
 * ورودی نامعتبر (خالی/غیرعددی) → null (تصمیم‌گیرنده باید محافظه‌کار باشد).
 */
export function compareSemver(a: string, b: string): -1 | 0 | 1 | null {
  const parse = (v: string): number[] | null => {
    if (typeof v !== 'string') return null;
    const trimmed = v.trim();
    if (!/^\d+(\.\d+)*$/.test(trimmed)) return null;
    return trimmed.split('.').map((part) => parseInt(part, 10));
  };

  const pa = parse(a);
  const pb = parse(b);
  if (!pa || !pb) return null;

  const len = Math.max(pa.length, pb.length);
  for (let i = 0; i < len; i++) {
    const na = pa[i] ?? 0;
    const nb = pb[i] ?? 0;
    if (na > nb) return 1;
    if (na < nb) return -1;
  }
  return 0;
}

/**
 * تصمیم نهایی force-reload در useVersionChecker:
 * فقط وقتی سرور «اکیداً جدیدتر» از نسخهٔ باندل فعلی کلاینت است → true.
 * برابر یا قدیمی‌تر یا نامعتبر → false (هیچ‌وقت حلقه نمی‌سازد).
 */
export function shouldForcePwaReload(serverVersion: string, localVersion: string): boolean {
  return compareSemver(serverVersion, localVersion) === 1;
}
