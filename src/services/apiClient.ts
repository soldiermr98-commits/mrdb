/**
 * @file services/apiClient.ts
 * @description کلاینت HTTP حالت Real-API برای پل وردپرس «مستر دستباف» (v1.1.0)
 *
 * قرارداد فریز‌شده (همگام با wordpress-plugin):
 *  - Base: VITE_API_BASE_URL (مثال: https://mrdastbaaf.com/wp-json/mrdastbaaf/v1)
 *  - خطاها با شکل WP برمی‌گردند: {code, message, data:{status}} — message فارسی به کاربر نشان داده می‌شود.
 *  - احراز هویت: توکن opaque در هدر «Authorization: Bearer <token>» (کوکی لازم نیست → credentials:'omit').
 *
 * حالت Dual-Mode: اگر VITE_API_BASE_URL تنظیم نشده باشد، isRealApiMode برابر false است
 * و تمام سرویس‌ها همان مسیر دموی mock قبلی را اجرا می‌کنند (رفتار v1.0.3 دست‌نخورده).
 */

/**
 * خواندن امن متغیرهای Vite env — در مرورگر همان import.meta.env جایگزین‌شدهٔ استاتیک است؛
 * در رانتایم‌های بدون Vite (تست/Node) undefined برمی‌گردد تا حالت دمو فعال بماند.
 */
function readViteEnv(key: string): string | undefined {
  const env: Record<string, string | undefined> | undefined = import.meta.env;
  if (!env) return undefined;
  const value = env[key];
  return typeof value === 'string' ? value : undefined;
}

const RAW_API_BASE = (readViteEnv('VITE_API_BASE_URL') ?? '').trim();

/** Base URL بدون اسلش انتهایی — خالی یعنی حالت دمو (mock). */
export const API_BASE = RAW_API_BASE.replace(/\/+$/, '');

/** فقط وقتی true است که VITE_API_BASE_URL ست شده باشد؛ در غیر این صورت دمو کامل mock اجرا می‌شود. */
export const isRealApiMode = API_BASE !== '';

/**
 * کلید توکن — دقیقاً همان STORAGE_KEY_TOKEN سرویس authService است تا سشن دمو/Real
 * روی یک کلید مشترک بنشیند. (apiClient عمداً authService را import نمی‌کند تا چرخه وابستگی رخ ندهد.)
 */
const TOKEN_STORAGE_KEY = 'mrdastbaaf_auth_token';

export function getStoredAuthToken(): string | null {
  try {
    const token = localStorage.getItem(TOKEN_STORAGE_KEY);
    return token && token.trim() !== '' ? token : null;
  } catch {
    return null;
  }
}

export function setStoredAuthToken(token: string): void {
  try {
    localStorage.setItem(TOKEN_STORAGE_KEY, token);
  } catch {
    // localStorage در دسترس نیست — سشن درون‌پردازه‌ای authService باقی می‌ماند
  }
}

export function clearStoredAuthToken(): void {
  try {
    localStorage.removeItem(TOKEN_STORAGE_KEY);
  } catch {
    // ignore
  }
}

/**
 * Hardening (prelive 18-b): سقف زمان انتظار پاسخ سرور. هر درخواست با AbortController
 * پس از این مدت لغو می‌شود تا UI هرگز روی درخواست معلق گیر نکند.
 */
export const API_TIMEOUT_MS = 20000;

/** خطای تایپ‌دار HTTP با وضعیت — برای تفکیک 401/404 از بقیه خطاها در callers. */
export class ApiError extends Error {
  readonly status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

export interface ApiFetchOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  /** بدنهٔ JSON — در صورت داده شدن، Content-Type: application/json ست می‌شود. */
  body?: unknown;
  /** در صورت true، هدر Bearer از توکن ذخیره‌شده خوانده می‌شود. */
  auth?: boolean;
  /** سقف زمان انتظار پاسخ بر حسب میلی‌ثانیه — پیش‌فرض API_TIMEOUT_MS. */
  timeoutMs?: number;
}

interface WpErrorPayload {
  code?: unknown;
  message?: unknown;
  data?: { status?: unknown };
}

/** پیام‌های فارسی جایگزین برای پاسخ‌های بدون message قابل خواندن. */
const FALLBACK_STATUS_MESSAGES: Record<number, string> = {
  400: 'درخواست ارسال‌شده معتبر نیست.',
  401: 'جلسه شما منقضی شده است؛ لطفاً دوباره وارد شوید.',
  403: 'دسترسی به این بخش برای حساب شما مجاز نیست.',
  404: 'اطلاعات درخواستی در سرور یافت نشد.',
  409: 'این درخواست با وضعیت فعلی سیستم تداخل دارد.',
  429: 'تعداد درخواست‌ها بیش از حد مجاز است؛ کمی بعد دوباره تلاش کنید.',
  500: 'خطای داخلی سرور؛ لطفاً بعداً دوباره تلاش کنید.',
  502: 'سرور میانی پاسخ معتبری دریافت نکرد؛ لطفاً بعداً دوباره تلاش کنید.',
  // v1.3.0: وضعیت‌های گذرای retryشونده — پیام نهایی پس از اتمام تلاش‌ها
  503: 'سرور در حال حاضر در دسترس نیست؛ لطفاً کمی بعد دوباره تلاش کنید.',
  504: 'پاسخ سرور دیر رسید؛ لطفاً دوباره تلاش کنید.',
};

function extractStatus(payload: WpErrorPayload, httpResponseStatus: number): number {
  if (payload && typeof payload === 'object') {
    const nested = payload.data;
    if (nested && typeof nested === 'object' && typeof nested.status === 'number') {
      return nested.status;
    }
  }
  return httpResponseStatus;
}

function extractErrorMessage(payload: unknown, httpResponseStatus: number): string {
  if (payload && typeof payload === 'object') {
    const maybeMessage = (payload as WpErrorPayload).message;
    if (typeof maybeMessage === 'string' && maybeMessage.trim() !== '') {
      return maybeMessage;
    }
  }
  return FALLBACK_STATUS_MESSAGES[httpResponseStatus] ?? 'خطای غیرمنتظره در ارتباط با سرور رخ داد.';
}

/**
 * Hardening (prelive 18-b): بدنهٔ خالی/JSON نامعتبر دیگر هرگز «null بی‌صدا» برنمی‌گرداند؛
 * caller همیشه یا دادهٔ معتبر می‌گیرد یا Error فارسی — پاسخ ok اما خراب، خطای صریح است.
 */
async function parseJsonBodyOrThrow(response: Response): Promise<unknown> {
  const text = await response.text();
  if (text.trim() === '') {
    throw new Error('پاسخ سرور نامعتبر بود؛ لطفاً دوباره تلاش کنید.');
  }
  try {
    return JSON.parse(text) as unknown;
  } catch {
    throw new Error('پاسخ سرور نامعتبر بود؛ لطفاً دوباره تلاش کنید.');
  }
}

/**
 * v1.3.0 — تلاش مجدد شفاف فقط برای GET («خطای اتصال گاه‌به‌گاه»):
 *  - حداکثر ۲ تلاش اضافه (۳ تلاش کل) با backoff خطی ۶۰۰ms سپس ۱۲۰۰ms.
 *  - فقط خطاهای گذرا: شکست شبکه / abort-timeout / HTTP 502,503,504.
 *  - هرگز 4xx تکرار نمی‌شود و پس از شروع خواندن بدنه (پارس JSON) هم نه —
 *    پاسخ خراب «پاسخ سرور نامعتبر» خطای نهایی است، نه تریگر retry.
 *  - سقف زمان هر تلاش همان timeoutMs (پیش‌فرض API_TIMEOUT_MS) است؛ کل زمان bounded.
 */
const TRANSIENT_HTTP_STATUSES: ReadonlySet<number> = new Set([502, 503, 504]);
const GET_RETRY_DELAYS_MS: readonly number[] = [600, 1200];

/**
 * خطای داخلی گذرا — فقط علامت «قابل retry بودن» است؛ در اتمام تلاش‌ها
 * همیشه finalError (همان خطای فارسیِ مصرف‌کننده) پرتاب می‌شود.
 */
class TransientRequestError extends Error {
  readonly finalError: Error;

  constructor(finalError: Error) {
    super(finalError.message);
    this.name = 'TransientRequestError';
    this.finalError = finalError;
  }
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** یک تلاش کامل درخواست — یا داده برمی‌گرداند یا TransientRequestError (گذرا) یا Error نهایی پرتاب می‌کند. */
async function requestOnce<T>(
  url: string,
  method: 'GET' | 'POST' | 'PUT' | 'DELETE',
  headers: Record<string, string>,
  body: unknown,
  timeoutMs: number,
  withAuth: boolean
): Promise<T> {
  // Hardening: AbortController با سقف زمانی — خطای timeout از خطای شبکه تفکیک می‌شود.
  const controller = new AbortController();
  const abortTimer = setTimeout(() => controller.abort(), timeoutMs);

  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      // توکن opaque در هدر است؛ کوکی/سشن مرورگر لازم نیست.
      credentials: 'omit',
      signal: controller.signal,
    });
  } catch (e) {
    clearTimeout(abortTimer);
    if (e instanceof DOMException && e.name === 'AbortError') {
      throw new TransientRequestError(
        new Error('زمان انتظار پاسخ سرور به پایان رسید؛ اتصال خود را بررسی کنید.')
      );
    }
    throw new TransientRequestError(
      new Error('ارتباط با سرور برقرار نشد؛ اتصال اینترنت خود را بررسی کنید.')
    );
  }
  clearTimeout(abortTimer);

  // v1.3.0: 502/503/504 گذرا هستند — پیش از خواندن بدنه به‌عنوان retryable علامت می‌خورند.
  // (پس از شروع خواندن بدنه دیگر retry در کار نیست؛ این چک همان مرز تمیز است.)
  if (TRANSIENT_HTTP_STATUSES.has(response.status)) {
    throw new TransientRequestError(
      new ApiError(
        FALLBACK_STATUS_MESSAGES[response.status] ??
          'خطای غیرمنتظره در ارتباط با سرور رخ داد.',
        response.status
      )
    );
  }

  const payload = await parseJsonBodyOrThrow(response);

  if (!response.ok) {
    const status = extractStatus((payload ?? {}) as WpErrorPayload, response.status);
    if (response.status === 401 || status === 401) {
      if (withAuth) {
        // جلسه منقضی/نامعتبر در درخواست auth-دار — توکن محلی باید پاک شود تا
        // کاربر دوباره وارد شود (apiClient خودش پیام استاندارد می‌دهد).
        clearStoredAuthToken();
        throw new ApiError('جلسه شما منقضی شده است؛ لطفاً دوباره وارد شوید.', 401);
      }
      // 401 روی درخواست غیر-auth — پیام سرور (در صورت وجود) مستقیم پاس می‌شود و
      // توکن محلی دست‌نخورده می‌ماند (مثلاً خطای احراز verify-otp/کال‌بک).
      throw new ApiError(extractErrorMessage(payload, response.status), 401);
    }
    throw new ApiError(extractErrorMessage(payload, response.status), status);
  }

  return payload as T;
}

/**
 * درخواست JSON به پل وردپرس. در خطا همیشه Error با پیام فارسی پرتاب می‌شود.
 *
 * Hardening (prelive 18-b) — معناشناسی 401:
 *  - فقط وقتی options.auth === true باشد، 401 یعنی «جلسه منقضی» → توکن پاک و
 *    پیام «جلسه شما منقضی شده است» داده می‌شود.
 *  - درخواست‌های غیر-auth که 401 می‌گیرند (مثلاً لبهٔ verify-otp یا کال‌بک)
 *    پیام فارسی سرور را با status 401 پاس می‌دهند و توکن را دست نمی‌زنند —
 *    کد invalid_otp از v1.2.0 با 400 می‌آید، پس این مسیر فقط 401 واقعی است.
 *
 * v1.3.0 — retry شفاف فقط برای GET روی خطاهای گذرا (شبکه/timeout/502/503/504)
 * با backoff ۶۰۰/۱۲۰۰ms — سایر متدها و 4xx هرگز تکرار نمی‌شوند.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const { method = 'GET', body, auth = false } = options;
  const timeoutMs =
    typeof options.timeoutMs === 'number' && Number.isFinite(options.timeoutMs) && options.timeoutMs > 0
      ? options.timeoutMs
      : API_TIMEOUT_MS;

  const url = `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth) {
    const token = getStoredAuthToken();
    if (!token) {
      throw new ApiError('برای این عملیات ابتدا باید وارد حساب کاربری خود شوید.', 401);
    }
    headers.Authorization = `Bearer ${token}`;
  }

  const maxAttempts = method === 'GET' ? 1 + GET_RETRY_DELAYS_MS.length : 1;

  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    try {
      return await requestOnce<T>(url, method, headers, body, timeoutMs, auth);
    } catch (e) {
      // خطای گذرا روی آخرین تلاش یا هر خطای غیرگذرا → همان خطای نهایی کاربر.
      if (!(e instanceof TransientRequestError) || attempt >= maxAttempts) {
        throw e instanceof TransientRequestError ? e.finalError : e;
      }
      await delay(GET_RETRY_DELAYS_MS[attempt - 1]);
    }
  }

  // unreachable (حلقه همیشه return/throw می‌کند) — برای راضی‌نگه‌داشتن تایپ‌چکر
  throw new Error('ارتباط با سرور برقرار نشد؛ اتصال اینترنت خود را بررسی کنید.');
}
