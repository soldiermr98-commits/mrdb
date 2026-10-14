/**
 * قراردادهای وب‌اپ 1.3.0 — تست‌های متمرکز:
 *  (a) normalizeApiLesson — duration عدد/null/غایب → دقیقهٔ 0 (UI چیپ «دقیقه» را حذف می‌کند؛ هرگز «۰ دقیقه»)
 *      + formatDurationFa — قالب دقیقه/ساعت و null برای نامشخص.
 *  (c) normalizeAppUi — ادغام کلید NEW «physical_products» با پیش‌فرض‌ها و ادامهٔ دور ریختن
 *      کلید بازنشستهٔ «categories»؛ سرور قدیمی بدون این کلید هم پیش‌فرض کد را می‌گیرد.
 *  (d) نرمال‌ساز shop — گروه‌بندی دسته‌های GET /shop/products و دور ریختن ردیف‌های مخدوش.
 *
 * محیط تست node است — window/localStorage با شیم درون‌حافظه‌ای stub می‌شود
 * (courseService در بارگذاری ماژول به آن‌ها دست می‌زند).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

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

describe('v1.3.0 lesson duration contract', () => {
  it('(a) normalizeApiLesson maps positive durations to minutes and null/0/missing to 0', async () => {
    const { normalizeApiLesson } = await import('../../src/services/courseService');

    // عدد مثبت سرور → همان دقیقهٔ صحیح
    expect(normalizeApiLesson({ id: 'l1', title: 'جلسه اول', duration: 45 }, 'c1', 0).durationMinutes).toBe(45);
    expect(normalizeApiLesson({ id: 'l1b', duration: 12.6 }, 'c1', 0).durationMinutes).toBe(13);

    // null / 0 / غایب / نامعتبر → ۰ (نامشخص — UI چیپ را کلاً حذف می‌کند)
    expect(normalizeApiLesson({ id: 'l2', duration: null }, 'c1', 1).durationMinutes).toBe(0);
    expect(normalizeApiLesson({ id: 'l3', duration: 0 }, 'c1', 2).durationMinutes).toBe(0);
    expect(normalizeApiLesson({ id: 'l4' }, 'c1', 3).durationMinutes).toBe(0);
    expect(normalizeApiLesson({ id: 'l5', duration: '۳۰' }, 'c1', 4).durationMinutes).toBe(0);
    expect(normalizeApiLesson({ id: 'l6', duration: -5 }, 'c1', 5).durationMinutes).toBe(0);
    expect(normalizeApiLesson({ id: 'l7', duration: Number.NaN }, 'c1', 6).durationMinutes).toBe(0);
  });

  it('(a2) formatDurationFa renders «دقیقه/ساعت» and returns null for unknown (never «۰ دقیقه»)', async () => {
    const { formatDurationFa } = await import('../../src/utils/format');

    expect(formatDurationFa(0)).toBeNull();
    expect(formatDurationFa(null)).toBeNull();
    expect(formatDurationFa(undefined)).toBeNull();
    expect(formatDurationFa(-10)).toBeNull();

    expect(formatDurationFa(45)).toBe('۴۵ دقیقه');
    expect(formatDurationFa(120)).toBe('۲ ساعت');
    expect(formatDurationFa(95)).toBe('۱ ساعت و ۳۵ دقیقه');
  });
});

describe('v1.3.0 app-ui section keys contract', () => {
  it('(c) merge keeps physical_products with server values and still drops retired categories', async () => {
    const { normalizeAppUi, DEFAULT_APP_UI } = await import('../../src/services/appContentService');

    const ui = normalizeAppUi({
      sections: [
        // کلید بازنشسته (افزونهٔ ۱.۳.۵ آن را حذف کرد) — باید دور ریخته شود
        { key: 'categories', title: 'دسته‌بندی‌های قدیمی', subtitle: '', enabled: true, sortOrder: 2 },
        // کلید شناخته‌شده با مقادیر سرور
        { key: 'faq', title: 'پرسش‌های اختصاصی', subtitle: 'زیرنویس اختصاصی', enabled: false, sortOrder: 6 },
        // کلید NEW — افزونهٔ ۱.۴.۰
        { key: 'physical_products', title: 'گالری محصولات', subtitle: 'ارسال به سراسر ایران', enabled: true, sortOrder: 7 },
      ],
    });

    const keys = ui.sections.map((s) => s.key);
    expect(keys).not.toContain('categories');
    expect(keys).toContain('physical_products');

    const shop = ui.sections.find((s) => s.key === 'physical_products');
    expect(shop).toMatchObject({
      title: 'گالری محصولات',
      subtitle: 'ارسال به سراسر ایران',
      enabled: true,
      sortOrder: 7,
    });

    const faq = ui.sections.find((s) => s.key === 'faq');
    expect(faq).toMatchObject({ title: 'پرسش‌های اختصاصی', enabled: false });

    // سکشن‌های غایب در پاسخ سرور با پیش‌فرض کد می‌مانند
    const defFeatured = DEFAULT_APP_UI.sections.find((s) => s.key === 'featured_courses');
    const featured = ui.sections.find((s) => s.key === 'featured_courses');
    expect(featured?.title).toBe(defFeatured?.title);
    expect(featured?.sortOrder).toBe(3);
  });

  it('(c2) an old server without physical_products still gets the code default (enabled, sortOrder 7)', async () => {
    const { normalizeAppUi } = await import('../../src/services/appContentService');

    const legacy = normalizeAppUi({ sections: [{ key: 'faq' }] });

    const shop = legacy.sections.find((s) => s.key === 'physical_products');
    expect(shop).toMatchObject({
      title: 'محصولات دست‌ساز',
      subtitle: 'آثار هنری آمادهٔ ارسال مستقیم',
      enabled: true,
      sortOrder: 7,
    });
    // و کلید بازنشسته هرگز از هیچ پاسخی نمی‌آید
    expect(legacy.sections.map((s) => s.key)).not.toContain('categories');
  });
});

describe('v1.3.0 shop products contract', () => {
  it('(d) normalizeShopProducts groups valid categories/products and drops malformed rows', async () => {
    const { normalizeShopProducts } = await import('../../src/services/shopService');

    const cats = normalizeShopProducts({
      success: true,
      categories: [
        {
          id: 5,
          slug: 'rugs',
          name: 'قالی دستباف',
          products: [
            { id: 1, title: 'قالی تبریزی', price: 1200000, imageUrl: null, url: 'https://mrdastbaaf.com/p1' },
            // بدون url → دور ریخته (CTA لینک الزامی است)
            { id: 99, title: 'بدون لینک', price: 10, url: '' },
            // قیمت رشته‌ای عددی → عدد
            { id: 2, title: 'گلیم اردبیل', price: '۹۵۰۰۰۰', imageUrl: 'https://cdn.example/g.jpg', url: 'https://mrdastbaaf.com/p2' },
          ],
        },
        // دستهٔ بدون محصول معتبر → دور ریخته
        { id: 6, slug: 'empty', name: 'دستهٔ خالی', products: [] },
        // دستهٔ بدون نام → دور ریخته
        { products: [{ id: 3, title: 'کالا', url: 'https://mrdastbaaf.com/p3' }] },
        {
          id: 7,
          slug: 'bags',
          name: 'کیف دست‌دوز',
          products: [{ id: 4, title: 'کیف ترمه', price: 890000, url: 'https://mrdastbaaf.com/p4' }],
        },
      ],
    });

    expect(cats).toHaveLength(2);
    expect(cats.map((c) => c.name)).toEqual(['قالی دستباف', 'کیف دست‌دوز']);

    expect(cats[0].id).toBe(5);
    expect(cats[0].slug).toBe('rugs');
    expect(cats[0].products).toHaveLength(2);
    expect(cats[0].products[0]).toMatchObject({ title: 'قالی تبریزی', price: 1200000, imageUrl: null });
    expect(cats[0].products[1]).toMatchObject({ title: 'گلیم اردبیل', price: 950000 });

    expect(cats[1].products[0]).toMatchObject({ title: 'کیف ترمه', price: 890000, imageUrl: null });
  });

  it('(d2) a malformed /shop/products payload yields an empty list (never crashes)', async () => {
    const { normalizeShopProducts } = await import('../../src/services/shopService');

    expect(normalizeShopProducts(null)).toEqual([]);
    expect(normalizeShopProducts({})).toEqual([]);
    expect(normalizeShopProducts({ categories: 'not-an-array' })).toEqual([]);
  });
});
