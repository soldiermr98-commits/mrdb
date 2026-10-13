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
 *
 * BUG-33 (Phase 3):
 *  (a) a module-level `storage` listener re-hydrates (and notifies subscribers)
 *      when another tab mutates `mrdastbaaf_orders`;
 *  (b) write failures (private mode / full quota) are no longer swallowed —
 *      `add()`/`update()`/`mutateOrder()` throw so callers can surface the error;
 *  (c) `mutateOrder()` re-reads latest state and applies the transition under an
 *      in-module mutex (boolean lock + microtask queue) — fixes TOCTOU
 *      double-extension races in payment/transfer status transitions.
 */

import { INITIAL_ORDERS } from '../data/mockData';
import { PaymentOrder } from '../types';
import { readJsonArray } from './storageGuard';

export const ORDERS_STORAGE_KEY = 'mrdastbaaf_orders';

/**
 * BUG-29: per-item validation for stored orders — a row without `id`/`status`/
 * `userId` is financial-ledger corruption; it is quarantined (console.warn inside
 * the guard) instead of crashing dashboards that assume those fields.
 */
function isValidOrderRow(item: any): boolean {
  return (
    typeof item?.id === 'string' &&
    typeof item?.status === 'string' &&
    typeof item?.userId === 'string'
  );
}

function readOrders(): PaymentOrder[] {
  // BUG-29: shared storage guard — heals corrupted JSON / non-array payloads.
  return readJsonArray<PaymentOrder>(ORDERS_STORAGE_KEY, INITIAL_ORDERS, isValidOrderRow);
}

/** BUG-33b: returns write success so mutations can fail loudly. */
function writeOrders(orders: PaymentOrder[]): boolean {
  try {
    localStorage.setItem(ORDERS_STORAGE_KEY, JSON.stringify(orders));
    return true;
  } catch (e) {
    // localStorage unavailable (private mode / quota) — report to caller.
    return false;
  }
}

/** BUG-33b: one shared Persian message for storage write failures. */
export const ORDERS_WRITE_ERROR = 'ذخیره‌سازی مرورگر در دسترس نیست یا فضای آن پر است';

/**
 * BUG-33a: cross-tab sync. The store is read-through (no stale cache), so the
 * listener's job is to force a re-parse of the fresh payload and notify
 * subscribers (the upcoming UI wiring) that orders changed in another tab.
 * Registered exactly once, guarded for non-browser environments.
 */
type OrdersListener = (orders: PaymentOrder[]) => void;
const ordersListeners = new Set<OrdersListener>();

/** BUG-33a: subscribe to order changes (local writes + other tabs). Returns an unsubscribe fn. */
export function subscribeToOrders(listener: OrdersListener): () => void {
  ordersListeners.add(listener);
  return () => {
    ordersListeners.delete(listener);
  };
}

function notifyOrdersListeners(): void {
  const orders = readOrders();
  ordersListeners.forEach((listener) => {
    try {
      listener(orders);
    } catch (e) {
      // a broken subscriber must never break the store
    }
  });
}

if (typeof window !== 'undefined') {
  // BUG-33a: registered once at module load.
  window.addEventListener('storage', (event) => {
    // `event.key === null` means another tab cleared all storage.
    if (event.key === null || event.key === ORDERS_STORAGE_KEY) {
      notifyOrdersListeners();
    }
  });
}

/**
 * BUG-33c: in-module mutex. JavaScript is single-threaded, so the lock can only
 * be re-entered from WITHIN a mutation callback (e.g. fn triggers another
 * mutateOrder). Such nested tasks are pushed to a microtask queue and drained
 * right after the outer lock is released — the uncontended path stays fully
 * synchronous so callers always get the updated order back.
 */
let mutationLocked = false;
const mutationQueue: Array<() => void> = [];

function runExclusive(task: () => void): void {
  if (mutationLocked) {
    mutationQueue.push(task);
    return;
  }
  mutationLocked = true;
  try {
    task();
  } finally {
    mutationLocked = false;
    if (mutationQueue.length > 0) {
      // Drain queued (re-entrant) mutations asynchronously.
      queueMicrotask(() => {
        const next = mutationQueue.shift();
        if (next) runExclusive(next);
      });
    }
  }
}

/**
 * BUG-33c: atomic read-modify-write for one order.
 * Re-reads the LATEST state inside the lock, lets `fn` mutate a shallow copy
 * (or return a replacement), writes once and returns the updated order —
 * `null` when the id is unknown. Throws when the storage write fails (BUG-33b).
 *
 * @param orderId target order id
 * @param fn      mutator: mutate the draft in place or return the new order
 */
export function mutateOrder(
  orderId: string,
  fn: (draft: PaymentOrder) => PaymentOrder | void
): PaymentOrder | null {
  let result: PaymentOrder | null = null;
  runExclusive(() => {
    const orders = readOrders(); // latest state — never a cached copy
    const idx = orders.findIndex((o) => o.id === orderId);
    if (idx === -1) return;
    const draft = { ...orders[idx] };
    const returned = fn(draft);
    // fn may mutate the draft in place (void) or return a replacement order.
    const next: PaymentOrder = returned !== undefined ? (returned as PaymentOrder) : draft;
    orders[idx] = next;
    if (!writeOrders(orders)) {
      throw new Error(ORDERS_WRITE_ERROR);
    }
    result = next;
    notifyOrdersListeners();
  });
  return result;
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

  /**
   * Insert a new order at the head of the list. Returns the stored list.
   * BUG-33b: throws when the write fails so callers can surface the error.
   */
  add(order: PaymentOrder): PaymentOrder[] {
    const orders = readOrders();
    orders.unshift(order);
    if (!writeOrders(orders)) {
      throw new Error(ORDERS_WRITE_ERROR);
    }
    notifyOrdersListeners();
    return orders;
  },

  /**
   * Patch a single order by id.
   * Returns the updated order, or `null` when the id is unknown.
   * BUG-33b: throws when the write fails so callers can surface the error.
   */
  update(orderId: string, updates: Partial<PaymentOrder>): PaymentOrder | null {
    const orders = readOrders();
    const idx = orders.findIndex((o) => o.id === orderId);
    if (idx === -1) return null;
    orders[idx] = { ...orders[idx], ...updates };
    if (!writeOrders(orders)) {
      throw new Error(ORDERS_WRITE_ERROR);
    }
    notifyOrdersListeners();
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
