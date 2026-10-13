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
import { membershipService } from './membershipService';
import { orderStore } from './orderStore';
import { formatJalali } from '../utils/format';

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
    const trackId = `TRK-ZIBAL-${Date.now().toString().slice(-6)}-${Math.random().toString(36).slice(2, 5).toUpperCase()}`;
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

    if (simulatedOutcome === 'user_cancelled') {
      const updated = orderStore.update(orderId, { status: 'failed' });
      return {
        success: false,
        order: updated || order,
        message: 'تراکنش توسط کاربر در درگاه پرداخت زیبال لغو شد.',
        serverLogExplanation: 'تراکنش توسط کاربر انصراف داده شد. هیچ وجهی کسر نشد و اشتراک فعال نگردید.',
      };
    }

    if (simulatedOutcome === 'bank_error') {
      const updated = orderStore.update(orderId, { status: 'failed' });
      return {
        success: false,
        order: updated || order,
        message: 'خطای بانکی در اتصال به شاپرک: شماره کارت نامعتبر یا موجودی ناکافی.',
        serverLogExplanation: 'پاسخ شاپرک کد خطا بود. اشتراک به صورت خودکار مسدود ماند.',
      };
    }

    // SUCCESSFUL OUTCOME
    // IDEMPOTENCY GUARD (BUG-03 mirror, client side): never double-activate a paid order.
    if (order.status === 'completed') {
      return {
        success: true,
        order,
        refNumber: order.zibalRefNumber,
        message: 'این تراکنش قبلاً تایید شده بود؛ وضعیت اشتراک تغییر نکرد.',
        serverLogExplanation: 'درخواست تکراری verify برای سفارش تکمیل‌شده نادیده گرفته شد (idempotent).',
      };
    }

    const refNum = `100${Math.floor(10000000 + Math.random() * 90000000)}`;
    const updated = orderStore.update(orderId, {
      status: 'completed',
      verifiedAt: new Date().toISOString(),
      zibalRefNumber: refNum,
    });

    // RENEWAL FIX (BUG-19): extend from the current expiry instead of resetting.
    const plan = membershipService.getPlanById(order.itemId);
    if (plan) {
      const membershipRecord = membershipService.grantOrExtend(actingUser, plan);
      authService.updateCurrentUser({ membership: membershipRecord });
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
