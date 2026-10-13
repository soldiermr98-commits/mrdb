/**
 * @file services/authService.ts
 * @description Mock authentication service simulating WordPress + Otino Phone Login
 *
 * ARCHITECTURE NOTE:
 * In production, this service will connect to the WordPress REST API endpoint
 * provided by the Otino OTP plugin or a custom WordPress bridge.
 *
 * TODO: Official Otino OTP REST API endpoints, request schemas, and SMS provider hooks
 * are currently unknown and pending official documentation and staging credentials.
 * Do not invent production endpoints until documentation is provided.
 */

import { INITIAL_USERS } from '../data/mockData';
import { UserProfile, UserRole } from '../types';
import { toEnglishDigits } from '../utils/format';
import { hasStringId, readJsonArray } from './storageGuard';

const STORAGE_KEY_USER = 'mrdastbaaf_current_user';
const STORAGE_KEY_TOKEN = 'mrdastbaaf_auth_token';
// BUG-25: persistent users registry — identity + membership + progress survive
// logout / re-login / re-install of the session (see loadUserRegistry below).
const STORAGE_KEY_USERS = 'mrdastbaaf_users';

// In-memory runtime state with session persistence.
// SECURITY FIX (BUG-07): if no user is persisted, currentUser stays `null`.
// The previous fallback to INITIAL_USERS[0] meant "logout" was not real:
// after logout + page refresh the visitor silently became a logged-in student.
let currentUser: UserProfile | null = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_USER);
    if (saved) return JSON.parse(saved) as UserProfile;
  } catch (e) {
    // ignore
  }
  return null;
})();

/**
 * BUG-25: collision-safe user id — same base36 time + counter + random pattern
 * as orderStore.nextOrderId() so two sign-ups in the same millisecond never
 * share an id (the old `user-${Date.now()}` collided).
 */
let userSeq = 0;
function generateUserId(): string {
  userSeq = (userSeq + 1) % 10000;
  return `user-${Date.now().toString(36)}-${userSeq.toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
}

/**
 * BUG-26: shared phone normalization + validation for requestOtp/verifyOtp.
 * Persian/Arabic digits → English, trims, tolerates +98/0098 prefixes, then
 * enforces the strict Iranian mobile format `^09\d{9}$`.
 * Returns the normalized phone, or `null` when invalid.
 */
export function normalizeIranPhone(raw: string): string | null {
  const normalized = toEnglishDigits(raw ?? '')
    .trim()
    .replace(/^(\+98|0098)/, '0');
  return /^09\d{9}$/.test(normalized) ? normalized : null;
}

/**
 * BUG-25: load the persistent users registry. On first use the registry is
 * seeded from INITIAL_USERS (and persisted). A corrupted registry is healed
 * through the shared storage guard (BUG-29) instead of crashing the app.
 */
function loadUserRegistry(): UserProfile[] {
  try {
    if (localStorage.getItem(STORAGE_KEY_USERS) === null) {
      // BUG-25: first use — seed the registry from INITIAL_USERS and persist it.
      saveUserRegistry(INITIAL_USERS);
      return [...INITIAL_USERS];
    }
  } catch (e) {
    // localStorage unavailable — fall through to the guard (in-memory fallback).
  }
  // BUG-29: a corrupted registry is healed through the shared storage guard.
  return readJsonArray<UserProfile>(STORAGE_KEY_USERS, INITIAL_USERS, hasStringId);
}

function saveUserRegistry(users: UserProfile[]): void {
  try {
    localStorage.setItem(STORAGE_KEY_USERS, JSON.stringify(users));
  } catch (e) {
    // ignore
  }
}

/** BUG-25: read the persistent users registry (seeded with INITIAL_USERS on first use). */
export function getUserRegistry(): UserProfile[] {
  return loadUserRegistry();
}

/** BUG-25/BUG-46: insert or refresh one registry entry (matched by id). */
export function upsertRegistryUser(user: UserProfile): void {
  const users = loadUserRegistry();
  const idx = users.findIndex((u) => u.id === user.id);
  if (idx >= 0) {
    users[idx] = { ...users[idx], ...user };
  } else {
    users.unshift(user);
  }
  saveUserRegistry(users);
}

/**
 * BUG-25: resolve a phone to its registry user (preserving id/membership/progress);
 * unknown phones are registered as brand-new students.
 */
export function findOrRegisterUser(phone: string, fullName?: string): UserProfile {
  const users = loadUserRegistry();
  const existing = users.find((u) => u.phoneNumber === phone);
  if (existing) return existing;

  const masked = `${phone.slice(0, 4)}***${phone.slice(-4)}`;
  const newUser: UserProfile = {
    id: generateUserId(),
    phoneNumber: phone,
    maskedPhone: masked,
    fullName: fullName?.trim() || 'هنرجوی جدید',
    role: 'student',
    completedLessonIds: [],
    bookmarkedLessonIds: [],
    createdAt: new Date().toISOString(),
  };
  users.unshift(newUser);
  saveUserRegistry(users);
  return newUser;
}

export const authService = {
  /**
   * Send mock OTP to Iranian mobile number
   * Valid format: 09xxxxxxxxx or 9xxxxxxxxx
   */
  async requestOtp(phoneNumber: string): Promise<{ success: boolean; message: string; mockOtpCode: string }> {
    // BUG-26: normalize Persian digits + trim BEFORE validation (shared helper
    // with verifyOtp so both paths accept the exact same inputs).
    const cleanPhone = normalizeIranPhone(phoneNumber);
    if (!cleanPhone) {
      throw new Error('شماره موبایل وارد شده معتبر نیست. لطفاً شماره را با فرمت ۰۹xxxxxxxxx وارد کنید.');
    }

    // Simulate network delay
    await new Promise((res) => setTimeout(res, 600));

    // Prototype note: Mock code is fixed to 12345 for ease of testing
    return {
      success: true,
      message: `کد تایید پیامکی (آزمایشی) برای شماره ${cleanPhone} ارسال شد.`,
      mockOtpCode: '12345',
    };
  },

  /**
   * Verify mock OTP
   */
  async verifyOtp(phoneNumber: string, otpCode: string): Promise<{ user: UserProfile; token: string }> {
    // BUG-26: verifyOtp previously skipped phone validation entirely — apply the
    // same normalizeIranPhone guard as requestOtp, and normalize the OTP digits
    // (a keyboard with Persian digits like ۱۲۳۴۵ must still pass).
    const cleanPhone = normalizeIranPhone(phoneNumber);
    if (!cleanPhone) {
      throw new Error('شماره موبایل وارد شده معتبر نیست. لطفاً شماره را با فرمت ۰۹xxxxxxxxx وارد کنید.');
    }
    const cleanOtp = toEnglishDigits(otpCode ?? '').trim();

    // Simulate network delay
    await new Promise((res) => setTimeout(res, 700));

    if (cleanOtp !== '12345') {
      throw new Error('کد تایید وارد شده نامعتبر یا منقضی شده است. (در نسخه آزمایشی، کد ۱۲۳۴۵ است)');
    }

    // BUG-25: resolve through the persistent users registry — re-login restores
    // the SAME id/membership/progress instead of minting a fresh user.
    const user = findOrRegisterUser(cleanPhone);

    const mockToken = `mock-wp-jwt-${Date.now()}`;
    currentUser = user;
    try {
      localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(user));
      localStorage.setItem(STORAGE_KEY_TOKEN, mockToken);
    } catch (e) {
      // ignore
    }

    return { user, token: mockToken };
  },

  /**
   * Get current authenticated user
   */
  getCurrentUser(): UserProfile | null {
    return currentUser;
  },

  /**
   * Switch between demo student and admin for review
   */
  switchRole(role: UserRole): UserProfile {
    // BUG-25 + BUG-27: resolve through the registry (keeps demo membership
    // changes) and FAIL LOUDLY on an unknown role instead of silently falling
    // back to INITIAL_USERS[0]. Seeded users are preferred so the demo switch
    // always lands on the known demo accounts (سارا / مدیر), not the newest
    // sign-up; their state still comes from the registry.
    const registry = getUserRegistry();
    const seededTarget = INITIAL_USERS.find((u) => u.role === role);
    const targetUser =
      (seededTarget && registry.find((u) => u.id === seededTarget.id)) ||
      registry.find((u) => u.role === role);
    if (!targetUser) throw new Error('نقش نامعتبر است');
    currentUser = targetUser;
    try {
      localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(targetUser));
    } catch (e) {
      // ignore
    }
    return targetUser;
  },

  /**
   * Update user details in prototype
   */
  updateCurrentUser(updated: Partial<UserProfile>): UserProfile {
    if (!currentUser) throw new Error('کاربر وارد نشده است.');
    // BUG-28: whitelist merge — an unvalidated Partial previously allowed
    // callers to overwrite `id` / `role` / `phoneNumber` (privilege & identity
    // corruption). Only profile/progress/membership fields are mergeable.
    const allowed: Partial<UserProfile> = {};
    if (updated.fullName !== undefined) allowed.fullName = updated.fullName;
    if (updated.avatarUrl !== undefined) allowed.avatarUrl = updated.avatarUrl;
    if (updated.membership !== undefined) allowed.membership = updated.membership;
    if (updated.completedLessonIds !== undefined) allowed.completedLessonIds = updated.completedLessonIds;
    if (updated.bookmarkedLessonIds !== undefined) allowed.bookmarkedLessonIds = updated.bookmarkedLessonIds;

    currentUser = { ...currentUser, ...allowed };
    try {
      localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(currentUser));
    } catch (e) {
      // ignore
    }
    // BUG-25: mirror membership/progress changes into the persistent registry
    // so the next re-login restores everything.
    upsertRegistryUser(currentUser);
    return currentUser;
  },

  /**
   * Logout
   */
  logout(): void {
    currentUser = null;
    try {
      localStorage.removeItem(STORAGE_KEY_USER);
      localStorage.removeItem(STORAGE_KEY_TOKEN);
    } catch (e) {
      // ignore
    }
  },
};
