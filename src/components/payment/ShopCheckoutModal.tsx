/**
 * @file components/payment/ShopCheckoutModal.tsx
 * @description v1.5.0 — مودال «خرید درون‌اپ محصول فیزیکی» (قرارداد افزونهٔ 1.6.0)
 *
 * فرم سبک اطلاعات ارسال (نام گیرنده، موبایل، نشانی پستی، کدپستی اختیاری) + خلاصهٔ
 * سفارش. پس از تایید، initiate سمت سرور (POST /payment/zibal/initiate
 * {product_id, kind:'shop', …}) لینک زیبال را برمی‌گرداند و کاربر با
 * window.location.href به درگاه می‌رود؛ در تاییدِ پرداخت، سفارشِ «واقعی
 * ووکامرس» با نشانی کامل ساخته می‌شود (ایمیل «سفارش جدید» ادمین).
 *
 * اصول:
 *  - بدون ورود، سفارش نمی‌توان ثبت کرد (هم‌الگوی CheckoutModal — دکمهٔ ورود سریع)
 *  - اعتبارسنجی سمت کلاینت با پیام فارسی دقیق زیر هر فیلد؛ ارقام فارسی/عربی
 *    موبایل و کدپستی پیش از سنجش انگلیسی می‌شوند (toEnglishDigits)
 *  - دکمهٔ پرداخت در طول فراخوانی شبکه قفل می‌شود (isSubmitting) تا دوبار
 *    initiate رخ ندهد
 *  - حالت دمو: سرویس خطای فارسی روشن پرتاب می‌کند (خرید آنلاین فقط Real است)
 */

import React, { useEffect, useState } from 'react';
import { ArrowLeft, CreditCard, MapPin, ShoppingBag, Truck } from 'lucide-react';
import { Modal } from '../common/Modal';
import { ShopProduct, ShopShippingInfo, UserProfile } from '../../types';
import { toEnglishDigits } from '../../utils/format';
import { paymentService } from '../../services/paymentService';

interface ShopCheckoutModalProps {
  isOpen: boolean;
  onClose: () => void;
  product: ShopProduct | null;
  currentUser: UserProfile | null;
  /** ورود سریع — مودال لاگین از App باز می‌شود (هم‌الگوی CheckoutModal) */
  onOpenLogin: () => void;
  /**
   * پرداخت — هم‌الگوی onBuyCourse: initiate سپس خروج به درگاه (Real) یا
   * toast خطا (دمو). مودال باز می‌ماند تا خروج/خطا مشخص شود.
   */
  onPay: (product: ShopProduct, shipping: ShopShippingInfo) => Promise<void>;
}

/** موبایل ایرانی → 09xxxxxxxxx یا '' */
function normalizeIrMobile(raw: string): string {
  let digits = toEnglishDigits(raw).replace(/\D+/g, '');
  if (digits.startsWith('0098')) digits = '0' + digits.slice(4);
  else if (digits.startsWith('98') && digits.length >= 12) digits = '0' + digits.slice(2);
  if (digits.startsWith('9') && digits.length === 10) digits = '0' + digits;
  return digits;
}

export const ShopCheckoutModal: React.FC<ShopCheckoutModalProps> = ({
  isOpen,
  onClose,
  product,
  currentUser,
  onOpenLogin,
  onPay,
}) => {
  const [fullName, setFullName] = useState('');
  const [phone, setPhone] = useState('');
  const [address, setAddress] = useState('');
  const [postalCode, setPostalCode] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [isSubmitting, setIsSubmitting] = useState(false);

  // پیش‌پرِ نام/موبایل از پروفایل — فقط فیلدِ خالی (تایپ کاربر هرگز بازنویسی
  // نمی‌شود)؛ بعد از لاگینِ درون‌مودالی هم دوباره هم‌گام می‌شود (isOpen/user در وابستگی).
  useEffect(() => {
    if (!isOpen || !currentUser) return;
    setFullName((v) => (v === '' ? currentUser.fullName ?? '' : v));
    setPhone((v) => (v === '' ? currentUser.phoneNumber ?? '' : v));
  }, [isOpen, currentUser]);

  if (!isOpen || !product) return null;

  const validate = (): ShopShippingInfo | null => {
    const nextErrors: Record<string, string> = {};
    const trimmedName = fullName.trim();
    const normalizedPhone = normalizeIrMobile(phone);
    const trimmedAddress = address.trim();
    const trimmedPostal = toEnglishDigits(postalCode).replace(/\D+/g, '').trim();

    if (trimmedName === '') {
      nextErrors.fullName = 'نام و نام خانوادگی گیرنده الزامی است.';
    }
    if (!/^09\d{9}$/.test(normalizedPhone)) {
      nextErrors.phone = 'شماره موبایل معتبر نیست (مثال: 09123456789).';
    }
    if (trimmedAddress.length < 10) {
      nextErrors.address = 'نشانی کامل پستی (استان، شهر، خیابان، پلاک) الزامی است.';
    }
    if (trimmedPostal !== '' && !/^\d{5,10}$/.test(trimmedPostal)) {
      nextErrors.postalCode = 'کد پستی معتبر نیست.';
    }

    setErrors(nextErrors);
    if (Object.keys(nextErrors).length > 0) return null;
    return {
      fullName: trimmedName,
      phone: normalizedPhone,
      address: trimmedAddress,
      postalCode: trimmedPostal,
    };
  };

  const handleSubmit = async () => {
    if (!currentUser) {
      onOpenLogin();
      return;
    }
    if (isSubmitting) return;
    const shipping = validate();
    if (!shipping) return;
    setIsSubmitting(true);
    try {
      await onPay(product, shipping);
    } finally {
      setIsSubmitting(false);
    }
  };

  const formatPrice = (price: number) => price.toLocaleString('fa-IR');

  const inputClass = (hasError: boolean) =>
    `w-full rounded-xl border px-3 py-2.5 text-xs text-stone-900 bg-white placeholder:text-stone-400 outline-none transition focus:ring-2 ${
      hasError
        ? 'border-red-300 focus:border-red-400 focus:ring-red-100'
        : 'border-stone-200 focus:border-amber-700 focus:ring-amber-100'
    }`;

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="خرید محصول"
      subtitle={`محصول: ${product.title}`}
      maxWidth="md"
    >
      <div className="space-y-4 text-xs">
        {/* خلاصهٔ سفارش */}
        <div className="bg-stone-50 border border-stone-200 rounded-xl p-3.5 space-y-2">
          <div className="flex items-start justify-between gap-3">
            <span className="font-bold text-amber-950 text-sm leading-snug flex items-start gap-1.5">
              <ShoppingBag className="w-4 h-4 text-amber-800 shrink-0 mt-0.5" />
              <span>{product.title}</span>
            </span>
          </div>
          <p className="text-stone-500 text-[11px] leading-relaxed flex items-start gap-1.5">
            <Truck className="w-3.5 h-3.5 text-amber-700 shrink-0 mt-0.5" />
            <span>
              پرداخت آنلاین با درگاه زیبال؛ پس از تایید، سفارش شما در سایت مستر دستباف ثبت می‌شود و برای هماهنگی ارسال با شما تماس گرفته می‌شود.
            </span>
          </p>
          <div className="pt-2 border-t border-stone-200 flex items-center justify-between">
            <span className="text-stone-500 font-medium">مبلغ قابل پرداخت:</span>
            <div className="flex items-baseline gap-1">
              <span className="text-base font-black text-amber-800 font-mono">
                {formatPrice(product.price)}
              </span>
              <span className="text-[11px] text-stone-500">تومان</span>
            </div>
          </div>
        </div>

        {/* فرم اطلاعات ارسال */}
        <div className="space-y-3">
          <label className="block font-semibold text-stone-800 flex items-center gap-1.5">
            <MapPin className="w-4 h-4 text-amber-700" />
            اطلاعات ارسال
          </label>

          <div className="space-y-1">
            <label htmlFor="shop-checkout-name" className="text-[11px] text-stone-600 font-medium">
              نام و نام خانوادگی گیرنده <span className="text-red-500">*</span>
            </label>
            <input
              id="shop-checkout-name"
              type="text"
              value={fullName}
              onChange={(e) => setFullName(e.target.value)}
              placeholder="مثلاً مریم محمدی"
              className={inputClass(!!errors.fullName)}
            />
            {errors.fullName && <p className="text-[10px] text-red-600">{errors.fullName}</p>}
          </div>

          <div className="space-y-1">
            <label htmlFor="shop-checkout-phone" className="text-[11px] text-stone-600 font-medium">
              شماره موبایل گیرنده <span className="text-red-500">*</span>
            </label>
            <input
              id="shop-checkout-phone"
              type="tel"
              inputMode="tel"
              dir="ltr"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="09123456789"
              className={`${inputClass(!!errors.phone)} text-left font-mono`}
            />
            {errors.phone && <p className="text-[10px] text-red-600">{errors.phone}</p>}
          </div>

          <div className="space-y-1">
            <label htmlFor="shop-checkout-address" className="text-[11px] text-stone-600 font-medium">
              نشانی کامل پستی <span className="text-red-500">*</span>
            </label>
            <textarea
              id="shop-checkout-address"
              rows={3}
              value={address}
              onChange={(e) => setAddress(e.target.value)}
              placeholder="استان، شهر، خیابان، کوچه، پلاک، واحد"
              className={`${inputClass(!!errors.address)} resize-none leading-relaxed`}
            />
            {errors.address && <p className="text-[10px] text-red-600">{errors.address}</p>}
          </div>

          <div className="space-y-1">
            <label htmlFor="shop-checkout-postal" className="text-[11px] text-stone-600 font-medium">
              کد پستی <span className="text-stone-400">(اختیاری)</span>
            </label>
            <input
              id="shop-checkout-postal"
              type="text"
              inputMode="numeric"
              dir="ltr"
              value={postalCode}
              onChange={(e) => setPostalCode(e.target.value)}
              placeholder="1234567890"
              className={`${inputClass(!!errors.postalCode)} text-left font-mono`}
            />
            {errors.postalCode && <p className="text-[10px] text-red-600">{errors.postalCode}</p>}
          </div>
        </div>

        {/* شیوه پرداخت — فقط زیبال (هم‌الگوی خرید دوره) */}
        <div>
          <label className="block font-semibold text-stone-800 mb-2">شیوه پرداخت:</label>
          <div className="flex items-start gap-3 p-3 rounded-xl border border-amber-700 bg-amber-50/40 ring-1 ring-amber-700/20">
            <CreditCard className="w-4 h-4 text-amber-700 shrink-0 mt-1" />
            <div className="flex-1">
              <div className="flex items-center justify-between">
                <span className="font-bold text-stone-900">درگاه پرداخت آنلاین زیبال (Zibal)</span>
                <span className="text-[10px] text-emerald-700 font-semibold bg-emerald-50 px-2 py-0.5 rounded-full">
                  ثبت سفارش آنی
                </span>
              </div>
              <p className="text-stone-500 text-[11px] mt-0.5">
                پرداخت با کلیه کارت‌های عضو شبکه شتاب
              </p>
            </div>
          </div>
        </div>

        {/* حالت بدون ورود */}
        {!currentUser && (
          <div className="bg-stone-100 p-2.5 rounded-xl text-stone-600 text-[11px] flex items-center justify-between">
            <span>برای ثبت سفارش ابتدا باید شماره موبایل خود را تایید کنید.</span>
            <button
              onClick={onOpenLogin}
              className="text-amber-800 font-bold hover:underline"
            >
              ورود سریع
            </button>
          </div>
        )}

        {/* CTA */}
        <div className="pt-2">
          <button
            onClick={handleSubmit}
            disabled={isSubmitting}
            className="w-full bg-amber-800 hover:bg-amber-900 disabled:opacity-60 disabled:cursor-not-allowed text-white font-bold py-3 rounded-xl text-xs transition shadow-sm flex items-center justify-center gap-2"
          >
            <span>
              {isSubmitting
                ? 'در حال اتصال به درگاه…'
                : currentUser
                  ? 'انتقال به درگاه پرداخت زیبال'
                  : 'ورود به حساب و ادامه خرید'}
            </span>
            {!isSubmitting && <ArrowLeft className="w-4 h-4" />}
          </button>
        </div>
      </div>
    </Modal>
  );
};
