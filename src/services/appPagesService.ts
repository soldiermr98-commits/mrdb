/**
 * @file services/appPagesService.ts
 * @description سرویس «صفحات سایت در منوی اپ» (v1.2.6) — فهرست + محتوا از پل وردپرس
 *
 * قرارداد:
 *   GET /app-pages                → AppPage[] (بر اساس سطح دسترسی کاربر جاری فیلترشده سمت سرور)
 *   GET /app-pages/{id}/content   → AppPageContent (HTML رندرشدهٔ برگهٔ وردپرس)
 *
 * در حالت دمو (بدون VITE_API_BASE_URL) فهرست خالی است — منوی «صفحات سایت»
 * فقط وقتی چیزی برای نشان‌دادن باشد رندر می‌شود.
 */

import { AppPage, AppPageContent } from '../types';
import { apiFetch, isRealApiMode } from './apiClient';

interface ApiAppPageShape {
  id?: unknown;
  title?: unknown;
  access?: unknown;
  sortOrder?: unknown;
}

interface ApiAppPageContentShape {
  id?: unknown;
  title?: unknown;
  html?: unknown;
  access?: unknown;
  updatedAtJalali?: unknown;
}

function shapeString(value: unknown, fallback = ''): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function shapeAccess(value: unknown): AppPage['access'] {
  return value === 'members' || value === 'admins' ? value : 'all';
}

export const appPagesService = {
  /**
   * فهرست صفحات مجازِ کاربر جاری — هر بار از سرور (بدون کش محلی) تا تغییر
   * سطح دسترسی (ورود/خرید اشتراک) بلافاصله در منو دیده شود.
   */
  async getPages(): Promise<AppPage[]> {
    if (!isRealApiMode) return [];
    const data = await apiFetch<ApiAppPageShape[]>('/app-pages');
    const list = Array.isArray(data) ? data : [];
    return list
      .map((raw, index) => ({
        id: shapeString(raw.id, `app-page-${index + 1}`),
        title: shapeString(raw.title, 'صفحهٔ سایت'),
        access: shapeAccess(raw.access),
        sortOrder:
          typeof raw.sortOrder === 'number' && Number.isFinite(raw.sortOrder) ? raw.sortOrder : index,
      }))
      .sort((a, b) => a.sortOrder - b.sortOrder);
  },

  /**
   * محتوای HTML یک صفحه — خطای ۴۰۳ (سطح دسترسی ناکافی) بالا فرستاده می‌شود
   * تا UI پیام مناسب + مسیر رفع (ورود/اشتراک) نشان دهد.
   */
  async getPageContent(pageId: string): Promise<AppPageContent> {
    const data = await apiFetch<ApiAppPageContentShape>(
      `/app-pages/${encodeURIComponent(pageId)}/content`
    );
    return {
      id: shapeString(data.id, pageId),
      title: shapeString(data.title, 'صفحهٔ سایت'),
      html: shapeString(data.html),
      access: shapeAccess(data.access),
      updatedAtJalali: shapeString(data.updatedAtJalali),
    };
  },
};
