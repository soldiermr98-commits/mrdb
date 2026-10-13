/**
 * @file services/transferService.ts
 * @description Service for managing manual bank transfers (کارت به کارت / حواله پایا)
 */

import { ManualTransferDetails, MembershipPlan, PaymentOrder, UserProfile } from '../types';
import { authService } from './authService';
import { membershipService } from './membershipService';
import { orderStore } from './orderStore';

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

    orderStore.add(newOrder);

    return newOrder;
  },

  /**
   * Admin approves manual bank transfer order
   */
  async approveTransferOrder(orderId: string, adminNotes?: string): Promise<PaymentOrder> {
    await new Promise((res) => setTimeout(res, 400));

    const order = orderStore.findById(orderId);
    if (!order) throw new Error('سفارش یافت نشد.');

    const details: ManualTransferDetails | undefined = order.manualTransferDetails
      ? {
          ...order.manualTransferDetails,
          adminReviewNote: adminNotes || 'فیش بانکی توسط کارشناس مالی تایید شد.',
          reviewedAt: new Date().toISOString(),
        }
      : undefined;

    const updated = orderStore.update(orderId, {
      status: 'completed',
      verifiedAt: new Date().toISOString(),
      manualTransferDetails: details,
    });
    if (!updated) throw new Error('سفارش یافت نشد.');

    // If current logged in user is the owner of this order, update their membership
    const currentUser = authService.getCurrentUser();
    if (currentUser && currentUser.id === updated.userId) {
      const plan = membershipService.getPlanById(updated.itemId);
      if (plan) {
        const membership = membershipService.createMembershipRecord(plan);
        authService.updateCurrentUser({ membership });
      }
    }

    return updated;
  },

  /**
   * Admin rejects manual bank transfer order with mandatory reason
   */
  async rejectTransferOrder(orderId: string, reason: string): Promise<PaymentOrder> {
    if (!reason || reason.trim().length === 0) {
      throw new Error('علت رد فیش واریزی الزامی است.');
    }

    await new Promise((res) => setTimeout(res, 400));

    const order = orderStore.findById(orderId);
    if (!order) throw new Error('سفارش یافت نشد.');

    const details: ManualTransferDetails | undefined = order.manualTransferDetails
      ? {
          ...order.manualTransferDetails,
          adminReviewNote: reason,
          reviewedAt: new Date().toISOString(),
        }
      : undefined;

    const updated = orderStore.update(orderId, {
      status: 'rejected',
      manualTransferDetails: details,
    });
    if (!updated) throw new Error('سفارش یافت نشد.');

    return updated;
  },
};
