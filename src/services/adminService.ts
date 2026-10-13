/**
 * @file services/adminService.ts
 * @description Service for administrative control of homepage sections, editorial pages, and platform metrics
 */

import { INITIAL_EDITORIAL_PAGES, INITIAL_HOMEPAGE_SECTIONS } from '../data/mockData';
import { EditorialPage, HomepageSectionConfig } from '../types';
import { paymentService } from './paymentService';

const STORAGE_KEY_SECTIONS = 'mrdastbaaf_home_sections';
const STORAGE_KEY_PAGES = 'mrdastbaaf_editorial_pages';

let inMemorySections: HomepageSectionConfig[] = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_SECTIONS);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return INITIAL_HOMEPAGE_SECTIONS;
})();

let inMemoryPages: EditorialPage[] = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_PAGES);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return INITIAL_EDITORIAL_PAGES;
})();

export const adminService = {
  /**
   * Get homepage sections configuration
   */
  async getHomepageSections(): Promise<HomepageSectionConfig[]> {
    return [...inMemorySections].sort((a, b) => a.sortOrder - b.sortOrder);
  },

  /**
   * Update or reorder homepage sections
   */
  async updateHomepageSections(sections: HomepageSectionConfig[]): Promise<HomepageSectionConfig[]> {
    inMemorySections = [...sections];
    try {
      localStorage.setItem(STORAGE_KEY_SECTIONS, JSON.stringify(inMemorySections));
    } catch (e) {
      // ignore
    }
    return inMemorySections;
  },

  /**
   * Update a specific homepage section (e.g. hero banner text)
   */
  async updateSection(id: string, updates: Partial<HomepageSectionConfig>): Promise<HomepageSectionConfig> {
    const idx = inMemorySections.findIndex((s) => s.id === id);
    if (idx === -1) throw new Error('بخش یافت نشد.');
    inMemorySections[idx] = { ...inMemorySections[idx], ...updates };
    try {
      localStorage.setItem(STORAGE_KEY_SECTIONS, JSON.stringify(inMemorySections));
    } catch (e) {
      // ignore
    }
    return inMemorySections[idx];
  },

  /**
   * Get all WordPress editorial pages
   */
  async getEditorialPages(): Promise<EditorialPage[]> {
    return [...inMemoryPages];
  },

  /**
   * Get single page by slug
   */
  async getEditorialPageBySlug(slug: string): Promise<EditorialPage | null> {
    return inMemoryPages.find((p) => p.slug === slug) || null;
  },

  /**
   * Update page content
   */
  async updateEditorialPage(slug: string, updates: Partial<EditorialPage>): Promise<EditorialPage> {
    const idx = inMemoryPages.findIndex((p) => p.slug === slug);
    if (idx === -1) throw new Error('صفحه مورد نظر یافت نشد.');
    inMemoryPages[idx] = { ...inMemoryPages[idx], ...updates };
    try {
      localStorage.setItem(STORAGE_KEY_PAGES, JSON.stringify(inMemoryPages));
    } catch (e) {
      // ignore
    }
    return inMemoryPages[idx];
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

    return {
      totalRevenueToman: totalRevenue,
      pendingReceiptsCount: pendingReceipts.length,
      completedOrdersCount: completedOrders.length,
      activeMembersCount: 142, // Simulated active member pool
    };
  },
};
