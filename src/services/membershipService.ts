/**
 * @file services/membershipService.ts
 * @description Service for managing membership tiers, manual renewals, and entitlement rules
 */

import { INITIAL_MEMBERSHIP_PLANS } from '../data/mockData';
import { MembershipPlan, MembershipTier, UserMembership, UserProfile } from '../types';
import { formatJalali, isLive } from '../utils/format';

const STORAGE_KEY_PLANS = 'mrdastbaaf_plans';

let inMemoryPlans: MembershipPlan[] = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_PLANS);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return INITIAL_MEMBERSHIP_PLANS;
})();

export const membershipService = {
  /**
   * Get all active membership plans for checkout
   */
  async getActivePlans(): Promise<MembershipPlan[]> {
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

    const base = isRenewal && existing ? new Date(existing.expiresAt) : new Date();
    const expires = new Date(base.getTime());

    if (plan.duration === '1_month') {
      expires.setMonth(expires.getMonth() + 1);
    } else if (plan.duration === '3_months') {
      expires.setMonth(expires.getMonth() + 3);
    } else if (plan.duration === '6_months') {
      expires.setMonth(expires.getMonth() + 6);
    } else if (plan.duration === '12_months') {
      expires.setFullYear(expires.getFullYear() + 1);
    }

    const startedAt = isRenewal && existing ? existing.startedAt : new Date().toISOString();
    const diffDays = Math.max(1, Math.ceil((expires.getTime() - Date.now()) / (1000 * 60 * 60 * 24)));

    let jalaliFormatted: string;
    try {
      jalaliFormatted = formatJalali(expires.toISOString());
    } catch (e) {
      jalaliFormatted = '—';
    }

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
    const expires = new Date();

    if (plan.duration === '1_month') {
      expires.setMonth(expires.getMonth() + 1);
    } else if (plan.duration === '3_months') {
      expires.setMonth(expires.getMonth() + 3);
    } else if (plan.duration === '6_months') {
      expires.setMonth(expires.getMonth() + 6);
    } else if (plan.duration === '12_months') {
      expires.setFullYear(expires.getFullYear() + 1);
    }

    const diffDays = Math.ceil((expires.getTime() - started.getTime()) / (1000 * 60 * 60 * 24));

    // DISPLAY FIX (BUG-10): real Gregorian→Jalali conversion using the Intl
    // Persian calendar. The old `getFullYear() - 621` arithmetic produced
    // Gregorian month/day mislabeled as Jalali (off by ~2-3 months).
    // Fallback keeps the app usable on engines without Persian calendar support.
    let jalaliFormatted: string;
    try {
      jalaliFormatted = new Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
      }).format(expires);
    } catch (e) {
      const jalaliYear = expires.getFullYear() - 621; // approximate fallback only
      const jalaliMonth = String(expires.getMonth() + 1).padStart(2, '0');
      const jalaliDay = String(expires.getDate()).padStart(2, '0');
      jalaliFormatted = `${jalaliYear}/${jalaliMonth}/${jalaliDay}`;
    }

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
