/**
 * @file services/authService.ts
 * @description Mock authentication service simulating WordPress + Otino Phone Login
 * 
 * ARCHITECTURE NOTE:
 * In production, this service will connect to the WordPress REST API endpoint
 * provided by the Otino OTP plugin (e.g. POST /wp-json/otino/v1/send-otp and
 * POST /wp-json/otino/v1/verify-otp). User accounts and roles remain in WordPress (wp_users).
 */

import { INITIAL_USERS } from '../data/mockData';
import { UserProfile, UserRole } from '../types';

const STORAGE_KEY_USER = 'mrdastbaaf_current_user';
const STORAGE_KEY_TOKEN = 'mrdastbaaf_auth_token';

// In-memory runtime state with session persistence for prototype
let currentUser: UserProfile | null = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_USER);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return INITIAL_USERS[0]; // Default to student Sara Ahmadi for immediate evaluation
})();

export const authService = {
  /**
   * Send mock OTP to Iranian mobile number
   * Valid format: 09xxxxxxxxx or 9xxxxxxxxx
   */
  async requestOtp(phoneNumber: string): Promise<{ success: boolean; message: string; mockOtpCode: string }> {
    // Validate Iranian mobile regex
    const cleanPhone = phoneNumber.trim().replace(/^(\+98|0098)/, '0');
    const iranPhoneRegex = /^09[0-9]{9}$/;

    if (!iranPhoneRegex.test(cleanPhone)) {
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
    const cleanPhone = phoneNumber.trim().replace(/^(\+98|0098)/, '0');

    // Simulate network delay
    await new Promise((res) => setTimeout(res, 700));

    if (otpCode !== '12345') {
      throw new Error('کد تایید وارد شده نامعتبر یا منقضی شده است. (در نسخه آزمایشی، کد ۱۲۳۴۵ است)');
    }

    // Find existing user or create student
    let user = INITIAL_USERS.find((u) => u.phoneNumber === cleanPhone);

    if (!user) {
      const masked = `${cleanPhone.slice(0, 4)}***${cleanPhone.slice(-4)}`;
      user = {
        id: `user-${Date.now()}`,
        phoneNumber: cleanPhone,
        maskedPhone: masked,
        fullName: 'هنرجوی جدید',
        role: 'student',
        completedLessonIds: [],
        bookmarkedLessonIds: [],
        createdAt: new Date().toISOString(),
      };
    }

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
    const targetUser = INITIAL_USERS.find((u) => u.role === role) || INITIAL_USERS[0];
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
    currentUser = { ...currentUser, ...updated };
    try {
      localStorage.setItem(STORAGE_KEY_USER, JSON.stringify(currentUser));
    } catch (e) {
      // ignore
    }
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
