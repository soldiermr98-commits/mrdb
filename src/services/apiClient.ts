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

async function parseJsonBody(response: Response): Promise<unknown> {
  const text = await response.text();
  if (text.trim() === '') return null;
  try {
    return JSON.parse(text) as unknown;
  } catch {
    return null;
  }
}

/**
 * درخواست JSON به پل وردپرس. در خطا همیشه Error با پیام فارسی پرتاب می‌شود؛
 * پاسخ 401 توکن ذخیره‌شده را پاک می‌کند و خطای «جلسه منقضی شد» می‌دهد.
 */
export async function apiFetch<T>(path: string, options: ApiFetchOptions = {}): Promise<T> {
  const { method = 'GET', body, auth = false } = options;

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

  let response: Response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      // توکن opaque در هدر است؛ کوکی/سشن مرورگر لازم نیست.
      credentials: 'omit',
    });
  } catch {
    throw new Error('ارتباط با سرور برقرار نشد؛ اتصال اینترنت خود را بررسی کنید.');
  }

  const payload = await parseJsonBody(response);

  if (!response.ok) {
    const status = extractStatus((payload ?? {}) as WpErrorPayload, response.status);
    if (response.status === 401 || status === 401) {
      // جلسه منقضی/نامعتبر — توکن محلی باید پاک شود تا کاربر دوباره وارد شود.
      clearStoredAuthToken();
      throw new ApiError('جلسه شما منقضی شده است؛ لطفاً دوباره وارد شوید.', 401);
    }
    throw new ApiError(extractErrorMessage(payload, response.status), status);
  }

  return payload as T;
}
