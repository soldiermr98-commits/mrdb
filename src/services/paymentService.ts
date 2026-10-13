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
 */

import { INITIAL_ORDERS } from '../data/mockData';
import { MembershipPlan, PaymentOrder, UserProfile } from '../types';
import { authService } from './authService';
import { membershipService } from './membershipService';

const STORAGE_KEY_ORDERS = 'mrdastbaaf_orders';

let inMemoryOrders: PaymentOrder[] = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_ORDERS);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return INITIAL_ORDERS;
})();

export const paymentService = {
  /**
   * Get all orders (for Admin or user history)
   */
  async getAllOrders(): Promise<PaymentOrder[]> {
    await new Promise((res) => setTimeout(res, 100));
    return [...inMemoryOrders];
  },

  /**
   * Get orders for a specific user
   */
  async getUserOrders(userId: string): Promise<PaymentOrder[]> {
    await new Promise((res) => setTimeout(res, 100));
    return inMemoryOrders.filter((o) => o.userId === userId);
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

    const orderId = `ord-${Date.now()}`;
    const orderNumber = `MD-${Math.floor(10000 + Math.random() * 90000)}`;
    const trackId = `TRK-ZIBAL-${Date.now().toString().slice(-6)}`;

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
      createdAt: new Date().toISOString(),
      createdAtJalali: 'هم‌اکنون',
      zibalTrackId: trackId,
    };

    inMemoryOrders.unshift(newOrder);
    try {
      localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(inMemoryOrders));
    } catch (e) {
      // ignore
    }

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

    const orderIdx = inMemoryOrders.findIndex((o) => o.id === orderId);
    if (orderIdx === -1) {
      throw new Error('سفارش مورد نظر در پایگاه داده یافت نشد.');
    }

    const order = inMemoryOrders[orderIdx];

    if (simulatedOutcome === 'user_cancelled') {
      order.status = 'failed';
      inMemoryOrders[orderIdx] = order;
      localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(inMemoryOrders));

      return {
        success: false,
        order,
        message: 'تراکنش توسط کاربر در درگاه پرداخت زیبال لغو شد.',
        serverLogExplanation: 'تراکنش توسط کاربر انصراف داده شد. هیچ وجهی کسر نشد و اشتراک فعال نگردید.',
      };
    }

    if (simulatedOutcome === 'bank_error') {
      order.status = 'failed';
      inMemoryOrders[orderIdx] = order;
      localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(inMemoryOrders));

      return {
        success: false,
        order,
        message: 'خطای بانکی در اتصال به شاپرک: شماره کارت نامعتبر یا موجودی ناکافی.',
        serverLogExplanation: 'پاسخ شاپرک کد خطا بود. اشتراک به صورت خودکار مسدود ماند.',
      };
    }

    // SUCCESSFUL OUTCOME
    const refNum = `100${Math.floor(10000000 + Math.random() * 90000000)}`;
    order.status = 'completed';
    order.verifiedAt = new Date().toISOString();
    order.zibalRefNumber = refNum;
    inMemoryOrders[orderIdx] = order;
    localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(inMemoryOrders));

    // Update current user's membership
    const plan = membershipService.getPlanById(order.itemId);
    if (plan) {
      const membershipRecord = membershipService.createMembershipRecord(plan);
      authService.updateCurrentUser({ membership: membershipRecord });
    }

    return {
      success: true,
      order,
      refNumber: refNum,
      message: 'پرداخت با موفقیت انجام شد و اشتراک شما فعال گردید.',
      serverLogExplanation: `[امنیت سرور] فراخوانی موفقیت‌آمیز endpoint تایید زیبال (verify) در سرور با کد پیگیری ${order.zibalTrackId} و شماره مرجع شاپرک ${refNum}. رول کاربری با موفقیت در سیستم به‌روزرسانی شد.`,
    };
  },
};
