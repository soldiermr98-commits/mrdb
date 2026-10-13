/**
 * @file services/adminService.ts
 * @description Service for administrative control of homepage sections, editorial pages, and platform metrics
 */

import { INITIAL_EDITORIAL_PAGES, INITIAL_HOMEPAGE_SECTIONS } from '../data/mockData';
import { EditorialPage, HomepageSectionConfig } from '../types';
import { paymentService } from './paymentService';
import { getUserRegistry } from './authService';
import { membershipService } from './membershipService';
import { formatJalali } from '../utils/format';
import { hasStringId, readJsonArray } from './storageGuard';

const STORAGE_KEY_SECTIONS = 'mrdastbaaf_home_sections';
const STORAGE_KEY_PAGES = 'mrdastbaaf_editorial_pages';

// BUG-29: corrupt storage no longer crashes the app on boot. The old
// module-level in-memory caches are also gone (they went stale the moment
// another tab / build wrote new values) — every API now reads through from
// localStorage via the guard, keeping the exported behaviour identical.
function readSections(): HomepageSectionConfig[] {
  return readJsonArray<HomepageSectionConfig>(
    STORAGE_KEY_SECTIONS,
    INITIAL_HOMEPAGE_SECTIONS,
    hasStringId
  );
}

function readPages(): EditorialPage[] {
  return readJsonArray<EditorialPage>(STORAGE_KEY_PAGES, INITIAL_EDITORIAL_PAGES, hasStringId);
}

function persistSections(sections: HomepageSectionConfig[]): void {
  try {
    localStorage.setItem(STORAGE_KEY_SECTIONS, JSON.stringify(sections));
  } catch (e) {
    // ignore
  }
}

function persistPages(pages: EditorialPage[]): void {
  try {
    localStorage.setItem(STORAGE_KEY_PAGES, JSON.stringify(pages));
  } catch (e) {
    // ignore
  }
}

export const adminService = {
  /**
   * Get homepage sections configuration
   */
  async getHomepageSections(): Promise<HomepageSectionConfig[]> {
    return [...readSections()].sort((a, b) => a.sortOrder - b.sortOrder);
  },

  /**
   * Update or reorder homepage sections
   */
  async updateHomepageSections(sections: HomepageSectionConfig[]): Promise<HomepageSectionConfig[]> {
    persistSections([...sections]);
    return sections;
  },

  /**
   * Update a specific homepage section (e.g. hero banner text)
   */
  async updateSection(id: string, updates: Partial<HomepageSectionConfig>): Promise<HomepageSectionConfig> {
    const sections = readSections();
    const idx = sections.findIndex((s) => s.id === id);
    if (idx === -1) throw new Error('بخش یافت نشد.');
    sections[idx] = { ...sections[idx], ...updates };
    persistSections(sections);
    return sections[idx];
  },

  /**
   * Get all WordPress editorial pages
   */
  async getEditorialPages(): Promise<EditorialPage[]> {
    return [...readPages()];
  },

  /**
   * Get single page by slug
   */
  async getEditorialPageBySlug(slug: string): Promise<EditorialPage | null> {
    return readPages().find((p) => p.slug === slug) || null;
  },

  /**
   * Update page content
   */
  async updateEditorialPage(slug: string, updates: Partial<EditorialPage>): Promise<EditorialPage> {
    const pages = readPages();
    const idx = pages.findIndex((p) => p.slug === slug);
    if (idx === -1) throw new Error('صفحه مورد نظر یافت نشد.');
    pages[idx] = {
      ...pages[idx],
      ...updates,
      // LOW-SEVERITY FIX: keep the «آخرین به‌روزرسانی» stamp real instead of the
      // seed placeholder that never moved.
      lastUpdatedJalali: formatJalali(new Date().toISOString()),
    };
    persistPages(pages);
    return pages[idx];
  },

  /**
   * Calculate dashboard analytics summary
   */
  async getAnalyticsSummary(): Promise<{
    totalRevenueToman: number;
    pendingReceiptsCount: number;
    completedOrdersCount: number;
    activeMembersCount: number;
  }> {
    const orders = await paymentService.getAllOrders();
    const completedOrders = orders.filter((o) => o.status === 'completed');
    const pendingReceipts = orders.filter((o) => o.gateway === 'card_to_card' && o.status === 'pending');

    const totalRevenue = completedOrders.reduce((acc, curr) => acc + curr.amountToman, 0);

    // LOW-SEVERITY FIX: derive the live member count from the persistent users
    // registry (BUG-25) instead of the hardcoded `142` — users with a live
    // membership (status active + future expiry, via isMembershipLive) count.
    const registryUsers = getUserRegistry();
    const liveMembers = registryUsers.filter((u) => membershipService.isMembershipLive(u)).length;

    return {
      totalRevenueToman: totalRevenue,
      pendingReceiptsCount: pendingReceipts.length,
      completedOrdersCount: completedOrders.length,
      activeMembersCount: liveMembers,
    };
  },
};
