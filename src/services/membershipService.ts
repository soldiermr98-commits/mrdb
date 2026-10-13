/**
 * @file services/membershipService.ts
 * @description Service for managing membership tiers, manual renewals, and entitlement rules
 */

import { INITIAL_MEMBERSHIP_PLANS } from '../data/mockData';
import { MembershipPlan, MembershipTier, UserMembership, UserProfile } from '../types';

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
   * Generate membership entitlement record after successful payment verification
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

    // Convert to mock Jalali date (adding 1399/1400 baseline for demo presentation)
    const jalaliYear = expires.getFullYear() - 621;
    const jalaliMonth = String(expires.getMonth() + 1).padStart(2, '0');
    const jalaliDay = String(expires.getDate()).padStart(2, '0');
    const jalaliFormatted = `${jalaliYear}/${jalaliMonth}/${jalaliDay}`;

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
