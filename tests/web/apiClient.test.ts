/**
 * Hardening (prelive 18-b — یافتهٔ 2-13): تست‌های apiClient.
 *
 * پوشش:
 *  - 401 روی درخواست auth-دار → پاک شدن توکن + پیام فارسی «جلسه منقضی»
 *  - 401 روی درخواست غیر-auth → توکن دست‌نخورده + پیام سرور پاس می‌شود
 *  - پاسخ ok اما JSON نامعتبر/خالی → Error فارسی (هرگز null بی‌صدا)
 *  - بدنهٔ خطای WP {code,message,data:{status}} → ApiError با message+status
 *  - مسیر timeout با AbortController (fake timers) → پیام فارسی timeout
 *  - خطای شبکه (fetch reject) → پیام فارسی اتصال
 *
 * import.meta.env با vi.stubEnv «قبل از» import دینامیک ست می‌شود و
 * localStorage با شیم درون‌حافظه‌ای جایگزین می‌شود.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type ApiClientModule = typeof import('../../src/services/apiClient');

const TOKEN_KEY = 'mrdastbaaf_auth_token';

function createStorageShim() {
  const store = new Map<string, string>();
  return {
    getItem: (key: string): string | null => (store.has(key) ? (store.get(key) as string) : null),
    setItem: (key: string, value: string): void => {
      store.set(key, String(value));
    },
    removeItem: (key: string): void => {
      store.delete(key);
    },
    has: (key: string): boolean => store.has(key),
  };
}

let storage: ReturnType<typeof createStorageShim>;
const fetchMock = vi.fn();

beforeEach(() => {
  vi.resetModules();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  storage = createStorageShim();
  vi.stubGlobal('localStorage', storage);
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
});

afterEach(() => {
  vi.useRealTimers();
});

/** ماژول apiClient را در حالت Real (با env) دوباره لود می‌کند تا RAW_API_BASE گرفته شود. */
async function loadRealClient(): Promise<ApiClientModule> {
  vi.stubEnv('VITE_API_BASE_URL', 'https://x.example/wp-json/mrdastbaaf/v1');
  return (await import('../../src/services/apiClient')) as ApiClientModule;
}

function rawResponse(status: number, bodyText: string, ok?: boolean) {
  return {
    ok: ok ?? (status >= 200 && status < 300),
    status,
    text: async () => bodyText,
  };
}

describe('apiClient hardening', () => {
  it('401 on an auth request clears the stored token and throws the Persian session message', async () => {
    storage.setItem(TOKEN_KEY, 'tok-1');
    fetchMock.mockResolvedValueOnce(
      rawResponse(401, JSON.stringify({ code: 'rest_invalid', message: 'توکن نامعتبر است', data: { status: 401 } }))
    );
    const client = await loadRealClient();

    const err = await client.apiFetch('/auth/me', { auth: true }).catch((e: unknown) => e);

    expect(err).toBeInstanceOf(client.ApiError);
    const apiErr = err as InstanceType<ApiClientModule['ApiError']>;
    expect(apiErr.status).toBe(401);
    expect(apiErr.message).toBe('جلسه شما منقضی شده است؛ لطفاً دوباره وارد شوید.');
    // توکن باید پاک شده باشد.
    expect(storage.getItem(TOKEN_KEY)).toBeNull();
    // هدر Bearer + credentials:'omit' عیناً ارسال شده‌اند.
    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer tok-1');
    expect(init.credentials).toBe('omit');
  });

  it('401 on a non-auth request keeps the token and passes the server message through', async () => {
    storage.setItem(TOKEN_KEY, 'tok-keep');
    fetchMock.mockResolvedValueOnce(
      rawResponse(401, JSON.stringify({ code: 'auth_failed', message: 'کد ارسالی نامعتبر است.', data: { status: 401 } }))
    );
    const client = await loadRealClient();

    const err = (await client
      .apiFetch('/auth/verify-otp', { method: 'POST', body: { phone: '09123456789', otp_code: '1' } })
      .catch((e: unknown) => e)) as InstanceType<ApiClientModule['ApiError']>;

    expect(err).toBeInstanceOf(client.ApiError);
    expect(err.status).toBe(401);
    // پیام سرور (نه پیام جایگزین «جلسه منقضی») پاس داده می‌شود.
    expect(err.message).toBe('کد ارسالی نامعتبر است.');
    // توکن محلی دست نخورده باقی می‌ماند.
    expect(storage.getItem(TOKEN_KEY)).toBe('tok-keep');
  });

  it('ok-but-invalid JSON throws the Persian invalid-response error (never silent null)', async () => {
    fetchMock.mockResolvedValueOnce(rawResponse(200, 'not-json{{'));
    const client = await loadRealClient();
    await expect(client.apiFetch('/courses')).rejects.toThrow('پاسخ سرور نامعتبر بود؛ لطفاً دوباره تلاش کنید.');
  });

  it('ok-but-empty body throws the Persian invalid-response error', async () => {
    storage.setItem(TOKEN_KEY, 'tok-1');
    fetchMock.mockResolvedValueOnce(rawResponse(200, '   '));
    const client = await loadRealClient();
    await expect(client.apiFetch('/me/progress', { auth: true })).rejects.toThrow(
      'پاسخ سرور نامعتبر بود؛ لطفاً دوباره تلاش کنید.'
    );
  });

  it('WP error body {code,message,data:{status}} becomes ApiError with message + nested status', async () => {
    fetchMock.mockResolvedValueOnce(
      rawResponse(400, JSON.stringify({ code: 'invalid_otp', message: 'کد تایید نامعتبر است.', data: { status: 400 } }))
    );
    const client = await loadRealClient();

    const err = (await client.apiFetch('/auth/verify-otp', { method: 'POST', body: {} }).catch((e) => e)) as InstanceType<
      ApiClientModule['ApiError']
    >;

    expect(err).toBeInstanceOf(client.ApiError);
    expect(err.status).toBe(400); // invalid_otp اکنون 400 است (v1.2.0) — از data.status خوانده شد
    expect(err.message).toBe('کد تایید نامعتبر است.');
  });

  it('AbortController timeout path throws the Persian timeout message', async () => {
    storage.setItem(TOKEN_KEY, 'tok-timeout');
    const client = await loadRealClient();
    vi.useFakeTimers();
    try {
      fetchMock.mockImplementation((_url: string, init: { signal: AbortSignal }) => {
        return new Promise((_resolve, reject) => {
          init.signal.addEventListener('abort', () => {
            reject(new DOMException('The operation was aborted.', 'AbortError'));
          });
        });
      });

      const pending = client.apiFetch('/me/orders', { auth: true });
      const expectation = expect(pending).rejects.toThrow(
        'زمان انتظار پاسخ سرور به پایان رسید؛ اتصال خود را بررسی کنید.'
      );
      await vi.advanceTimersByTimeAsync(client.API_TIMEOUT_MS + 50);
      await expectation;
    } finally {
      vi.useRealTimers();
    }
  });

  it('network failure (fetch rejects with TypeError) throws the Persian connection message', async () => {
    fetchMock.mockRejectedValueOnce(new TypeError('Failed to fetch'));
    const client = await loadRealClient();
    await expect(client.apiFetch('/courses')).rejects.toThrow(
      'ارتباط با سرور برقرار نشد؛ اتصال اینترنت خود را بررسی کنید.'
    );
  });

  it('timeoutMs option overrides the default API_TIMEOUT_MS', async () => {
    const client = await loadRealClient();
    vi.useFakeTimers();
    try {
      fetchMock.mockImplementation((_url: string, init: { signal: AbortSignal }) => {
        return new Promise((_resolve, reject) => {
          init.signal.addEventListener('abort', () => {
            reject(new DOMException('The operation was aborted.', 'AbortError'));
          });
        });
      });

      const pending = client.apiFetch('/ping', { timeoutMs: 1000 });
      const expectation = expect(pending).rejects.toThrow(
        'زمان انتظار پاسخ سرور به پایان رسید؛ اتصال خود را بررسی کنید.'
      );
      await vi.advanceTimersByTimeAsync(1050);
      await expectation;
    } finally {
      vi.useRealTimers();
    }
  });
});
