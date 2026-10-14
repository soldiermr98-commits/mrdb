/**
 * @file services/appContentService.ts
 * @description سرویس «محتوای وب‌اپ» (v1.2.7) — متن‌ها/سکشن‌ها/منو از پل وردپرس
 *
 * قرارداد (افزونهٔ 1.3.4+):
 *   GET /app-ui → { hero, sections[], menu:{ dock[], guides[] }, featuredCourseIds[] }
 *
 * اصول:
 *  - در حالت دمو null برمی‌گردد (مصرف‌کننده پیش‌فرض‌های کد را نشان می‌دهد).
 *  - هر فیلد پاسخ به‌صورت تدافعی شکل‌دهی می‌شود؛ پاسخ ناقص/مخدوش هرگز اپ را
 *    نمی‌شکند و فیلد جاافتاده با پیش‌فرض جایگزین می‌شود.
 *  - هیچ کشی در اینجا نیست (پاسخ سرور no-store است و کارفرما انتظار تغییر
 *    تقریباً آنی دارد).
 * v1.2.8: سکشن «categories» بازنشسته شد؛ featuredCourseIds (دوره‌های برگزیده از
 * پیشخوان) اضافه شد.
 * v1.3.0: سکشن NEW «physical_products» (محصولات دست‌ساز — GET /shop/products)
 * با sortOrder 7 به پیش‌فرض‌ها اضافه شد (کلید در types + SECTION_KEYS همگام).
 */

import { AppUiConfig, AppUiSectionKey } from '../types';
import { apiFetch, isRealApiMode } from './apiClient';

/** پیش‌فرض‌های سمت اپ — هم‌ارز get_defaults() افزونه؛ برای دمو/آفلاین/پاسخ ناقص */
export const DEFAULT_APP_UI: AppUiConfig = {
  hero: {
    badgeText: 'پلتفرم تخصصی آموزش صنایع دستی',
    headline: 'هنر بافندگی و اصالت ایرانی را با دستان خود زنده کنید',
    subheadline:
      'مجموعه مسترکلاس‌های تصویری قالی‌بافی، گلیم‌بافی و منسوجات دستباف، همراه با هدایت گام‌به‌گام استادکاران نام‌آور',
    ctaPrimaryText: 'مشاهده دوره‌ها و شروع یادگیری',
    ctaSecondaryText: 'پلن‌های عضویت و اشتراک',
    stat1Value: '+۱,۴۰۰',
    stat1Label: 'هنرجوی فعال در سراسر ایران',
    stat2Value: '۶ دوره',
    stat2Label: 'مسترکلاس ویدیویی گام‌به‌گام',
    stat3Value: '۱۰۰٪ دستی',
    stat3Label: 'تمدید اشتراک بدون کسر خودکار',
  },
  sections: [
    // v1.2.8: سکشن «categories» بازنشسته شد (دسته‌بندی‌های آموزشی حذف شد)
    // ترتیب پیش‌فرض هم‌ارز افزونهٔ 1.3.5 است (featured=3 … faq=6)
    {
      key: 'featured_courses',
      title: 'دوره‌های برگزیده و پیشنهادی اساتید',
      subtitle: 'دوره‌های پرطرفدار با بالاترین رضایت هنرجویان در ماه‌های اخیر',
      enabled: true,
      sortOrder: 3,
    },
    {
      key: 'artisan_story',
      title: 'داستان مستر دستباف: هنر کهن در عصر نو',
      subtitle: 'روایتی از تار و پود، عشق به هویت و انتقال سینه به سینه دانش اساتید',
      enabled: true,
      sortOrder: 4,
    },
    {
      key: 'membership_plans',
      title: 'پلن‌های عضویت و اشتراک دانلودی',
      subtitle: 'دسترسی نامحدود به ویدیوها با پرداخت ماهانه و سالانه مقرون‌به‌صرفه',
      enabled: true,
      sortOrder: 5,
    },
    {
      key: 'faq',
      title: 'سوالات متداول هنرجویان',
      subtitle: 'پاسخ به سوالات پرتکرار پیرامون شیوه آموزش، خرید اشتراک و ابزار کار',
      enabled: true,
      sortOrder: 6,
    },
    // v1.3.0: «محصولات» — همان پیش‌فرض افزونهٔ ۱.۵.۰ (سرو از /shop/products)؛ v1.4.0: عنوان کوتاه‌شده
    {
      key: 'physical_products',
      title: 'محصولات',
      subtitle: 'آثار هنری آمادهٔ ارسال مستقیم',
      enabled: true,
      sortOrder: 7,
    },
  ],
  menu: {
    dock: [
      { key: 'home', label: 'خانه', enabled: true },
      { key: 'catalogue', label: 'دوره‌ها', enabled: true },
      { key: 'membership', label: 'عضویت', enabled: true },
      { key: 'dashboard', label: 'داشبورد', enabled: true },
    ],
    guides: [
      { key: 'about-us', label: 'درباره ما', enabled: true, sortOrder: 1 },
      { key: 'guide-membership', label: 'راهنمای عضویت و تمدید دستی', enabled: true, sortOrder: 2 },
      { key: 'faq', label: 'سوالات متداول', enabled: true, sortOrder: 3 },
      { key: 'membership', label: 'پلن‌های اشتراک', enabled: true, sortOrder: 4 },
    ],
  },
  featuredCourseIds: [],
};

const SECTION_KEYS: AppUiSectionKey[] = [
  'featured_courses',
  'artisan_story',
  'membership_plans',
  'faq',
  // v1.3.0: کلید NEW — سرور قدیمی این کلید را نمی‌فرستد (فقط پیش‌فرض کد)
  'physical_products',
];

const DOCK_KEYS = ['home', 'catalogue', 'membership', 'dashboard'] as const;
const GUIDE_KEYS = ['about-us', 'guide-membership', 'faq', 'membership'] as const;

function str(value: unknown, fallback: string): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function bool(value: unknown, fallback: boolean): boolean {
  return typeof value === 'boolean' ? value : fallback;
}

function num(value: unknown, fallback: number): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : fallback;
}

/** پالایش تدافعی پاسخ /app-ui روی پیش‌فرض‌ها */
export function normalizeAppUi(raw: unknown): AppUiConfig {
  const out: AppUiConfig = {
    hero: { ...DEFAULT_APP_UI.hero },
    sections: DEFAULT_APP_UI.sections.map((s) => ({ ...s })),
    menu: {
      dock: DEFAULT_APP_UI.menu.dock.map((d) => ({ ...d })),
      guides: DEFAULT_APP_UI.menu.guides.map((g) => ({ ...g })),
    },
    featuredCourseIds: [],
  };

  if (!raw || typeof raw !== 'object') return out;
  const data = raw as Record<string, unknown>;

  // ─── hero ───
  if (data.hero && typeof data.hero === 'object') {
    const hero = data.hero as Record<string, unknown>;
    (Object.keys(out.hero) as Array<keyof AppUiConfig['hero']>).forEach((field) => {
      out.hero[field] = str(hero[field], out.hero[field]);
    });
  }

  // ─── sections (بر اساس key ادغام + مرتب‌سازی sortOrder) ───
  // v1.2.8: ردیف‌های ناشناخته (مثل categories بازنشسته‌شده) عمداً دور ریخته می‌شوند —
  // فقط کلیدهای شناخته‌شده از پاسخ سرور روی پیش‌فرض‌ها سوار می‌شوند.
  if (Array.isArray(data.sections)) {
    const storedBy = new Map<string, Record<string, unknown>>();
    data.sections.forEach((entry) => {
      if (entry && typeof entry === 'object' && typeof (entry as any).key === 'string') {
        const key = (entry as any).key as string;
        if ((SECTION_KEYS as string[]).includes(key)) {
          storedBy.set(key, entry as Record<string, unknown>);
        }
      }
    });
    out.sections = out.sections
      .map((def) => {
        const s = storedBy.get(def.key);
        if (!s) return def;
        return {
          key: def.key,
          title: str(s.title, def.title),
          subtitle: str(s.subtitle, def.subtitle),
          enabled: bool(s.enabled, def.enabled),
          sortOrder: num(s.sortOrder, def.sortOrder),
        };
      })
      .sort((a, b) => a.sortOrder - b.sortOrder);
  }

  // ─── featuredCourseIds (v1.2.8: دوره‌های برگزیده از پیشخوان) ───
  if (Array.isArray(data.featuredCourseIds)) {
    out.featuredCourseIds = Array.from(
      new Set(
        data.featuredCourseIds
          .filter((id): id is number => typeof id === 'number' && Number.isFinite(id) && id > 0)
          .map((id) => Math.round(id))
      )
    );
  }

  // ─── menu.dock (فیلدهای شناخته‌شده + ترتیب آرایهٔ سرور) ───
  const menu = data.menu && typeof data.menu === 'object' ? (data.menu as Record<string, unknown>) : null;
  if (menu && Array.isArray(menu.dock)) {
    const storedDock = new Map<string, Record<string, unknown>>();
    menu.dock.forEach((entry) => {
      if (entry && typeof entry === 'object' && typeof (entry as any).key === 'string') {
        storedDock.set((entry as any).key, entry as Record<string, unknown>);
      }
    });
    const serverOrdered = menu.dock
      .map((e) => (e && typeof e === 'object' ? String((e as any).key) : ''))
      .filter((k): k is (typeof DOCK_KEYS)[number] => (DOCK_KEYS as readonly string[]).includes(k));
    const orderedKeys = [...serverOrdered, ...DOCK_KEYS.filter((k) => !serverOrdered.includes(k))];
    out.menu.dock = orderedKeys
      .map((key) => {
        const def = DEFAULT_APP_UI.menu.dock.find((d) => d.key === key)!;
        const s = storedDock.get(key);
        if (!s) return def;
        return { key, label: str(s.label, def.label), enabled: bool(s.enabled, def.enabled) };
      });
  }

  // ─── menu.guides (ادغام بر اساس key + مرتب‌سازی sortOrder) ───
  if (menu && Array.isArray(menu.guides)) {
    const storedGuides = new Map<string, Record<string, unknown>>();
    menu.guides.forEach((entry) => {
      if (entry && typeof entry === 'object' && typeof (entry as any).key === 'string') {
        storedGuides.set((entry as any).key, entry as Record<string, unknown>);
      }
    });
    out.menu.guides = out.menu.guides
      .map((def) => {
        const g = storedGuides.get(def.key);
        if (!g) return def;
        return {
          key: def.key,
          label: str(g.label, def.label),
          enabled: bool(g.enabled, def.enabled),
          sortOrder: num(g.sortOrder, def.sortOrder),
        };
      })
      .sort((a, b) => a.sortOrder - b.sortOrder);
  }

  return out;
}

export const appContentService = {
  /**
   * پیکربندی وب‌اپ از سرور — در دمو یا هر خطایی null برمی‌گرداند تا مصرف‌کننده
   * پیش‌فرض‌های کد را نشان دهد (رفتار امن؛ مثل appPagesService).
   */
  async getUi(): Promise<AppUiConfig | null> {
    if (!isRealApiMode) return null;
    try {
      const data = await apiFetch<unknown>('/app-ui');
      return normalizeAppUi(data);
    } catch {
      return null;
    }
  },
};
