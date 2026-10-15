/**
 * @file tests/web/versionChecker.test.ts
 * @description v1.5.5 — تست منطق مقایسهٔ نسخه (فیکس باگ حلقهٔ reload v1.5.4).
 *
 * ریشهٔ باگ: چکر قبلی با !== مقایسه می‌کرد؛ سرورِ «قدیمی‌تر» از باندل کلاینت
 * هم باعث force reload می‌شد → حلقهٔ بی‌نهایت → قطع درخواست‌های نیمه‌کاره
 * (verify-otp) با پیام «ارتباط با سرور برقرار نشد».
 */

import { describe, expect, it } from 'vitest';
import { compareSemver, shouldForcePwaReload } from '../../src/utils/versionCompare';

describe('compareSemver', () => {
  it('detects strictly newer server version', () => {
    expect(compareSemver('1.5.5', '1.5.4')).toBe(1);
    expect(compareSemver('2.0.0', '1.9.9')).toBe(1);
    expect(compareSemver('1.6.0', '1.5.10')).toBe(1); // عددی، نه رشته‌ای
  });

  it('detects equal versions', () => {
    expect(compareSemver('1.5.4', '1.5.4')).toBe(0);
  });

  it('detects older server version — ریشهٔ باگ حلقهٔ reload', () => {
    expect(compareSemver('1.5.3', '1.5.4')).toBe(-1);
    expect(compareSemver('1.4.9', '1.5.0')).toBe(-1);
  });

  it('handles partial and extended segments', () => {
    expect(compareSemver('1.5', '1.5.0')).toBe(0); // 1.5 === 1.5.0
    expect(compareSemver('1.5.4.1', '1.5.4')).toBe(1);
  });

  it('returns null for invalid input', () => {
    expect(compareSemver('', '1.5.4')).toBeNull();
    expect(compareSemver('abc', '1.5.4')).toBeNull();
    expect(compareSemver('1.5.x', '1.5.4')).toBeNull();
    expect(compareSemver(' 1.5.4 ', '1.5.4')).toBe(0); // trim مجاز
  });
});

describe('shouldForcePwaReload — قرارداد چکر نسخه (v1.5.5)', () => {
  it('reloads ONLY when server is strictly newer', () => {
    expect(shouldForcePwaReload('1.5.5', '1.5.4')).toBe(true);
    expect(shouldForcePwaReload('1.6.0', '1.5.4')).toBe(true);
  });

  it('never reloads on equal versions', () => {
    expect(shouldForcePwaReload('1.5.4', '1.5.4')).toBe(false);
  });

  it('never reloads when server is older — ضد حلقهٔ بی‌نهایت', () => {
    // سناریوی واقعی استقرار 1.5.4: سرور 1.5.3 (پیش‌فرض کهنه) و باندل 1.5.4
    expect(shouldForcePwaReload('1.5.3', '1.5.4')).toBe(false);
  });

  it('never reloads on invalid/garbage server version', () => {
    expect(shouldForcePwaReload('', '1.5.4')).toBe(false);
    expect(shouldForcePwaReload('git-main', '1.5.4')).toBe(false);
  });
});
