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
};
