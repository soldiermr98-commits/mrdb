/**
 * @file services/authService.ts
 * @description سرویس احراز هویت Dual-Mode: دمو (mock/رجیستری محلی) و Real-API (پل وردپرس)
 *
 * v1.2.0 (prelive 18-b): مسیرهای OTP/تایید از v1.1.0 مستند و پیاده شده‌اند
 * (POST /auth/request-otp و /auth/verify-otp)، خروج با POST /auth/logout و
 * پیشرفت یادگیری با GET/PUT /me/progress (progressService) همگام می‌شود.
 */

import { INITIAL_USERS } from '../data/mockData';
import { MembershipTier, UserMembership, UserProfile, UserRole } from '../types';
import { formatJalali, toEnglishDigits } from '../utils/format';
import { ApiError, apiFetch, isRealApiMode, setStoredAuthToken, getStoredAuthToken } from './apiClient';
import { ORDERS_STORAGE_KEY } from './orderStore';
import { progressService } from './progressService';
import { hasStringId, readJsonArray } from './storageGuard';

const STORAGE_KEY_USER = 'mrdastbaaf_current_user';
const STORAGE_KEY_TOKEN = 'mrdastbaaf_auth_token';
// BUG-25: persistent users registry — identity + membership + progress survive
// logout / re-login / re-install of the session (see loadUserRegistry below).
const STORAGE_KEY_USERS = 'mrdastbaaf_users';

// ---------------------------------------------------------------------------
// Hardening (prelive 18-b): کلیدهای دمویی که در خروج Real باید پاک شوند.
// ---------------------------------------------------------------------------
const DEMO_DATA_KEYS_TO_PURGE_ON_REAL_LOGOUT: readonly string[] = [
  ORDERS_STORAGE_KEY, // 'mrdastbaaf_orders' — دفتر سفارش‌های دمو/آینهٔ محلی
  STORAGE_KEY_USERS, // 'mrdastbaaf_users' — رجیستری کاربران دمو/آینهٔ هویت
  'mrdastbaaf_courses', // courseService.STORAGE_KEY_COURSES (وارد نشدن سرویس برای جلوگیری از چرخه)
  'mrdastbaaf_notes', // courseService.STORAGE_KEY_NOTES (همان)
];

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

// ---------------------------------------------------------------------------
// v1.1.0 — Real-API: نرمال‌سازی شکل کاربر برگشتی از «/auth/verify-otp» و «/auth/me».
// قرارداد: {id, name|fullName, phone, role: admin|student, avatar?, membership:{tier,
// status, expiresAt?}...} — فیلدهای جاافتاده با default پر می‌شوند تا UserProfile
// کاملِ کلاینت (maskedPhone/completedLessonIds/...) همیشه برقرار بماند.
// ---------------------------------------------------------------------------

/** شکل خام کاربر از پل وردپرس — همهٔ فیلدها optional/unknown تا پاسخ ناقص هم کرش نکند. */
interface ApiUserProfileShape {
  id?: unknown;
  name?: unknown;
  fullName?: unknown;
  phone?: unknown;
  phoneNumber?: unknown;
  maskedPhone?: unknown;
  role?: unknown;
  avatar?: unknown;
  avatarUrl?: unknown;
  membership?: {
    tier?: unknown;
    planId?: unknown;
    planName?: unknown;
    status?: unknown;
    startedAt?: unknown;
    expiresAt?: unknown;
    formattedExpireDateJalali?: unknown;
    daysRemaining?: unknown;
  } | null;
  completedLessonIds?: unknown;
  bookmarkedLessonIds?: unknown;
  createdAt?: unknown;
}

function asTrimmedString(value: unknown, fallback = ''): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function asStringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}

const API_MEMBERSHIP_TIERS: MembershipTier[] = ['free', 'bronze', 'silver', 'gold'];

function tierLabelFa(tier: MembershipTier): string {
  if (tier === 'gold') return 'طلایی';
  if (tier === 'silver') return 'نقره‌ای';
  if (tier === 'bronze') return 'برنزی';
  return 'رایگان';
}

function normalizeApiMembership(
  raw: ApiUserProfileShape['membership']
): UserMembership | undefined {
  if (!raw || typeof raw !== 'object') return undefined;
  const tier: MembershipTier = API_MEMBERSHIP_TIERS.includes(raw.tier as MembershipTier)
    ? (raw.tier as MembershipTier)
    : 'free';
  const status: UserMembership['status'] =
    raw.status === 'expired' || raw.status === 'pending' ? raw.status : 'active';
  const expiresAt = asTrimmedString(raw.expiresAt);
  const expiresMs = expiresAt ? new Date(expiresAt).getTime() : NaN;
  const daysFromExpiry = Number.isFinite(expiresMs)
    ? Math.max(0, Math.ceil((expiresMs - Date.now()) / (1000 * 60 * 60 * 24)))
    : 0;
  const daysRemaining =
    typeof raw.daysRemaining === 'number' && Number.isFinite(raw.daysRemaining)
      ? Math.max(0, Math.round(raw.daysRemaining))
      : daysFromExpiry;
  return {
    tier,
    planId: asTrimmedString(raw.planId),
    planName: asTrimmedString(raw.planName, `اشتراک ${tierLabelFa(tier)}`),
    status,
    startedAt: asTrimmedString(raw.startedAt, new Date().toISOString()),
    expiresAt,
    formattedExpireDateJalali: asTrimmedString(raw.formattedExpireDateJalali, formatJalali(expiresAt || undefined)),
    daysRemaining,
  };
}

/** تبدیل پاسخ خام سرور به UserProfile کامل کلاینت — فیلدهای جاافتاده با default پر می‌شوند. */
function normalizeApiUser(raw: ApiUserProfileShape | null | undefined): UserProfile {
  const source: ApiUserProfileShape = raw && typeof raw === 'object' ? raw : {};
  const phoneNumber = asTrimmedString(source.phoneNumber ?? source.phone, '00000000000');
  // ---------------------------------------------------------------------------
  // Hardening (prelive 18-b — یافتهٔ 1-7): پیشرفت محلی هرگز با [] سرور محو نمی‌شود.
  // default خالی فقط برای «فیلد جاافتاده» است؛ اگر سشن محلیِ قبلی آرایه داشته باشد،
  // هنگام غیبت فیلد در پاسخ سرور همان آرایه حفظ می‌شود:
  //   (آرایهٔ غیرخالی سرور) ? سرور : (آرایهٔ سشن قبلی) ? قبلی : []
  // یکپارچه‌سازی اتحاد سرور/محلی هم در progressService.syncProgressOnLogin انجام می‌شود.
  // ---------------------------------------------------------------------------
  const previousUser = currentUser;
  const previousCompleted =
    Array.isArray(previousUser?.completedLessonIds) ? previousUser.completedLessonIds : [];
  const previousBookmarked =
    Array.isArray(previousUser?.bookmarkedLessonIds) ? previousUser.bookmarkedLessonIds : [];
  const serverCompleted = asStringArray(source.completedLessonIds);
  const serverBookmarked = asStringArray(source.bookmarkedLessonIds);
  return {
    id: asTrimmedString(source.id) || generateUserId(),
    phoneNumber,
    maskedPhone:
      asTrimmedString(source.maskedPhone) ||
      (phoneNumber.length >= 8 ? `${phoneNumber.slice(0, 4)}***${phoneNumber.slice(-4)}` : phoneNumber),
    fullName: asTrimmedString(source.fullName ?? source.name, 'هنرجوی مستر دستباف'),
    // قرارداد v1.1.0: role فقط admin|student است — avatar از avatarUrl یا avatar خوانده می‌شود.
    role: source.role === 'admin' ? 'admin' : 'student',
    avatarUrl: asTrimmedString(source.avatarUrl ?? source.avatar) || undefined,
    membership: normalizeApiMembership(source.membership),
    completedLessonIds: serverCompleted.length > 0 ? serverCompleted : previousCompleted,
    bookmarkedLessonIds: serverBookmarked.length > 0 ? serverBookmarked : previousBookmarked,
    createdAt: asTrimmedString(source.createdAt) || new Date().toISOString(),
  };
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

    // v1.1.0 Real-API: POST /auth/request-otp {phone} → {success, message, expires_in_seconds, smsWarning?}
    if (isRealApiMode) {
      const res = await apiFetch<{
        success?: unknown;
        message?: unknown;
        expires_in_seconds?: unknown;
        smsWarning?: unknown;
      }>('/auth/request-otp', { method: 'POST', body: { phone: cleanPhone } });
      const baseMessage = asTrimmedString(res.message, `کد تایید پیامکی برای شماره ${cleanPhone} ارسال شد.`);
      const smsWarning = asTrimmedString(res.smsWarning);
      return {
        success: res.success !== false,
        // هشدار سرور (مثلاً تأخیر پیامک) بدون تغییر UI به همان پیام چسبانده می‌شود.
        message: smsWarning ? `${baseMessage} ${smsWarning}` : baseMessage,
        mockOtpCode: '',
      };
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

    // v1.1.0 Real-API: POST /auth/verify-otp {phone, otp_code} → {success, token, user}
    if (isRealApiMode) {
      const res = await apiFetch<{
        success?: unknown;
        token?: unknown;
        user?: ApiUserProfileShape;
      }>('/auth/verify-otp', { method: 'POST', body: { phone: cleanPhone, otp_code: cleanOtp } });

      const token = asTrimmedString(res.token);
      if (!token) {
        throw new Error('پاسخ ورود از سرور معتبر نیست؛ توکن دریافت نشد. لطفاً دوباره تلاش کنید.');
      }

      // شکل کاربر سرور با defaultها کامل می‌شود (completedLessonIds و ...)
      const user = normalizeApiUser(res.user);

      // همان کلید توکن دمو (mrdastbaaf_auth_token) — منبع واحد apiClient
      setStoredAuthToken(token);
      currentUser = user;
      try {
        localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(user));
      } catch (e) {
        // ignore
      }
      // BUG-25: هویت Real هم در رجیستری محلی mirror می‌شود تا پیشرفت/بوکمارک دمو حفظ بماند
      upsertRegistryUser(user);

      // Hardening (prelive 18-b): یکپارچه‌سازی پیشرفت سرور با سشن — fire-and-forget
      // (شکست سینک هرگز لاگین را نمی‌شکند؛ progressService داخلی try/catch دارد).
      void progressService.syncProgressOnLogin();

      return { user, token };
    }

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
    // Hardening (prelive 18-b): سوییچ نقش فقط ابزار بازبینی دمو است — در حالت
    // اتصال واقعی به سرور هرگز نباید اجرا شود (هویت ادمین/هنرجو از سرور می‌آید).
    if (isRealApiMode) {
      throw new Error('سوییچ نقش در حالت واقعی مجاز نیست.');
    }
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
   * Logout.
   *
   * Hardening (prelive 18-b):
   *  - حالت Real: ابتدا POST /auth/logout با Bearer (best-effort؛ await با catch —
   *    خطا بی‌صدا نادیده گرفته می‌شود) و بعد پاک‌سازی توکن/سشن، تا سرور توکن
   *    opaque را revoke کند. سپس کلیدهای دادهٔ دمو نیز پاک می‌شوند (سرور دفتر
   *    مالی/سفارش است؛ آینه‌های محلی دیگر معنا ندارند).
   *  - حالت دمو: همان رفتار قبلی (پاک‌سازی سشن محلی).
   * متد async است؛ caller نیازی به await ندارد (هیچ‌وقت throw نمی‌کند).
   */
  async logout(): Promise<void> {
    if (isRealApiMode) {
      // ۱) revoke سمت سرور — best-effort: حتی اگر شبکه/سرور در دسترس نبود،
      // خروج محلی باید کامل شود (توکن انقضاشده هم سرور بی‌خطر می‌کند).
      try {
        await apiFetch('/auth/logout', { method: 'POST', auth: true });
      } catch (e) {
        // best-effort — هیچ خطایی جریان خروج را نمی‌شکند.
      }
    }

    currentUser = null;
    try {
      localStorage.removeItem(STORAGE_KEY_USER);
      // در حالت Real هم همین کلید توکن opaque را پاک می‌کند (apiClient همان کلید را می‌خواند).
      localStorage.removeItem(STORAGE_KEY_TOKEN);
    } catch (e) {
      // ignore
    }

    if (isRealApiMode) {
      // ۲) پاک‌سازی داده‌های دمو/آینه‌های محلی — فقط در حالت Real و فقط روی
      // فهرست کلیدهای صریح (بدون wildcard) تا هیچ کلید ناشناخته‌ای دست نخورد.
      // دفتر مالی واقعی سرور است؛ این کلیدها فقط منابع UI دمو/آینه بودند.
      for (const key of DEMO_DATA_KEYS_TO_PURGE_ON_REAL_LOGOUT) {
        try {
          localStorage.removeItem(key);
        } catch (e) {
          // ignore — حذف تک‌کلید هرگز کل خروج را نمی‌شکند.
        }
      }
    }
  },

  /**
   * v1.1.0 Real-API — همگام‌سازی سشن از سرور هنگام boot:
   * اگر حالت Real فعال باشد و توکن ذخیره‌شده وجود داشته باشد، «GET /auth/me» صدا زده
   * می‌شود و عضویت/هویت از سرور تازه می‌شود. در دمو (بدون env) بدونِ touch سشن null برمی‌گرداند.
   *
   * - 401: توکن توسط apiClient پاک شده → سشن محلی هم بسته می‌شود (خروج خودکار).
   * - خطای شبکه/سرور: سشن محلی حفظ می‌شود (تحمل آفلاین) و null برمی‌گردد.
   */
  async syncSessionFromServer(): Promise<UserProfile | null> {
    if (!isRealApiMode) return null;
    if (!getStoredAuthToken()) return null;

    try {
      const res = await apiFetch<{ user?: ApiUserProfileShape }>('/auth/me', { auth: true });
      // قرارداد: {user: {...}} — برای سازگاری، پاسخِ خودِ پروفایل هم پذیرفته می‌شود.
      const rawUser =
        res.user && typeof res.user === 'object' ? res.user : (res as unknown as ApiUserProfileShape);
      const user = normalizeApiUser(rawUser);
      currentUser = user;
      try {
        localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(user));
      } catch (e) {
        // ignore
      }
      upsertRegistryUser(user);

      // Hardening (prelive 18-b): یکپارچه‌سازی پیشرفت سرور (GET /me/progress → UNION)
      // — progressService هرگز throw نمی‌کند؛ await تا پروفایل برگشتی شامل
      // پیشرفت یکپارچه باشد (App پس از سینک، state را با همین مقدار تازه می‌کند).
      await progressService.syncProgressOnLogin();

      // پس از سینک پیشرفت، سشن ممکن است آرایه‌های یکپارچه داشته باشد — نسخهٔ نهایی برگردانده می‌شود.
      return currentUser;
    } catch (e) {
      if (e instanceof ApiError && e.status === 401) {
        // توکن توسط apiClient پاک شده — سشن محلی هم باید بسته شود.
        currentUser = null;
        try {
          localStorage.removeItem(STORAGE_KEY_USER);
        } catch (e2) {
          // ignore
        }
      }
      return null;
    }
  },
};
