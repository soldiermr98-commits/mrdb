/**
 * @file services/membershipService.ts
 * @description Service for managing membership tiers, manual renewals, and entitlement rules
 */

import { INITIAL_MEMBERSHIP_PLANS } from '../data/mockData';
import { MembershipPlan, MembershipTier, PaymentOrder, PlanDuration, UserMembership, UserProfile } from '../types';
import { apiFetch, isRealApiMode } from './apiClient';
import { formatJalali, isLive } from '../utils/format';
import { hasStringId, readJsonArray } from './storageGuard';

const STORAGE_KEY_PLANS = 'mrdastbaaf_plans';

// BUG-29: corrupt plans storage no longer crashes the app on boot — the shared
// storage guard heals the key and quarantines malformed rows.
const inMemoryPlans: MembershipPlan[] = readJsonArray<MembershipPlan>(
  STORAGE_KEY_PLANS,
  INITIAL_MEMBERSHIP_PLANS,
  hasStringId
);

// ---------------------------------------------------------------------------
// v1.1.0 — Real-API: پلن‌ها از «GET /memberships/plans» می‌آیند. شکل PHP:
// {id, tier, title, description, priceToman, originalPriceToman, duration:
// 'N_days'، durationLabel, features, isPopular, isActive, colorScheme} —
// duration به PlanDuration کلاینت نگاشت می‌شود (همان آستانه‌های planFromSnapshot).
// ---------------------------------------------------------------------------

interface ApiPlanShape {
  id?: unknown;
  tier?: unknown;
  title?: unknown;
  description?: unknown;
  priceToman?: unknown;
  originalPriceToman?: unknown;
  duration?: unknown;
  durationLabel?: unknown;
  features?: unknown;
  isPopular?: unknown;
  isActive?: unknown;
  colorScheme?: unknown;
}

const PLAN_DURATIONS: PlanDuration[] = ['1_month', '3_months', '6_months', '12_months'];
const PLAN_TIERS: MembershipTier[] = ['free', 'bronze', 'silver', 'gold'];
const PLAN_COLOR_SCHEMES: MembershipPlan['colorScheme'][] = ['slate', 'amber', 'stone', 'yellow'];

function planString(value: unknown, fallback = ''): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

/** نگاشت مدت سرور (روز یا PlanDuration) به PlanDuration کلاینت — آستانه‌های BUG-37. */
function planDurationFromApi(value: unknown): PlanDuration {
  if (typeof value === 'string' && PLAN_DURATIONS.includes(value as PlanDuration)) {
    return value as PlanDuration;
  }
  const match = typeof value === 'string' ? /^(\d+)_days$/.exec(value) : null;
  const days = match ? Number(match[1]) : NaN;
  if (!Number.isFinite(days)) return '1_month';
  if (days >= 365) return '12_months';
  if (days >= 180) return '6_months';
  if (days >= 90) return '3_months';
  return '1_month';
}

function normalizeApiPlan(raw: ApiPlanShape): MembershipPlan {
  const duration = planDurationFromApi(raw.duration);
  const originalPrice =
    typeof raw.originalPriceToman === 'number' && Number.isFinite(raw.originalPriceToman)
      ? Math.round(raw.originalPriceToman)
      : undefined;
  return {
    id: planString(raw.id, `plan-${Date.now()}`),
    tier: PLAN_TIERS.includes(raw.tier as MembershipTier) ? (raw.tier as MembershipTier) : 'bronze',
    title: planString(raw.title, 'اشتراک مستر دستباف'),
    description: planString(raw.description),
    priceToman:
      typeof raw.priceToman === 'number' && Number.isFinite(raw.priceToman) && raw.priceToman >= 0
        ? Math.round(raw.priceToman)
        : 0,
    originalPriceToman: originalPrice,
    duration,
    durationLabel: planString(raw.durationLabel, `${planDurationToDays(duration)} روزه`),
    features: Array.isArray(raw.features)
      ? (raw.features as unknown[]).filter((f): f is string => typeof f === 'string')
      : [],
    isPopular: raw.isPopular === true,
    isActive: raw.isActive !== false,
    colorScheme: PLAN_COLOR_SCHEMES.includes(raw.colorScheme as MembershipPlan['colorScheme'])
      ? (raw.colorScheme as MembershipPlan['colorScheme'])
      : 'stone',
  };
}

/** v1.1.0: آخرین فهرست Real واکشی‌شده — برای getPlanById در حالت Real (بدون نیاز به اندپوینت تک). */
let realPlansCache: MembershipPlan[] | null = null;

/**
 * BUG-40: افزودن مدت اشتراک به تاریخ مبنا با «کلمپ آخر ماه».
 *
 * `Date.setMonth` سرریز دارد: ۳۱ ژانویه + ۱ ماه → ۲/۳ مارس (پرش از اسفند)؛
 * یعنی هر تمدید در روزهای ۲۹–۳۱، چند روز از اعتبار هنرجو را حذف می‌کرد.
 * این تابع روز را به آخرین روزِ ماه مقصد محدود می‌کند:
 *   ۳۱ ژانویه + ۱ ماه → ۲۸/۲۹ فوریه، ۳۱ اوت + ۱ ماه → ۳۰ سپتامبر، ...
 * هم `grantOrExtend` و هم `createMembershipRecord` از همین مسیر می‌روند.
 */
export function addDuration(base: Date, duration: PlanDuration): Date {
  const expires = new Date(base.getTime());
  const originalDay = expires.getDate();

  if (duration === '12_months') {
    expires.setFullYear(expires.getFullYear() + 1);
  } else {
    const monthsToAdd = duration === '3_months' ? 3 : duration === '6_months' ? 6 : 1; // '1_month'
    expires.setMonth(expires.getMonth() + monthsToAdd);
  }

  // Overflow detection: if the day-of-month shrank, setMonth/setFullYear rolled
  // into the next month — clamp back to the last day of the intended month.
  if (expires.getDate() !== originalDay) {
    expires.setDate(0); // day 0 = last day of the previous (intended) month
  }
  return expires;
}

/** BUG-37: approximate day counts stamped into order snapshots at purchase time. */
export function planDurationToDays(duration: PlanDuration): number {
  switch (duration) {
    case '3_months':
      return 90;
    case '6_months':
      return 180;
    case '12_months':
      return 365;
    default:
      return 30; // '1_month'
  }
}

/**
 * BUG-37/BUG-38: بازسازی پلن از اسنپ‌شات ذخیره‌شده روی سفارش — وقتی پلن اصلی از
 * فروشگاه پلن‌ها حذف/ویرایش شده باشد، گرنت اشتراک نباید شکست بخورد.
 * Returns `null` when the order carries no snapshot (legacy orders).
 */
export function planFromSnapshot(order: PaymentOrder): MembershipPlan | null {
  if (!order.planSnapshot) return null;
  const days = order.planSnapshot.durationDays;
  const duration: PlanDuration =
    days >= 365 ? '12_months' : days >= 180 ? '6_months' : days >= 90 ? '3_months' : '1_month';
  return {
    id: order.itemId,
    tier: order.planSnapshot.tier,
    title: order.itemTitle,
    description: 'بازیابی‌شده از اسنپ‌شات سفارش',
    priceToman: order.amountToman,
    duration,
    durationLabel: `${order.planSnapshot.durationDays} روزه`,
    features: [],
    isActive: true,
    colorScheme: 'stone',
  };
}

export const membershipService = {
  /**
   * Get all active membership plans for checkout
   */
  async getActivePlans(): Promise<MembershipPlan[]> {
    // v1.1.0 Real-API: GET /memberships/plans → Plan[] (فقط فعال‌ها)
    if (isRealApiMode) {
      const data = await apiFetch<ApiPlanShape[]>('/memberships/plans');
      const plans = (Array.isArray(data) ? data : []).map((p) => normalizeApiPlan(p));
      realPlansCache = plans;
      return plans.filter((p) => p.isActive);
    }

    await new Promise((res) => setTimeout(res, 100));
    return inMemoryPlans.filter((p) => p.isActive);
  },

  /**
   * Get all plans (including inactive ones) for Admin
   */
  async getAllPlans(): Promise<MembershipPlan[]> {
    await new Promise((res) => setTimeout(res, 100));
    return [...inMemoryPlans];
  },

  /**
   * Get single plan by ID
   */
  getPlanById(planId: string): MembershipPlan | null {
    // v1.1.0: در حالت Real اولویت با آخرین فهرست واکشی‌شده از سرور است تا مسیرهای
    // گرنت محلی (fallback) به پلن‌های واقعی resolve شوند، نه داده‌های دمو.
    if (isRealApiMode && realPlansCache) {
      const realPlan = realPlansCache.find((p) => p.id === planId) || null;
      if (realPlan) return realPlan;
    }
    return inMemoryPlans.find((p) => p.id === planId) || null;
  },

  /**
   * Admin: Update or edit plan settings
   */
  async updatePlan(planId: string, updates: Partial<MembershipPlan>): Promise<MembershipPlan> {
    const idx = inMemoryPlans.findIndex((p) => p.id === planId);
    if (idx === -1) throw new Error('پلن مورد نظر یافت نشد.');
    inMemoryPlans[idx] = { ...inMemoryPlans[idx], ...updates };
    try {
      localStorage.setItem(STORAGE_KEY_PLANS, JSON.stringify(inMemoryPlans));
    } catch (e) {
      // ignore
    }
    return inMemoryPlans[idx];
  },

  /**
   * Admin: Add new membership plan
   */
  async createPlan(newPlanData: Omit<MembershipPlan, 'id'>): Promise<MembershipPlan> {
    const newPlan: MembershipPlan = {
      ...newPlanData,
      id: `plan-${Date.now()}`,
    };
    inMemoryPlans.push(newPlan);
    try {
      localStorage.setItem(STORAGE_KEY_PLANS, JSON.stringify(inMemoryPlans));
    } catch (e) {
      // ignore
    }
    return newPlan;
  },

  /**
   * ACCESS GATE FIX (BUG-18): «فعال» بودن اشتراک فقط با status کافی نیست؛
   * رکورد ذخیره‌شده در localStorage ممکن است status='active' داشته باشد ولی
   * تاریخش گذشته باشد. این گیت در هر جای UI که وضعیت اشتراک نمایش یا به آن
   * تکیه می‌شود باید استفاده شود (داشبورد، صفحه عضویت، ...).
   */
  isMembershipLive(user: UserProfile | null): boolean {
    if (!user?.membership) return false;
    return user.membership.status === 'active' && isLive(user.membership.expiresAt);
  },

  /**
   * Renewal mirror of the server-side BUG-04 fix (grant_or_extend):
   * RENEWAL FIX (BUG-19): قبلاً هر بار خرید/تمدید، رکورد از «همین لحظه» ساخته
   * می‌شد؛ هنرجویی که ۲۰ روز قبل از انقضا تمدید می‌کرد، آن ۲۰ روز را از دست
   * می‌داد. اکنون اگر اشتراک فعلی زنده باشد، انقضای جدید از همان انقضای قبلی
   * به بعد محاسبه می‌شود (روزهای باقی‌مانده حفظ می‌شود).
   */
  grantOrExtend(user: UserProfile | null, plan: MembershipPlan): UserMembership {
    const existing = user?.membership;
    const isRenewal = this.isMembershipLive(user);

    // BUG-40: shared month-end-clamped duration addition.
    const base = isRenewal && existing ? new Date(existing.expiresAt) : new Date();
    const expires = addDuration(base, plan.duration);

    const startedAt = isRenewal && existing ? existing.startedAt : new Date().toISOString();
    const diffDays = Math.max(1, Math.ceil((expires.getTime() - Date.now()) / (1000 * 60 * 60 * 24)));

    // formatJalali already returns «—» for invalid input (no throw path needed).
    const jalaliFormatted = formatJalali(expires.toISOString());

    return {
      tier: plan.tier,
      planId: plan.id,
      planName: plan.title,
      status: 'active',
      startedAt,
      expiresAt: expires.toISOString(),
      formattedExpireDateJalali: jalaliFormatted,
      daysRemaining: diffDays,
    };
  },

  /**
   * Generate membership entitlement record after successful payment verification
   * (new activation from «now» — renewals must go through `grantOrExtend`).
   */
  createMembershipRecord(plan: MembershipPlan): UserMembership {
    const started = new Date();
    // BUG-40: shared addDuration (month-end clamp) instead of the duplicated
    // setMonth chain, and the shared formatJalali instead of the inline
    // `getFullYear() - 621` Intl implementation (deleted here).
    const expires = addDuration(started, plan.duration);

    const diffDays = Math.ceil((expires.getTime() - started.getTime()) / (1000 * 60 * 60 * 24));
    const jalaliFormatted = formatJalali(expires.toISOString());

    return {
      tier: plan.tier,
      planId: plan.id,
      planName: plan.title,
      status: 'active',
      startedAt: started.toISOString(),
      expiresAt: expires.toISOString(),
      formattedExpireDateJalali: jalaliFormatted,
      daysRemaining: diffDays,
    };
  },
};
