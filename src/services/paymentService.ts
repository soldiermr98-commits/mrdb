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
 * v1.2.0 (prelive 18-b): initiate/verify قرارداد فریز v1.1.0 را دنبال می‌کنند و
 * تاریخچهٔ سفارش واقعی از GET /me/orders (fetchOrders) خوانده می‌شود.
 */

import { Course, MembershipPlan, OrderStatus, PaymentGatewayType, PaymentOrder, UserProfile } from '../types';
import { ApiError, apiFetch, isRealApiMode } from './apiClient';
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

// ---------------------------------------------------------------------------
// Hardening (prelive 18-b — یافتهٔ 1-9): تاریخچهٔ سفارش واقعی از سرور.
// برچسب‌های فارسی وضعیت/درگاه برای نمایش واحد در داشبورد (دمو هم از همین‌ها
// در رندر استفاده می‌کند تا متن‌ها همیشه یکسان بمانند).
// ---------------------------------------------------------------------------
export const ORDER_STATUS_LABELS: Record<string, string> = {
  pending: 'در انتظار پرداخت',
  verifying: 'در حال بررسی',
  completed: 'تکمیل شده',
  failed: 'ناموفق',
  cancelled: 'لغو شده',
  rejected: 'رد شده',
  // وضعیت‌های محلی دمو (فاز ۳) — بی‌برچسب نمانند:
  processing: 'در حال بررسی',
};

export const ORDER_GATEWAY_LABELS: Record<string, string> = {
  zibal: 'درگاه زیبال',
  card_to_card: 'کارت به کارت',
};

/** شکل خام سفارش از «GET /me/orders» — افزونهٔ v1.2.0 (قرارداد فریز). */
interface ApiOrderShape {
  id?: unknown;
  orderNumber?: unknown;
  itemTitle?: unknown;
  amountToman?: unknown;
  gateway?: unknown;
  status?: unknown;
  createdAt?: unknown;
  verifiedAt?: unknown;
  refNumber?: unknown;
}

const API_ORDER_GATEWAYS: PaymentGatewayType[] = ['zibal', 'card_to_card'];
const API_ORDER_STATUSES: OrderStatus[] = [
  'pending',
  'verifying',
  'completed',
  'failed',
  'cancelled',
  'rejected',
];

function normalizeApiOrder(raw: ApiOrderShape, fallbackUserId: string): PaymentOrder {
  const createdAt = typeof raw.createdAt === 'string' && raw.createdAt ? raw.createdAt : new Date().toISOString();
  const verifiedAt = typeof raw.verifiedAt === 'string' && raw.verifiedAt ? raw.verifiedAt : undefined;
  return {
    id: typeof raw.id === 'string' && raw.id ? raw.id : `ord-unknown-${createdAt}`,
    orderNumber: typeof raw.orderNumber === 'string' ? raw.orderNumber : '',
    // سفارش متعلق به کاربرِ صاحب Bearer است؛ شناسه از سشن فعلی پر می‌شود.
    userId: fallbackUserId,
    userPhone: authService.getCurrentUser()?.phoneNumber ?? '',
    userFullName: authService.getCurrentUser()?.fullName ?? '',
    itemType: 'membership',
    itemId: '',
    itemTitle: typeof raw.itemTitle === 'string' ? raw.itemTitle : 'سفارش',
    amountToman:
      typeof raw.amountToman === 'number' && Number.isFinite(raw.amountToman) && raw.amountToman >= 0
        ? Math.round(raw.amountToman)
        : 0,
    gateway: API_ORDER_GATEWAYS.includes(raw.gateway as PaymentGatewayType)
      ? (raw.gateway as PaymentGatewayType)
      : 'zibal',
    status: API_ORDER_STATUSES.includes(raw.status as OrderStatus)
      ? (raw.status as OrderStatus)
      : 'pending',
    createdAt,
    createdAtJalali: formatJalali(createdAt, true),
    verifiedAt,
    zibalRefNumber: typeof raw.refNumber === 'string' && raw.refNumber ? raw.refNumber : undefined,
  };
}

/**
 * v1.4.0 — پارسر مشترک پاسخ «POST /payment/zibal/initiate» (قرارداد فریز افزونه):
 * {orderId, orderNumber, trackId, paymentUrl} — نام فیلد «paymentUrl» عیناً از
 * class-payment-controller.php مصرف می‌شود (gatewayUrl فقط fallback). هم پلن و
 * هم خرید تک‌دوره‌ای (product_id) از همین پارسر استفاده می‌کنند.
 */
interface ZibalInitiateResponse {
  orderId?: unknown;
  orderNumber?: unknown;
  trackId?: unknown;
  paymentUrl?: unknown;
  gatewayUrl?: unknown;
}

function parseZibalInitiateResponse(res: ZibalInitiateResponse): {
  trackId: string;
  paymentUrl: string;
} {
  const trackId = typeof res.trackId === 'string' ? res.trackId : '';
  const paymentUrl =
    (typeof res.paymentUrl === 'string' && res.paymentUrl) ||
    (typeof res.gatewayUrl === 'string' && res.gatewayUrl) ||
    '';
  if (!paymentUrl) {
    throw new Error('لینک پرداخت از سرور دریافت نشد؛ لطفاً دوباره تلاش کنید.');
  }
  return { trackId, paymentUrl };
}

export const paymentService = {
  /**
   * Hardening (prelive 18-b — یافتهٔ 1-9): تاریخچهٔ سفارش‌های واقعی کاربر از سرور.
   * GET /me/orders (Bearer) → {orders: [...]} — فقط در حالت Real صدا زده می‌شود؛
   * در دمو داشبورد همان orderStore محلی را می‌خواند.
   */
  async fetchOrders(): Promise<PaymentOrder[]> {
    if (!isRealApiMode) {
      // دمو: همان منبع قبلی (orderStore) — فهرست سفارش‌های کاربر فعلی.
      const uid = authService.getCurrentUser()?.id ?? '';
      return orderStore.getByUser(uid);
    }
    const user = authService.getCurrentUser();
    const res = await apiFetch<{ orders?: unknown }>('/me/orders', { auth: true });
    const list = Array.isArray(res.orders) ? (res.orders as ApiOrderShape[]) : [];
    return list.map((o) => normalizeApiOrder(o, user?.id ?? ''));
  },

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
    // ----------------------------------------------------------------------
    // v1.1.0 Real-API: POST /payment/zibal/initiate {plan_id} (snake_case دقیق PHP)
    // پاسخ PHP: {orderId, orderNumber, trackId, paymentUrl} — نام فیلد «paymentUrl»
    // عیناً از class-payment-controller.php مصرف می‌شود (gatewayUrl فقط fallback).
    // سفارش واقعی سمت سرور ثبت می‌شود؛ رکورد محلی فقط برای UI است و در orderStore ذخیره نمی‌شود.
    // ----------------------------------------------------------------------
    if (isRealApiMode) {
      const res = await apiFetch<ZibalInitiateResponse>('/payment/zibal/initiate', {
        method: 'POST',
        auth: true,
        body: { plan_id: plan.id },
      });

      const { trackId, paymentUrl } = parseZibalInitiateResponse(res);

      const createdAt = new Date().toISOString();
      const gatewayOrder: PaymentOrder = {
        id: typeof res.orderId === 'string' && res.orderId ? res.orderId : `ord-zibal-${trackId}`,
        orderNumber: typeof res.orderNumber === 'string' ? res.orderNumber : '',
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
        createdAtJalali: formatJalali(createdAt, true),
        zibalTrackId: trackId,
        planSnapshot: { tier: plan.tier, durationDays: planDurationToDays(plan.duration) },
      };

      return { order: gatewayOrder, zibalTrackId: trackId, gatewayUrl: paymentUrl };
    }

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
   * v1.4.0 — خرید مستقیم یک دوره با درگاه زیبال (قرارداد فریز افزونهٔ 1.5.0):
   * POST /payment/zibal/initiate {product_id: <int>} — به‌جای plan_id؛ پاسخ دقیقاً
   * همان {orderId, orderNumber, trackId, paymentUrl} است. پس از تایید پرداخت،
   * سرور سفارش WooCommerce واقعی (processing) می‌سازد → پرچم owned فوراً باز می‌شود.
   * خطاهای فارسی سرور (404 محصول / 409 قبلاً خریداری‌شده) از apiFetch پاس داده می‌شوند.
   *
   * سفارش برگشتی فقط برای UI است و در orderStore ذخیره نمی‌شود (آینهٔ محلیِ مسیر پلن؛
   * دفتر مالی واقعی سرور است) و planSnapshot ندارد (مخصوص اشتراک است).
   *
   * حالت دمو: پرتاب خطای فارسی روشن — خرید تک‌دوره‌ای فقط در حالت Real معنا دارد
   * (شبیه‌ساز زیبال و گرنت محلی فقط برای اشتراک کار می‌کنند؛ مسیر دموی جعلی برای
   * دوره، تایید/گرنت ندارد و کاربر را گمراه می‌کند). تصمیم برای پایداری تست‌ها ثبت شد.
   */
  async initiateCourseZibalPayment(
    user: UserProfile,
    course: Course
  ): Promise<{ order: PaymentOrder; zibalTrackId: string; gatewayUrl: string }> {
    if (!isRealApiMode) {
      throw new Error('خرید آنلاین دوره در حالت دمو فعال نیست');
    }

    const productId = typeof course.productId === 'number' && Number.isFinite(course.productId) && course.productId > 0
      ? Math.round(course.productId)
      : null;
    if (!productId) {
      throw new Error('این دوره قابل خرید مستقیم نیست؛ لطفاً با پشتیبانی تماس بگیرید.');
    }

    const res = await apiFetch<ZibalInitiateResponse>('/payment/zibal/initiate', {
      method: 'POST',
      auth: true,
      // قرارداد فریز افزونهٔ 1.5.0: بدنهٔ snake_case با کلید product_id
      body: { product_id: productId },
    });

    const { trackId, paymentUrl } = parseZibalInitiateResponse(res);

    const createdAt = new Date().toISOString();
    const gatewayOrder: PaymentOrder = {
      id: typeof res.orderId === 'string' && res.orderId ? res.orderId : `ord-zibal-${trackId}`,
      orderNumber: typeof res.orderNumber === 'string' ? res.orderNumber : '',
      userId: user.id,
      userPhone: user.phoneNumber,
      userFullName: user.fullName,
      itemType: 'course',
      itemId: String(productId),
      itemTitle: course.title,
      amountToman: course.priceToman,
      gateway: 'zibal',
      status: 'pending',
      createdAt,
      createdAtJalali: formatJalali(createdAt, true),
      zibalTrackId: trackId,
    };

    return { order: gatewayOrder, zibalTrackId: trackId, gatewayUrl: paymentUrl };
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
    // v1.1.0 Real-API: شبیه‌ساز زیبال فقط در دمو اجرا می‌شود؛ تایید واقعی از طریق
    // کال‌بک درگاه (verifyZibalCallback در boot اپ) انجام می‌شود.
    if (isRealApiMode) {
      throw new Error('تایید پرداخت آنلاین در حالت اتصال واقعی، پس از بازگشت از درگاه به‌صورت خودکار انجام می‌شود.');
    }

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

  /**
   * v1.1.0 Real-API — تایید کال‌بک زیبال پس از بازگشت از درگاه.
   * قرارداد PHP (class-payment-controller.php): POST /payment/zibal/verify
   * {track_id, success, cb_token} →
   *  - موفق: {success: true, orderNumber, refNumber, membershipMode, membershipExpiresAt, message}
   *  - خطا (WP_Error): {code, message, data:{status}} → apiFetch همیشه Error با پیام فارسی پرتاب می‌کند.
   * عضویت سمت سرور فعال شده است؛ کلاینت فقط نتیجه را نشان می‌دهد و سشن را از /auth/me تازه می‌کند.
   */
  async verifyZibalCallback(params: {
    trackId: string;
    success: string;
    cbToken: string;
  }): Promise<{ success: boolean; message: string; orderNumber?: string; refNumber?: string }> {
    if (!isRealApiMode) {
      throw new Error('کال‌بک درگاه پرداخت فقط در حالت اتصال واقعی پردازش می‌شود.');
    }

    try {
      const res = await apiFetch<{
        success?: unknown;
        message?: unknown;
        orderNumber?: unknown;
        refNumber?: unknown;
      }>('/payment/zibal/verify', {
        method: 'POST',
        // دقیقاً snake_case قرارداد PHP
        body: { track_id: params.trackId, success: params.success, cb_token: params.cbToken },
      });
      return {
        success: res.success === true,
        message: typeof res.message === 'string' && res.message ? res.message : 'نتیجه تایید پرداخت از سرور دریافت شد.',
        orderNumber: typeof res.orderNumber === 'string' ? res.orderNumber : undefined,
        refNumber: typeof res.refNumber === 'string' ? res.refNumber : undefined,
      };
    } catch (e) {
      // WP_Errorها (لغو توسط کاربر/خطای مبلغ/...) همه Error با پیام فارسی‌اند؛
      // فقط دوباره غلاف می‌کنیم تا caller پیام سرور را مستقیم toast کند.
      if (e instanceof ApiError) throw e;
      throw e instanceof Error ? e : new Error('تایید پرداخت با خطا مواجه شد؛ در صورت کسر وجه با پشتیبانی تماس بگیرید.');
    }
  },
};
