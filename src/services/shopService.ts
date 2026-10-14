/**
 * @file services/shopService.ts
 * @description سرویس «محصولات» (v1.3.0 — ویترین + v1.4.0 «محصولات ویژه») — GET /shop/products
 *
 * قرارداد (افزونهٔ 1.5.0):
 *   GET /shop/products (عمومی) → { success:true,
 *     featured: [{ id, title, price, imageUrl|null, url }],   ← NEW در 1.5.0 (قدیمی‌ها: غایب → [])
 *     categories: [{ id, slug, name, products: [{ id, title, price, imageUrl|null, url }] }] }
 *
 * اصول:
 *  - در حالت دمو (بدون VITE_API_BASE_URL) یک نمونهٔ ثابت محلی با ۲ دسته و ۲ محصول
 *    ویژه برگردانده می‌شود تا سکشن صفحهٔ نخست + کاروسل قابل ارزیابی باشند — بدون هیچ
 *    تصویر بیرونی (imageUrl = null → UI کاشی حرف‌اول می‌کشد).
 *  - پاسخ سرور تدافعی پالایش می‌شود؛ ردیف مخدوش/ناقص دور ریخته می‌شود و هرگز
 *    اپ را نمی‌شکند (همان الگوی appContentService/appPagesService).
 */

import { ShopCategory, ShopFeed, ShopProduct } from '../types';
import { apiFetch, isRealApiMode } from './apiClient';
import { toEnglishDigits } from '../utils/format';

interface ApiShopProductShape {
  id?: unknown;
  title?: unknown;
  price?: unknown;
  imageUrl?: unknown;
  url?: unknown;
}

interface ApiShopCategoryShape {
  id?: unknown;
  slug?: unknown;
  name?: unknown;
  products?: unknown;
}

function shapeString(value: unknown, fallback = ''): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function shapeId(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) && value > 0 ? Math.round(value) : fallback;
}

function shapePrice(value: unknown): number {
  if (typeof value === 'number' && Number.isFinite(value) && value >= 0) return Math.round(value);
  // قیمت رشته‌ای عددی («۱۹۸۰۰۰» / "198000") هم پذیرفته می‌شود تا UI خالی نماند —
  // ارقام فارسی/عربی ابتدا انگلیسی می‌شوند (Number روی ارقام فارسی NaN می‌دهد).
  if (typeof value === 'string') {
    const trimmed = toEnglishDigits(value).trim();
    if (trimmed !== '' && Number.isFinite(Number(trimmed)) && Number(trimmed) >= 0) {
      return Math.round(Number(trimmed));
    }
  }
  return 0;
}

function shapeImageUrl(value: unknown): string | null {
  return typeof value === 'string' && value.trim() !== '' ? value.trim() : null;
}

/** پالایش یک محصول — ردیف بدون عنوان/لینک معتبر دور ریخته می‌شود (url برای CTA الزامی است). */
export function normalizeShopProduct(raw: unknown, index: number): ShopProduct | null {
  const item = raw && typeof raw === 'object' ? (raw as ApiShopProductShape) : {};
  const title = shapeString(item.title);
  const url = shapeString(item.url);
  if (title === '' || url === '') return null;
  return {
    id: shapeId(item.id, index + 1),
    title,
    price: shapePrice(item.price),
    imageUrl: shapeImageUrl(item.imageUrl),
    url,
  };
}

/**
 * پالایش و گروه‌بندی پاسخ /shop/products — دسته‌های بدون نام یا بدون محصول
 * معتبر دور ریخته می‌شوند؛ ترتیب پاسخ سرور حفظ می‌شود.
 */
export function normalizeShopProducts(raw: unknown): ShopCategory[] {
  const data = raw && typeof raw === 'object' ? (raw as { categories?: unknown }) : null;
  const list = Array.isArray(data?.categories)
    ? (data?.categories as unknown[])
    : Array.isArray(raw)
      ? (raw as unknown[])
      : [];

  const out: ShopCategory[] = [];
  list.forEach((entry, catIndex) => {
    const cat = entry && typeof entry === 'object' ? (entry as ApiShopCategoryShape) : {};
    const name = shapeString(cat.name);
    if (name === '') return;
    const products = Array.isArray(cat.products)
      ? (cat.products as unknown[])
          .map((p, i) => normalizeShopProduct(p, i))
          .filter((p): p is ShopProduct => p !== null)
      : [];
    if (products.length === 0) return;
    out.push({
      id: shapeId(cat.id, catIndex + 1),
      slug: shapeString(cat.slug, `shop-cat-${catIndex + 1}`),
      name,
      products,
    });
  });
  return out;
}

/**
 * v1.4.0 — پالایش فهرست «محصولات ویژه» سطح‌بالای پاسخ (هم‌شکل آیتم محصولات دسته‌ها).
 * غایب/مخدوش روی سرور قدیمی → [] (هرگز اپ را نمی‌شکند).
 */
export function normalizeShopFeatured(raw: unknown): ShopProduct[] {
  const data = raw && typeof raw === 'object' ? (raw as { featured?: unknown }) : null;
  if (!Array.isArray(data?.featured)) return [];
  return (data?.featured as unknown[])
    .map((p, i) => normalizeShopProduct(p, i))
    .filter((p): p is ShopProduct => p !== null);
}

/**
 * v1.4.0 — خوراک کامل فروشگاه: {featured, categories} (نرمال‌سازها مشترک‌اند تا
 * شکل آیتم‌ها دقیقاً یکی بماند).
 */
export function normalizeShopFeed(raw: unknown): ShopFeed {
  return {
    featured: normalizeShopFeatured(raw),
    categories: normalizeShopProducts(raw),
  };
}

/** نمونهٔ ثابت دمو — ۲ دسته با کاشی حرف‌اول (بدون تصویر بیرونی؛ دمو صادقانه) */
const DEMO_SHOP_CATEGORIES: ShopCategory[] = [
  {
    id: 1,
    slug: 'handwoven-rugs',
    name: 'قالی و گلیم دستباف',
    products: [
      { id: 101, title: 'قالی تبریزی طرح لچک ترنج (۹۰×۶۰)', price: 4850000, imageUrl: null, url: 'https://mrdastbaaf.com/shop' },
      { id: 102, title: 'گلیم سفال‌رنگ بافت اردبیل (۱۲۰×۸۰)', price: 3200000, imageUrl: null, url: 'https://mrdastbaaf.com/shop' },
      { id: 103, title: 'تابلوفرش دستباف طرح ترنج (رودوزی)', price: 2650000, imageUrl: null, url: 'https://mrdastbaaf.com/shop' },
    ],
  },
  {
    id: 2,
    slug: 'accessories',
    name: 'کیف و زیورآلات صنایع دستی',
    products: [
      { id: 201, title: 'کیف دست‌دوز ترمه (جیبی)', price: 890000, imageUrl: null, url: 'https://mrdastbaaf.com/shop' },
      { id: 202, title: 'زیورآلات مکرمه با سنگ‌های نیمه‌قیمتی', price: 640000, imageUrl: null, url: 'https://mrdastbaaf.com/shop' },
    ],
  },
];

// v1.4.0: دمو — ۲ محصول ویژه (مرجع به همان آیتم‌های دسته‌ها؛ بدون دادهٔ تکراری)
const DEMO_FEATURED: ShopProduct[] = [
  DEMO_SHOP_CATEGORIES[0].products[0],
  DEMO_SHOP_CATEGORIES[1].products[0],
];

export const shopService = {
  /**
   * خوراک «محصولات» (دسته‌ها + ویژه‌ها) — در دمو نمونهٔ ثابت، در Real از سرور
   * (پیشخوان ← مستر دستباف ← محصولات فیزیکی). خطای شبکه بالا فرستاده می‌شود
   * تا HomeView رفتار تحمل‌پذیر خودش (سکشن مخفی) را اعمال کند.
   */
  async fetchShopProducts(): Promise<ShopFeed> {
    if (!isRealApiMode) {
      // دمو: تأخیر کوتاه هم‌سبک بقیهٔ سرویس‌ها
      await new Promise((res) => setTimeout(res, 200));
      return { featured: DEMO_FEATURED, categories: DEMO_SHOP_CATEGORIES };
    }
    const data = await apiFetch<unknown>('/shop/products');
    return normalizeShopFeed(data);
  },
};
