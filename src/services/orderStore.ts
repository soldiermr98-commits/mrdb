/**
 * @file services/orderStore.ts
 * @description Single source of truth for all PaymentOrder records.
 *
 * DATA-CONSISTENCY FIX (BUG-09):
 * Previously `paymentService` and `transferService` both persisted to the same
 * localStorage key (`mrdastbaaf_orders`) but kept SEPARATE in-memory caches that
 * were hydrated only once at module load. A manual card-to-card receipt submitted
 * via `transferService` was therefore invisible to `paymentService.getAllOrders()`
 * (and to the Admin dashboard) until a full page reload, because each service kept
 * reading its own stale copy.
 *
 * This store always reads through from localStorage and exposes one mutation path,
 * so every consumer sees the same order list at all times.
 */

import { INITIAL_ORDERS } from '../data/mockData';
import { PaymentOrder } from '../types';

export const ORDERS_STORAGE_KEY = 'mrdastbaaf_orders';

function readOrders(): PaymentOrder[] {
  try {
    const saved = localStorage.getItem(ORDERS_STORAGE_KEY);
    if (saved) {
      const parsed = JSON.parse(saved);
      if (Array.isArray(parsed)) return parsed as PaymentOrder[];
    }
  } catch (e) {
    // Corrupted storage: fall through to seed data and heal the key.
  }
  return [...INITIAL_ORDERS];
}

function writeOrders(orders: PaymentOrder[]): void {
  try {
    localStorage.setItem(ORDERS_STORAGE_KEY, JSON.stringify(orders));
  } catch (e) {
    // localStorage unavailable (private mode / quota): keep working in-memory.
  }
}

/**
 * DATA-INTEGRITY FIX (BUG-20, Phase 2): قبلاً شناسه سفارش `ord-${Date.now()}`
 * و شماره سفارش یک عدد ۵ رقمی تصادفی بود. دو سفارش در همان میلی‌ثانیه (یا دو تب
 * همزمان) شناسه یکسان می‌گرفتند و شماره ۵ رقمی بعد از چند صد سفارش برخورد
 * می‌کرد — برای دفتر مالی غیرقابل قبول است. مولدهای مرکزی زیر یکتایی را تضمین
 * می‌کنند (شمارنده درون-پردازه + پسوند زمانی base36 + کنترل تکراری بودن شماره
 * در میان سفارش‌های موجود).
 */
let orderSeq = 0;

function generateOrderId(): string {
  orderSeq = (orderSeq + 1) % 10000;
  return `ord-${Date.now().toString(36)}-${orderSeq.toString(36)}-${Math.random().toString(36).slice(2, 6)}`;
}

function generateOrderNumber(): string {
  const used = new Set(readOrders().map((o) => o.orderNumber));
  for (let attempt = 0; attempt < 20; attempt++) {
    const candidate = `MD-${Math.floor(10000 + Math.random() * 90000)}-${Date.now().toString(36).slice(-5).toUpperCase()}`;
    if (!used.has(candidate)) return candidate;
  }
  // practically unreachable fallback
  return `MD-${Date.now().toString(36).toUpperCase()}`;
}

export const orderStore = {
  /** Return all orders, always read-through from localStorage. */
  getAll(): PaymentOrder[] {
    return readOrders();
  },

  /** Return all orders of one user. */
  getByUser(userId: string): PaymentOrder[] {
    return readOrders().filter((o) => o.userId === userId);
  },

  /** Insert a new order at the head of the list. Returns the stored list. */
  add(order: PaymentOrder): PaymentOrder[] {
    const orders = readOrders();
    orders.unshift(order);
    writeOrders(orders);
    return orders;
  },

  /**
   * Patch a single order by id.
   * Returns the updated order, or `null` when the id is unknown.
   */
  update(orderId: string, updates: Partial<PaymentOrder>): PaymentOrder | null {
    const orders = readOrders();
    const idx = orders.findIndex((o) => o.id === orderId);
    if (idx === -1) return null;
    orders[idx] = { ...orders[idx], ...updates };
    writeOrders(orders);
    return orders[idx];
  },

  /** Find one order by id (read-through). */
  findById(orderId: string): PaymentOrder | null {
    return readOrders().find((o) => o.id === orderId) || null;
  },

  /** Find one order by Zibal track id (read-through). */
  findByTrackId(trackId: string): PaymentOrder | null {
    return readOrders().find((o) => o.zibalTrackId === trackId) || null;
  },

  /** BUG-20: collision-safe order identifier for the financial ledger. */
  nextOrderId(): string {
    return generateOrderId();
  },

  /** BUG-20: collision-safe human-readable order number. */
  nextOrderNumber(): string {
    return generateOrderNumber();
  },
};
