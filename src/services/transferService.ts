/**
 * @file services/transferService.ts
 * @description Service for managing manual bank transfers (کارت به کارت / حواله پایا)
 */

import { INITIAL_ORDERS } from '../data/mockData';
import { ManualTransferDetails, MembershipPlan, PaymentOrder, UserProfile } from '../types';
import { authService } from './authService';
import { membershipService } from './membershipService';

const STORAGE_KEY_ORDERS = 'mrdastbaaf_orders';

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
    await new Promise((res) => setTimeout(res, 500));

    const savedOrders: PaymentOrder[] = (() => {
      try {
        const saved = localStorage.getItem(STORAGE_KEY_ORDERS);
        if (saved) return JSON.parse(saved);
      } catch (e) {
        // ignore
      }
      return INITIAL_ORDERS;
    })();

    const orderId = `ord-${Date.now()}`;
    const orderNumber = `MD-${Math.floor(10000 + Math.random() * 90000)}`;

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
      createdAt: new Date().toISOString(),
      createdAtJalali: 'امروز',
      manualTransferDetails: details,
    };

    savedOrders.unshift(newOrder);
    try {
      localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(savedOrders));
    } catch (e) {
      // ignore
    }

    return newOrder;
  },

  /**
   * Admin approves manual bank transfer order
   */
  async approveTransferOrder(orderId: string, adminNotes?: string): Promise<PaymentOrder> {
    await new Promise((res) => setTimeout(res, 400));

    const savedOrders: PaymentOrder[] = (() => {
      try {
        const saved = localStorage.getItem(STORAGE_KEY_ORDERS);
        if (saved) return JSON.parse(saved);
      } catch (e) {
        // ignore
      }
      return INITIAL_ORDERS;
    })();

    const idx = savedOrders.findIndex((o) => o.id === orderId);
    if (idx === -1) throw new Error('سفارش یافت نشد.');

    const order = savedOrders[idx];
    order.status = 'completed';
    order.verifiedAt = new Date().toISOString();
    if (order.manualTransferDetails) {
      order.manualTransferDetails.adminReviewNote = adminNotes || 'فیش بانکی توسط کارشناس مالی تایید شد.';
      order.manualTransferDetails.reviewedAt = new Date().toISOString();
    }

    savedOrders[idx] = order;
    localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(savedOrders));

    // If current logged in user is the owner of this order, update their membership
    const currentUser = authService.getCurrentUser();
    if (currentUser && currentUser.id === order.userId) {
      const plan = membershipService.getPlanById(order.itemId);
      if (plan) {
        const membership = membershipService.createMembershipRecord(plan);
        authService.updateCurrentUser({ membership });
      }
    }

    return order;
  },

  /**
   * Admin rejects manual bank transfer order with mandatory reason
   */
  async rejectTransferOrder(orderId: string, reason: string): Promise<PaymentOrder> {
    if (!reason || reason.trim().length === 0) {
      throw new Error('علت رد فیش واریزی الزامی است.');
    }

    await new Promise((res) => setTimeout(res, 400));

    const savedOrders: PaymentOrder[] = (() => {
      try {
        const saved = localStorage.getItem(STORAGE_KEY_ORDERS);
        if (saved) return JSON.parse(saved);
      } catch (e) {
        // ignore
      }
      return INITIAL_ORDERS;
    })();

    const idx = savedOrders.findIndex((o) => o.id === orderId);
    if (idx === -1) throw new Error('سفارش یافت نشد.');

    const order = savedOrders[idx];
    order.status = 'rejected';
    if (order.manualTransferDetails) {
      order.manualTransferDetails.adminReviewNote = reason;
      order.manualTransferDetails.reviewedAt = new Date().toISOString();
    }

    savedOrders[idx] = order;
    localStorage.setItem(STORAGE_KEY_ORDERS, JSON.stringify(savedOrders));

    return order;
  },
};
