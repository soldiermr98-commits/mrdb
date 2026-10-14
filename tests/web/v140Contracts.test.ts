/**
 * قراردادهای وب‌اپ 1.4.0 — تست‌های متمرکز:
 *  (a) normalizeShopFeed/normalizeShopFeatured — فهرست سطح‌بالای «featured» از
 *      GET /shop/products (قرارداد افزونهٔ 1.5.0)؛ غایب/مخدوش → [] (سرور قدیمی هم سالم).
 *  (b) دموی fetchShopProducts — ۲ محصول ویژه + ۲ دسته (بدون تصویر بیرونی).
 *  (c) initiateCourseZibalPayment در دمو — خطای فارسی روشن (خرید تک‌دوره‌ای فقط Real).
 *
 * محیط تست node است — window/localStorage با شیم درون‌حافظه‌ای stub می‌شود
 * (paymentService → orderStore در بارگذاری ماژول به window دست می‌زند).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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
  };
}

let storage: ReturnType<typeof createStorageShim>;

beforeEach(() => {
  vi.resetModules();
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
  storage = createStorageShim();
  vi.stubGlobal('localStorage', storage);
  // orderStore در بارگذاری ماژول به window.addEventListener('storage') گوش می‌دهد
  vi.stubGlobal('window', {
    localStorage: storage,
    addEventListener: () => undefined,
    removeEventListener: () => undefined,
  });
});

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('v1.4.0 shop featured contract', () => {
  it('(a) normalizeShopFeed keeps top-level featured rows (same item shape as category products) and drops malformed ones', async () => {
    const { normalizeShopFeed } = await import('../../src/services/shopService');

    const feed = normalizeShopFeed({
      success: true,
      featured: [
        { id: 11, title: 'قالی ویژه', price: 1500000, imageUrl: 'https://cdn.example/f.jpg', url: 'https://mrdastbaaf.com/f1' },
        // قیمت رشته‌ای عددی → عدد (همان نرمال‌ساز آیتم دسته‌ها)
        { id: 12, title: 'گلیم ویژه', price: '۹۵۰۰۰۰', imageUrl: null, url: 'https://mrdastbaaf.com/f2' },
        // بدون url → دور ریخته (CTA لینک الزامی است)
        { id: 99, title: 'بدون لینک', price: 10, url: '' },
        'not-an-object',
      ],
      categories: [
        {
          id: 5,
          slug: 'rugs',
          name: 'قالی دستباف',
          products: [
            { id: 11, title: 'قالی ویژه', price: 1500000, imageUrl: 'https://cdn.example/f.jpg', url: 'https://mrdastbaaf.com/f1' },
          ],
        },
      ],
    });

    expect(feed.featured).toHaveLength(2);
    expect(feed.featured[0]).toMatchObject({ id: 11, title: 'قالی ویژه', price: 1500000 });
    expect(feed.featured[1]).toMatchObject({ id: 12, title: 'گلیم ویژه', price: 950000, imageUrl: null });
    // دسته‌ها دست‌نخورده (نرمال‌ساز مشترک)
    expect(feed.categories).toHaveLength(1);
    expect(feed.categories[0].products[0]).toMatchObject({ id: 11 });
  });

  it('(a2) missing/invalid featured (legacy server) yields [] and never crashes', async () => {
    const { normalizeShopFeed, normalizeShopFeatured } = await import('../../src/services/shopService');

    // سرور قدیمی ۱.۴.۰: کلید featured نمی‌فرستد
    expect(normalizeShopFeatured({ success: true, categories: [] })).toEqual([]);
    expect(normalizeShopFeatured(null)).toEqual([]);
    expect(normalizeShopFeatured('broken')).toEqual([]);

    const feed = normalizeShopFeed({ success: true, categories: [] });
    expect(feed.featured).toEqual([]);
    expect(feed.categories).toEqual([]);
  });

  it('(b) demo fetchShopProducts returns two featured products plus the two demo categories', async () => {
    // NOTE: مخزن یک .env واقعی دارد و vitest آن را در import.meta.env تزریق می‌کند —
    // برای رساندن سرویس به حالت دمو، متغیر پایه صریحاً خالی stub می‌شود (قبل از import دینامیک).
    vi.stubEnv('VITE_API_BASE_URL', '');
    const { shopService } = await import('../../src/services/shopService');

    const feed = await shopService.fetchShopProducts();

    expect(feed.featured).toHaveLength(2);
    expect(feed.categories).toHaveLength(2);
    // محصولات ویژه از میان همان آیتم‌های دسته‌ها هستند (شکل یکسان)
    const categoryTitles = feed.categories.flatMap((c) => c.products.map((p) => p.title));
    for (const featured of feed.featured) {
      expect(categoryTitles).toContain(featured.title);
      expect(featured.url).not.toBe('');
    }
  });
});

describe('v1.4.0 direct course checkout demo guard', () => {
  it('(c) initiateCourseZibalPayment in demo mode throws the exact Persian guard message', async () => {
    // NOTE: .env واقعی مخزن → vitest حالت Real می‌سازد؛ برای گارد دمو، پایه خالی stub می‌شود.
    vi.stubEnv('VITE_API_BASE_URL', '');
    const { paymentService } = await import('../../src/services/paymentService');

    const user = {
      id: 'u1',
      phoneNumber: '09120000000',
      maskedPhone: '0912***0000',
      fullName: 'هنرجوی دمو',
      role: 'student',
      completedLessonIds: [],
      bookmarkedLessonIds: [],
      createdAt: new Date().toISOString(),
    };

    await expect(
      paymentService.initiateCourseZibalPayment(
        user as never,
        { id: 'c1', title: 'دوره دمو', priceToman: 100000, productId: 7 } as never
      )
    ).rejects.toThrow('خرید آنلاین دوره در حالت دمو فعال نیست');
  });
});
