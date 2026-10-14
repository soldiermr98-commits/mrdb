/**
 * @file services/zibalCallback.ts
 * @description تشخیص و پاک‌سازی پارامترهای کال‌بک درگاه زیبال (v1.1.0 — Real-API)
 *
 * زیبال پس از پرداخت، کاربر را به آدرس کال‌بکِ ثبت‌شده برمی‌گرداند. افزونه این آدرس را
 * چنین می‌سازد (class-payment-controller.php):
 *   {frontend_url}/payment/callback?cb_token={cb_token}&orderId={order_id}
 * و زیبال خودش پارامترهای trackId/success/status را اضافه می‌کند.
 *
 * دو حالت پشتیبانی می‌شود:
 *   ۱) مسیر سرور:  https://mrdastbaaf.com/payment/callback?orderId=..&cb_token=..&trackId=..&success=..
 *      (location.search — سرور باید index.html را روی /payment/callback سرو کند)
 *   ۲) مسیر هش:    https://app.example.com/#/payment/callback?orderId=..&cb_token=..
 *      (query بعد از hash — هاست‌هایی که rewrite ندارند)
 *
 * پارامترها بلافاصله پس از تشخیص با history.replaceState پاک می‌شوند تا refresh صفحه
 * هرگز دوباره verify نزند (تایید سمت سرور idempotent است اما تمیزکاری URL نیز لازم است).
 */

export interface ZibalCallbackParams {
  trackId: string;
  /** مقدار خام success زیبال ('1' پرداخت‌شده، '2' قبلاً تاییدشده، ... — سرور تصمیم می‌گیرد) */
  success: string;
  cbToken: string;
  orderId: string;
  source: 'search' | 'hash';
}

const CALLBACK_PATH_SEGMENT = '/payment/callback';

/** اولین مقدار موجود میان چند نام ممکن (زیبال/سرور camelCase و snake_case را هر دو پوشش می‌دهد). */
function firstParam(search: URLSearchParams, keys: readonly string[]): string {
  for (const key of keys) {
    const value = search.get(key);
    if (value && value.trim() !== '') return value.trim();
  }
  return '';
}

/**
 * تشخیص کال‌بک زیبال در URL جاری. اگر cb_token موجود باشد ولی trackId نداشته باشد
 * (کال‌بک ناقص/خراب)، پارامترها پاک می‌شوند و null برمی‌گردد تا حلقهٔ refresh رخ ندهد.
 */
export function detectZibalCallback(): ZibalCallbackParams | null {
  if (typeof window === 'undefined') return null;

  // ۱) مسیر سرور — /payment/callback با query معمولی
  const searchParams = new URLSearchParams(window.location.search);
  const searchCbToken = firstParam(searchParams, ['cb_token']);
  if (window.location.pathname.includes(CALLBACK_PATH_SEGMENT) && searchCbToken) {
    const trackId = firstParam(searchParams, ['trackId', 'track_id']);
    if (!trackId) {
      clearZibalCallbackParams('search');
      return null;
    }
    return {
      trackId,
      success: firstParam(searchParams, ['success']),
      cbToken: searchCbToken,
      orderId: firstParam(searchParams, ['orderId']),
      source: 'search',
    };
  }

  // ۲) مسیر هش — #/payment/callback?orderId=..&cb_token=..
  const hash = window.location.hash || '';
  const queryIndex = hash.indexOf('?');
  if (queryIndex >= 0 && hash.slice(0, queryIndex).includes(CALLBACK_PATH_SEGMENT)) {
    const hashParams = new URLSearchParams(hash.slice(queryIndex + 1));
    const cbToken = firstParam(hashParams, ['cb_token']);
    const trackId = firstParam(hashParams, ['trackId', 'track_id']);
    if (!cbToken) return null;
    if (!trackId) {
      clearZibalCallbackParams('hash');
      return null;
    }
    return {
      trackId,
      success: firstParam(hashParams, ['success']),
      cbToken,
      orderId: firstParam(hashParams, ['orderId']),
      source: 'hash',
    };
  }

  return null;
}

/**
 * پاک‌سازی پارامترهای کال‌بک از URL (history.replaceState — بدون reload و بدون
 * آلودگی تاریخچه) تا refresh صفحه هرگز دوباره verify نزند.
 */
export function clearZibalCallbackParams(source: 'search' | 'hash'): void {
  try {
    if (source === 'search') {
      // مسیر سرور: کل query کال‌بک حذف می‌شود (مسیریابی اپ روی hash است).
      window.history.replaceState(null, '', window.location.pathname);
      return;
    }
    // مسیر هش: هش به ریشه بازمی‌گردد؛ pathname/search دست‌نخورده می‌مانند.
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.search}#/`);
  } catch {
    // برخی مرورگرها replaceState را در شرایط خاص محدود می‌کنند — fatal نیست.
  }
}
