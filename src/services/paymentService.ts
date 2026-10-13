/**
 * @file services/paymentService.ts
 * @description Service for managing payments simulating Zibal Internet Payment Gateway (IPG)
 *
 * CRITICAL ARCHITECTURAL PRINCIPLE:
 * In a real production deployment, membership activation MUST NEVER happen
 * purely on the frontend redirect callback (e.g. /payment/callback?trackId=...).
 * The backend server (WordPress / Node.js) MUST perform a trusted server-to-server
 * verification request (POST https://gateway.zibal.ir/v1/verify) using the merchant key.
 * Only after the server validates that result == 100 (Success) and status == 1 (Paid)
 * is the user's role/membership updated in the database.
 *
 * TODO: Real Zibal Merchant ID, production IPG callback URL, and backend verification routes
 * are currently pending staging credentials. Do not hardcode or generate real merchant keys.
 */

import { MembershipPlan, PaymentOrder, UserProfile } from '../types';
import { authService } from './authService';
import { membershipService, planDurationToDays, planFromSnapshot } from './membershipService';
import { mutateOrder, orderStore } from './orderStore';
import { formatJalali } from '../utils/format';

/**
 * BUG-36: collision-safe mock track id — same base36 time + counter + random
 * pattern as orderStore.nextOrderId(). The previous `Date.now().slice(-6) +
 * 3 random chars` collided across tabs / rapid checkouts and broke
 * findByTrackId() lookups.
 */
let trackSeq = 0;
function generateZibalTrackId(): string {
  trackSeq = (trackSeq + 1) % 10000;
  return `TRK-ZIBAL-${Date.now().toString(36)}-${trackSeq.toString(36)}-${Math.random().toString(36).slice(2, 6)}`.toUpperCase();
}

export const paymentService = {
  /**
   * Get all orders (for Admin or user history)
   */
  async getAllOrders(): Promise<PaymentOrder[]> {
    await new Promise((res) => setTimeout(res, 100));
    return orderStore.getAll();
  },

  /**
   * Get orders for a specific user
   */
  async getUserOrders(userId: string): Promise<PaymentOrder[]> {
    await new Promise((res) => setTimeout(res, 100));
    return orderStore.getByUser(userId);
  },

  /**
   * Step 1: Initialize Zibal Checkout
   * In production: calls POST /api/payment/zibal/request
   */
  async initiateZibalPayment(
    user: UserProfile,
    plan: MembershipPlan
  ): Promise<{ order: PaymentOrder; zibalTrackId: string; gatewayUrl: string }> {
    await new Promise((res) => setTimeout(res, 400));

    // DATA-INTEGRITY FIX (BUG-20): collision-safe identifiers for the financial ledger
    const orderId = orderStore.nextOrderId();
    const orderNumber = orderStore.nextOrderNumber();
    // BUG-36: collision-safe mock track id
    const trackId = generateZibalTrackId();
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
      gateway: 'zibal',
      status: 'pending',
      createdAt,
      // STALE-DATE FIX (BUG-23): real Jalali conversion instead of the fake
      // «هم‌اکنون» placeholder that stayed forever in the order history.
      createdAtJalali: formatJalali(createdAt, true),
      zibalTrackId: trackId,
      // BUG-37: snapshot the plan so verify/grant never depends on the plans
      // store (the plan may be deleted/edited by admin before verification).
      planSnapshot: { tier: plan.tier, durationDays: planDurationToDays(plan.duration) },
    };

    orderStore.add(newOrder);

    return {
      order: newOrder,
      zibalTrackId: trackId,
      gatewayUrl: `https://gateway.zibal.ir/start/${trackId}`, // Mock reference URL
    };
  },

  /**
   * Step 2: Server-Side Payment Verification (Mock)
   * Simulates the server-to-server POST https://gateway.zibal.ir/v1/verify
   */
  async verifyZibalPayment(
    orderId: string,
    simulatedOutcome: 'success' | 'bank_error' | 'user_cancelled'
  ): Promise<{
    success: boolean;
    order: PaymentOrder;
    message: string;
    refNumber?: string;
    serverLogExplanation: string;
  }> {
    await new Promise((res) => setTimeout(res, 800));

    const order = orderStore.findById(orderId);
    if (!order) {
      throw new Error('سفارش مورد نظر در پایگاه داده یافت نشد.');
    }

    /**
     * OWNERSHIP GUARD (BUG-21): mirror of the production IDOR protection —
     * verify فقط برای سفارشی مجاز است که متعلق به کاربر لاگین فعلی باشد؛
     * وگرنه هر کاربر لاگین‌شده می‌توانست سفارش pending دیگری را تکمیل کند.
     */
    const actingUser = authService.getCurrentUser();
    if (!actingUser || actingUser.id !== order.userId) {
      throw new Error('این سفارش متعلق به حساب کاربری فعلی شما نیست و امکان تایید آن وجود ندارد.');
    }

    // BUG-34: gateway guard — a card_to_card order must never be verified
    // through the Zibal path (and vice versa).
    if (order.gateway !== 'zibal') {
      throw new Error('این سفارش از مسیر دیگری در حال بررسی است');
    }

    // BUG-34: status guards BEFORE any outcome handling — a completed order can
    // no longer be flipped to 'failed' by a later «user_cancelled» click.
    if (order.status === 'completed') {
      // IDEMPOTENCY GUARD (BUG-03 mirror, client side): never double-activate a paid order.
      return {
        success: true,
        order,
        refNumber: order.zibalRefNumber,
        message: 'این تراکنش قبلاً تایید شده بود؛ وضعیت اشتراک تغییر نکرد.',
        serverLogExplanation: 'درخواست تکراری verify برای سفارش تکمیل‌شده نادیده گرفته شد (idempotent).',
      };
    }
    if (order.status === 'verifying') {
      throw new Error('پرداخت در حال بررسی است');
    }
    if (order.status !== 'pending') {
      throw new Error('این سفارش قابل تایید نیست');
    }

    if (simulatedOutcome === 'user_cancelled') {
      // BUG-33c: atomic status transition (write failure propagates).
      const updated = mutateOrder(orderId, (o) => {
        o.status = 'failed';
      });
      return {
        success: false,
        order: updated || order,
        message: 'تراکنش توسط کاربر در درگاه پرداخت زیبال لغو شد.',
        serverLogExplanation: 'تراکنش توسط کاربر انصراف داده شد. هیچ وجهی کسر نشد و اشتراک فعال نگردید.',
      };
    }

    if (simulatedOutcome === 'bank_error') {
      const updated = mutateOrder(orderId, (o) => {
        o.status = 'failed';
      });
      return {
        success: false,
        order: updated || order,
        message: 'خطای بانکی در اتصال به شاپرک: شماره کارت نامعتبر یا موجودی ناکافی.',
        serverLogExplanation: 'پاسخ شاپرک کد خطا بود. اشتراک به صورت خودکار مسدود ماند.',
      };
    }

    // SUCCESSFUL OUTCOME
    const refNum = `100${Math.floor(10000000 + Math.random() * 90000000)}`;
    // BUG-34: single atomic check+write via mutateOrder — the status transition
    // and its guard share one lock, so a parallel verify call cannot race the grant.
    const updated = mutateOrder(orderId, (o) => {
      o.status = 'completed';
      o.verifiedAt = new Date().toISOString();
      o.zibalRefNumber = refNum;
    });

    // RENEWAL FIX (BUG-19): extend from the current expiry instead of resetting.
    const plan = membershipService.getPlanById(order.itemId);
    if (plan) {
      const membershipRecord = membershipService.grantOrExtend(actingUser, plan);
      authService.updateCurrentUser({ membership: membershipRecord });
    } else {
      // BUG-37: plan lookup failed — fall back to the snapshot taken at purchase
      // time so the grant never depends on the (mutable) plans store.
      const snapshotPlan = planFromSnapshot(order);
      if (snapshotPlan) {
        const membershipRecord = membershipService.grantOrExtend(actingUser, snapshotPlan);
        authService.updateCurrentUser({ membership: membershipRecord });
      } else {
        // BUG-35: no plan and no snapshot → NEVER return success. Park the order
        // as 'processing' with an admin note and fail loudly for the caller.
        mutateOrder(orderId, (o) => {
          o.status = 'processing';
          o.adminNote = 'پلن مربوط به این سفارش در فروشگاه پلن‌ها یافت نشد؛ فعال‌سازی نیازمند بررسی دستی است.';
        });
        throw new Error('پلن مربوط به این سفارش یافت نشد؛ با پشتیبانی تماس بگیرید');
      }
    }

    return {
      success: true,
      order: updated || order,
      refNumber: refNum,
      message: 'پرداخت با موفقیت انجام شد و اشتراک شما فعال گردید.',
      serverLogExplanation: `[امنیت سرور] فراخوانی موفقیت‌آمیز endpoint تایید زیبال (verify) در سرور با کد پیگیری ${order.zibalTrackId} و شماره مرجع شاپرک ${refNum}. رول کاربری با موفقیت در سیستم به‌روزرسانی شد.`,
    };
  },
};
