/**
 * @file services/transferService.ts
 * @description Service for managing manual bank transfers (کارت به کارت / حواله پایا)
 */

import { ManualTransferDetails, MembershipPlan, PaymentOrder, UserProfile } from '../types';
import { apiFetch, isRealApiMode } from './apiClient';
import { authService, getUserRegistry, upsertRegistryUser } from './authService';
import { membershipService, planDurationToDays, planFromSnapshot } from './membershipService';
import { mutateOrder, orderStore } from './orderStore';
import { formatJalali } from '../utils/format';

export const transferService = {
  /**
   * Submit a new manual bank transfer order
   * Initial status: 'pending' (در انتظار بررسی)
   */
  async submitTransferReceipt(
    user: UserProfile,
    plan: MembershipPlan,
    details: ManualTransferDetails
  ): Promise<PaymentOrder> {
    // ----------------------------------------------------------------------
    // v1.1.0 Real-API: POST /payment/manual-transfer/submit — دقیقاً snake_case PHP:
    // {plan_id, tracking_code, card_last_four, deposit_date, notes}
    // پاسخ: {success, orderId, orderNumber, status: 'pending', message}
    // سفارش واقعی سمت سرور ثبت می‌شود؛ از v1.2.0 (hardening) تاریخچه داشبورد
    // از GET /me/orders خوانده می‌شود و دیگر آینهٔ محلی نوشته نمی‌شود.
    // ----------------------------------------------------------------------
    if (isRealApiMode) {
      const res = await apiFetch<{
        success?: unknown;
        orderId?: unknown;
        orderNumber?: unknown;
        status?: unknown;
        message?: unknown;
      }>('/payment/manual-transfer/submit', {
        method: 'POST',
        auth: true,
        body: {
          plan_id: plan.id,
          tracking_code: details.trackingCode,
          card_last_four: details.sourceCardLastFour,
          deposit_date: details.depositDateJalali,
          notes: details.senderNotes ?? '',
        },
      });

      const createdAt = new Date().toISOString();
      // ---------------------------------------------------------------------
      // Hardening (prelive 18-b): آینهٔ محلی در حالت Real حذف شد — دفتر مالی
      // سرور است (GET /me/orders) و نوشتن محلی، وضعیت سروری را می‌تواند گمراه
      // کند (سفارش rejected/completed سمت سرور هرگز به آینه منتقل نمی‌شد).
      // یک رکورد گذرا (بدون ذخیره در orderStore) فقط برای UI فرم (شماره پیگیری
      // روی صفحهٔ موفقیت) ساخته و برگردانده می‌شود.
      // ---------------------------------------------------------------------
      const transientOrder: PaymentOrder = {
        id: typeof res.orderId === 'string' && res.orderId ? res.orderId : orderStore.nextOrderId(),
        orderNumber:
          typeof res.orderNumber === 'string' && res.orderNumber
            ? res.orderNumber
            : orderStore.nextOrderNumber(),
        userId: user.id,
        userPhone: user.phoneNumber,
        userFullName: user.fullName,
        itemType: 'membership',
        itemId: plan.id,
        itemTitle: plan.title,
        amountToman: plan.priceToman,
        gateway: 'card_to_card',
        // وضعیت واقعی سمت سرور است — رکورد گذرا فقط وضعیت ثبت اولیه را نشان می‌دهد.
        status: 'pending',
        createdAt,
        createdAtJalali: formatJalali(createdAt, true),
        manualTransferDetails: details,
        planSnapshot: { tier: plan.tier, durationDays: planDurationToDays(plan.duration) },
      };

      return transientOrder;
    }

    await new Promise((res) => setTimeout(res, 500));

    // DATA-INTEGRITY FIX (BUG-20): collision-safe identifiers for the financial ledger
    const orderId = orderStore.nextOrderId();
    const orderNumber = orderStore.nextOrderNumber();
    const createdAt = new Date().toISOString();

    const newOrder: PaymentOrder = {
      id: orderId,
      orderNumber,
      userId: user.id,
      userPhone: user.phoneNumber,
      userFullName: user.fullName,
      itemType: 'membership',
      itemId: plan.id,
      itemTitle: plan.title,
      amountToman: plan.priceToman,
      gateway: 'card_to_card',
      status: 'pending', // NEVER mark paid automatically!
      createdAt,
      // STALE-DATE FIX (BUG-23): real Jalali conversion instead of «امروز» placeholder
      createdAtJalali: formatJalali(createdAt, true),
      manualTransferDetails: details,
      // BUG-37: snapshot the plan so admin approval/grant never depends on the
      // (mutable) plans store.
      planSnapshot: { tier: plan.tier, durationDays: planDurationToDays(plan.duration) },
    };

    orderStore.add(newOrder);

    return newOrder;
  },

  /**
   * Admin approves manual bank transfer order
   *
   * v1.1.0: مسیر تایید ادمین در قرارداد فریز‌شده v1.1.0 نیست (بدون اندپوینت فرانت);
   * در حالت Real هم مانند دمو روی فروشگاه محلی کار می‌کند تا UI ادمین نشکند.
   */
  async approveTransferOrder(orderId: string, adminNotes?: string): Promise<PaymentOrder> {
    await new Promise((res) => setTimeout(res, 400));

    const order = orderStore.findById(orderId);
    if (!order) throw new Error('سفارش یافت نشد.');

    // BUG-38: idempotent second click — an already-completed order is returned
    // unchanged instead of re-granting / re-reviewing it.
    if (order.status === 'completed') {
      return order;
    }
    // BUG-38: only pending receipts are approvable.
    if (order.status !== 'pending') {
      throw new Error('این فیش در وضعیت قابل تایید نیست');
    }

    // BUG-38: resolve the plan BEFORE the status transition so a missing plan
    // (and no snapshot) leaves the order untouched in 'pending'.
    const plan = membershipService.getPlanById(order.itemId);
    // BUG-38: fall back to the snapshot taken at purchase time (BUG-37).
    const effectivePlan = plan || planFromSnapshot(order);
    if (!effectivePlan) {
      throw new Error('پلن مربوط به این سفارش یافت نشد');
    }

    const details: ManualTransferDetails | undefined = order.manualTransferDetails
      ? {
          ...order.manualTransferDetails,
          adminReviewNote: adminNotes || 'فیش بانکی توسط کارشناس مالی تایید شد.',
          reviewedAt: new Date().toISOString(),
        }
      : undefined;

    // BUG-33c: atomic status transition (write failure propagates as a throw).
    const updated = mutateOrder(orderId, (o) => {
      o.status = 'completed';
      o.verifiedAt = new Date().toISOString();
      if (details) o.manualTransferDetails = details;
    });
    if (!updated) throw new Error('سفارش یافت نشد.');

    // If current logged in user is the owner of this order, update their membership
    // RENEWAL FIX (BUG-19): extend from the current expiry instead of resetting.
    const currentUser = authService.getCurrentUser();
    if (currentUser && currentUser.id === updated.userId) {
      const membership = membershipService.grantOrExtend(currentUser, effectivePlan);
      authService.updateCurrentUser({ membership });
    } else {
      // BUG-46: the owner is NOT the currently-logged-in user (e.g. admin
      // approving a receipt while nobody / someone else is logged in). The old
      // code silently dropped the membership — write it into the persistent
      // users registry so the owner receives it on their next login.
      const registry = getUserRegistry();
      const owner = registry.find((u) => u.id === updated.userId);
      if (owner) {
        // grantOrExtend reads the owner's stored membership for renewal logic.
        const membership = membershipService.grantOrExtend(owner, effectivePlan);
        upsertRegistryUser({ ...owner, membership });
      }
    }

    return updated;
  },

  /**
   * Admin rejects manual bank transfer order with mandatory reason
   *
   * v1.1.0: مسیر رد ادمین در قرارداد فریز‌شده v1.1.0 نیست — همان مسیر دمو حفظ شده است.
   */
  async rejectTransferOrder(orderId: string, reason: string): Promise<PaymentOrder> {
    if (!reason || reason.trim().length === 0) {
      throw new Error('علت رد فیش واریزی الزامی است.');
    }

    await new Promise((res) => setTimeout(res, 400));

    const order = orderStore.findById(orderId);
    if (!order) throw new Error('سفارش یافت نشد.');

    // BUG-39: a completed (paid & granted) order can never be rejected.
    if (order.status === 'completed') {
      throw new Error('سفارش تکمیل‌شده قابل رد نیست');
    }

    const details: ManualTransferDetails | undefined = order.manualTransferDetails
      ? {
          ...order.manualTransferDetails,
          adminReviewNote: reason,
          reviewedAt: new Date().toISOString(),
        }
      : undefined;

    // BUG-33c: atomic status transition (write failure propagates as a throw).
    const updated = mutateOrder(orderId, (o) => {
      o.status = 'rejected';
      if (details) o.manualTransferDetails = details;
    });
    if (!updated) throw new Error('سفارش یافت نشد.');

    return updated;
  },
};
