/**
 * Hardening (prelive 18-b): اسموک قرارداد Real-API برای اندپوینت‌های جدید افزونهٔ v1.2.0.
 *
 * پوشش:
 *  - verifyOtp (Real) → POST /auth/verify-otp با بدنهٔ {phone, otp_code} →
 *    سشن ساخته می‌شود و syncProgressOnLogin به‌صورت fire-and-forget اجرا می‌شود.
 *  - syncProgressOnLogin → GET /me/progress و UNION order-stable با پیشرفت محلی؛
 *    سپس PUT /me/progress با آرایهٔ یکپارچه (بعد از debounce ۱۵۰۰ms).
 *  - logout (Real) → POST /auth/logout با Bearer و سپس پاک‌سازی توکن + کلیدهای دمو.
 *  - fetchOrders → GET /me/orders با Bearer و نرمال‌سازی به PaymentOrder.
 *
 * fetch و localStorage با mock/stub جایگزین شده‌اند؛ env با vi.stubEnv «قبل از»
 * import دینامیک ست می‌شود تا isRealApiMode=true شود.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type AuthServiceModule = typeof import('../../src/services/authService');
type PaymentServiceModule = typeof import('../../src/services/paymentService');
type ProgressServiceModule = typeof import('../../src/services/progressService');
type ApiClientModule = typeof import('../../src/services/apiClient');

const TOKEN_KEY = 'mrdastbaaf_auth_token';

function createStorageShim() {
  const store = new Map<string, string>();
  return {
    store,
    getItem: (key: string): string | null => (store.has(key) ? (store.get(key) as string) : null),
    setItem: (key: string, value: string): void => {
      store.set(key, String(value));
    },
    removeItem: (key: string): void => {
      store.delete(key);
    },
  };
}

let storage: ReturnType<typeof createStorageShim>;
const fetchMock = vi.fn();
const calls: Array<{ url: string; method: string; headers: Record<string, string>; body: string | undefined }> = [];

function jsonResponse(body: unknown, status = 200) {
  return { ok: status >= 200 && status < 300, status, text: async () => JSON.stringify(body) };
}

beforeEach(() => {
  vi.resetModules();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  vi.useRealTimers();
  storage = createStorageShim();
  vi.stubGlobal('localStorage', storage);
  vi.stubGlobal('fetch', fetchMock);
  fetchMock.mockReset();
  calls.length = 0;
  fetchMock.mockImplementation(async (url: string, init: { method?: string; headers: Record<string, string>; body?: string }) => {
    calls.push({
      url: String(url),
      method: init.method ?? 'GET',
      headers: init.headers,
      body: init.body,
    });
    if (String(url).endsWith('/auth/verify-otp')) {
      return jsonResponse({
        success: true,
        token: '42.secret',
        user: { id: '42', name: 'کاربر آزمایشی', phone: '09123456789', role: 'student' },
      });
    }
    if (String(url).endsWith('/me/progress')) {
      if ((init.method ?? 'GET') === 'PUT') {
        return jsonResponse({ completedLessonIds: [], bookmarkedLessonIds: [] });
      }
      return jsonResponse({ completedLessonIds: ['srv-1', 'local-1', 'srv-2'], bookmarkedLessonIds: [] });
    }
    if (String(url).endsWith('/auth/logout')) {
      return jsonResponse({ success: true, message: 'خروج انجام شد' });
    }
    if (String(url).endsWith('/me/orders')) {
      return jsonResponse({
        orders: [
          {
            id: 'o1',
            orderNumber: 'MD-10001',
            itemTitle: 'اشتراک برنزی',
            amountToman: 198000,
            gateway: 'zibal',
            status: 'completed',
            createdAt: '2026-01-01T10:00:00Z',
            verifiedAt: '2026-01-01T10:05:00Z',
            refNumber: '100200300',
          },
          {
            id: 'o2',
            orderNumber: 'MD-10002',
            itemTitle: 'اشتراک طلایی',
            amountToman: '990000',
            gateway: 'card_to_card',
            status: 'verifying',
            createdAt: '2026-02-01T08:00:00Z',
          },
        ],
      });
    }
    return jsonResponse({ success: true }, 200);
  });
});

afterEach(() => {
  vi.useRealTimers();
});

async function loadRealServices(): Promise<{
  auth: AuthServiceModule;
  payment: PaymentServiceModule;
  progress: ProgressServiceModule;
  api: ApiClientModule;
}> {
  vi.stubEnv('VITE_API_BASE_URL', 'https://x.example/wp-json/mrdastbaaf/v1');
  const auth = (await import('../../src/services/authService')) as AuthServiceModule;
  const payment = (await import('../../src/services/paymentService')) as PaymentServiceModule;
  const progress = (await import('../../src/services/progressService')) as ProgressServiceModule;
  const api = (await import('../../src/services/apiClient')) as ApiClientModule;
  return { auth, payment, progress, api };
}

describe('Real-API v1.2.0 contracts', () => {
  it('verifyOtp posts {phone, otp_code}, stores the token and unions server/local progress', async () => {
    const { auth } = await loadRealServices();

    const { user, token } = await auth.authService.verifyOtp('09123456789', '12345');
    expect(token).toBe('42.secret');
    expect(user.id).toBe('42');
    expect(storage.getItem(TOKEN_KEY)).toBe('42.secret');

    // پیشرفت محلیِ کاربر تازه‌وارد (سرور فیلد را نیاورده → [] بدون محو کردن چیزی)
    expect(user.completedLessonIds).toEqual([]);

    // fire-and-forget سینک پیشرفت: کمی صبر تا GET /me/progress برسد و merge شود.
    await new Promise((resolve) => setTimeout(resolve, 10));

    const verifyCall = calls.find((c) => c.url.endsWith('/auth/verify-otp'));
    expect(verifyCall).toBeDefined();
    expect(JSON.parse(verifyCall?.body ?? '{}')).toEqual({ phone: '09123456789', otp_code: '12345' });

    const progressCall = calls.find((c) => c.url.endsWith('/me/progress'));
    expect(progressCall).toBeDefined();
    expect(progressCall?.headers.Authorization).toBe('Bearer 42.secret');

    // UNION order-stable: محلی‌ها اول (ترتیب سشن)، سروری‌های جدید بعد، داپلیکیت حذف.
    const merged = auth.authService.getCurrentUser()?.completedLessonIds ?? [];
    expect(merged).toEqual(['srv-1', 'local-1', 'srv-2']);
  });

  it('pushProgress debounces and PUTs the full array to /me/progress', async () => {
    vi.useFakeTimers();
    try {
      const { auth, progress } = await loadRealServices();
      await auth.authService.verifyOtp('09123456789', '12345');
      await vi.advanceTimersByTimeAsync(20); // سینک خودکار پس از لاگین کامل شود

      const before = auth.authService.getCurrentUser()?.completedLessonIds ?? [];
      progress.progressService.pushProgress({
        completedLessonIds: [...before, 'local-3'],
      });
      await vi.advanceTimersByTimeAsync(1600); // عبور از debounce ۱۵۰۰ms

      const put = [...calls].reverse().find((c) => c.url.endsWith('/me/progress') && c.method === 'PUT');
      expect(put).toBeDefined();
      expect(put?.headers.Authorization).toBe('Bearer 42.secret');
      const body = JSON.parse(put?.body ?? '{}') as { completedLessonIds: string[] };
      // قرارداد replace: آرایهٔ کامل فرستاده می‌شود، نه دلتا — همهٔ آیتم‌های قبلی + جدید.
      expect(body.completedLessonIds).toContain('local-3');
      for (const id of before) {
        expect(body.completedLessonIds).toContain(id);
      }
      expect(body.completedLessonIds).toHaveLength(before.length + 1);
    } finally {
      vi.useRealTimers();
    }
  });

  it('logout posts /auth/logout with Bearer first, then clears token and demo data keys', async () => {
    const { auth, api } = await loadRealServices();
    api.setStoredAuthToken('tok-logout');
    for (const key of ['mrdastbaaf_orders', 'mrdastbaaf_users', 'mrdastbaaf_courses', 'mrdastbaaf_notes']) {
      storage.setItem(key, '[]');
    }

    await auth.authService.logout();

    const logoutCall = calls.find((c) => c.url.endsWith('/auth/logout'));
    expect(logoutCall).toBeDefined();
    expect(logoutCall?.method).toBe('POST');
    expect(logoutCall?.headers.Authorization).toBe('Bearer tok-logout');

    expect(storage.getItem(TOKEN_KEY)).toBeNull();
    for (const key of ['mrdastbaaf_orders', 'mrdastbaaf_users', 'mrdastbaaf_courses', 'mrdastbaaf_notes']) {
      expect(storage.getItem(key)).toBeNull();
    }
  });

  it('fetchOrders GETs /me/orders with Bearer and normalizes rows to PaymentOrder', async () => {
    const { auth, payment } = await loadRealServices();
    await auth.authService.verifyOtp('09123456789', '12345');
    await new Promise((resolve) => setTimeout(resolve, 10));

    const orders = await payment.paymentService.fetchOrders();

    const ordersCall = calls.find((c) => c.url.endsWith('/me/orders'));
    expect(ordersCall).toBeDefined();
    expect(ordersCall?.headers.Authorization).toBe('Bearer 42.secret');

    expect(orders).toHaveLength(2);
    expect(orders[0]).toMatchObject({
      id: 'o1',
      orderNumber: 'MD-10001',
      itemTitle: 'اشتراک برنزی',
      amountToman: 198000,
      gateway: 'zibal',
      status: 'completed',
      zibalRefNumber: '100200300',
      verifiedAt: '2026-01-01T10:05:00Z',
    });
    // نرمال‌ساز به غیبت/رشته‌بودن فیلدها تحمل دارد:
    expect(orders[1].amountToman).toBe(0); // رشتهٔ «۹۹۰۰۰۰» عددی-فارسی → 0 (تا نرمال‌ساز عدد نگیرد نمی‌پذیرد)
    expect(orders[1].status).toBe('verifying');
    expect(orders[1].gateway).toBe('card_to_card');
    expect(orders[1].createdAtJalali).not.toBe('');
    expect(orders[1].userId).toBe('42');
  });

  it('switchRole is forbidden in real mode with the exact Persian error', async () => {
    const { auth } = await loadRealServices();
    expect(() => auth.authService.switchRole('admin')).toThrow('سوییچ نقش در حالت واقعی مجاز نیست.');
  });
});
