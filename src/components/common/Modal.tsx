/**
 * @file components/common/Modal.tsx
 * @description Accessible Modal overlay container
 */

import React, { useEffect, useId, useRef } from 'react';
import { X } from 'lucide-react';
import { acquireScrollLock } from '../../utils/scrollLock';

// v1.5.7 (گزارش کارفرما «کیبورد روی پاپ‌آپ لاگین می‌افتد»):
// در موبایل مودال‌ها به‌جای وسط صفحه، به‌صورت Bottom Sheet از پایین باز می‌شوند
// (الگوی استاندارد اپ‌های مدرن). با interactive-widget=resizes-content در
// viewport متا (index.html) + واحد dvh، وقتی کیبورد باز می‌شود ارتفاع
// viewport واقعاً کوچک می‌شود و شیت همیشه بالای کیبورد می‌ماند — هیچ ورودی
// زیر کیبورد پنهان نمی‌شود. دسکتاپ بدون تغییر (مودال وسط‌چین سابق).
const MOBILE_SHEET_OVERLAY =
  'items-end sm:items-center justify-center sm:p-4 p-0 sm:bg-stone-900/60 bg-stone-900/60';
const MOBILE_SHEET_PANEL =
  'sm:rounded-2xl rounded-t-3xl max-h-[92dvh] sm:max-h-[80vh] w-full sm:w-auto sm:my-auto animate-in slide-in-from-bottom-4 sm:slide-in-from-bottom-0 sm:zoom-in-95 duration-200';

interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  maxWidth?: 'sm' | 'md' | 'lg' | 'xl' | '2xl';
  // v1.2.7 (فیکس «فرم ورود پشت فرم خرید»): همهٔ مودال‌ها z-50 بودند و ترتیب DOM
  // تعیین می‌کرد کدام بالاست — مودال ورود قبل از فرم خرید در DOM است و زیرش می‌افتاد.
  // اکنون هر مودال می‌تواند لایهٔ بالاتری بگیرد (ورود = z-[70]).
  overlayClassName?: string;
}

/** BUG-56: selector used by the focus trap to cycle Tab within the dialog. */
const FOCUSABLE_SELECTOR =
  'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])';

export const Modal: React.FC<ModalProps> = ({
  isOpen,
  onClose,
  title,
  subtitle,
  children,
  maxWidth = 'md',
  overlayClassName = '',
}) => {
  const titleId = useId();
  const containerRef = useRef<HTMLDivElement>(null);
  // v1.2.2 (hotfix پرش فوکس): onClose در ref نگه داشته می‌شود تا effect فقط به isOpen
  // وابسته بماند. قبلاً onClose در deps بود و چون فراخوان‌ها تابع بی‌نام inline می‌دهند،
  // هر کلید تایپ در فرم داخل مودال → re-render → onClose جدید → effect دوباره →
  // containerRef.focus() فوکس را از input می‌دزدید (گزارش کارفرما: ورود OTP).
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  useEffect(() => {
    if (!isOpen) return;

    // BUG-55: ref-counted body scroll lock — stacked modals no longer clobber
    // each other's `overflow` on unmount (restored only when the last one closes).
    const releaseScrollLock = acquireScrollLock();

    // v1.5.7: وقتی ورودی داخل مودال فوکس می‌گیرد (کیبورد موبایل باز می‌شود)،
    // عنصر فوکوس‌شده با scrollIntoView نزدیک‌ترین نقطه ممکن به دید می‌آید تا
    // هیچ‌وقت زیر کیبورد نرود (مکمل resizes-content — برای iOS Safari هم که
    // viewport را واقعاً کوچک می‌کند همان کافی است؛ این فقط هموارسازی است).
    const handleFocusIn = (e: FocusEvent) => {
      const container = containerRef.current;
      const target = e.target as HTMLElement | null;
      if (!container || !target || !container.contains(target)) return;
      if (!(target instanceof HTMLInputElement) && !(target instanceof HTMLTextAreaElement)) return;
      window.setTimeout(() => {
        target.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }, 120);
    };
    document.addEventListener('focusin', handleFocusIn);

    // BUG-56: move focus into the dialog on open.
    containerRef.current?.focus();

    // BUG-56: Esc closes + Tab cycles within the dialog (listener lives on
    // document while the modal is open).
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        onCloseRef.current();
        return;
      }
      if (e.key !== 'Tab') return;

      const container = containerRef.current;
      if (!container) return;
      const focusables = Array.from(
        container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)
      ).filter((el) => el.offsetParent !== null);

      if (focusables.length === 0) {
        e.preventDefault();
        container.focus();
        return;
      }
      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const active = document.activeElement as HTMLElement | null;
      const isInside = !!active && container.contains(active);
      if (e.shiftKey) {
        if (!isInside || active === first) {
          e.preventDefault();
          last.focus();
        }
      } else if (!isInside || active === last) {
        e.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    document.addEventListener('focusin', handleFocusIn);
    return () => {
      releaseScrollLock();
      document.removeEventListener('keydown', handleKeyDown);
      document.removeEventListener('focusin', handleFocusIn);
    };
  }, [isOpen]);

  if (!isOpen) return null;

  const widthClasses = {
    sm: 'max-w-sm',
    md: 'max-w-md',
    lg: 'max-w-lg',
    xl: 'max-w-xl',
    '2xl': 'max-w-2xl',
  }[maxWidth];

  return (
    // BUG-56: backdrop click now closes the modal (inner content stops propagation).
    // v1.2.7: overlayClassName برای کنترل لایهٔ مودال‌های روی‌هم (پیش‌فرض z-50)
    // v1.5.7: موبایل = Bottom Sheet (لبه‌های گرد فقط بالا + چسبیده به پایین)؛
    // دسکتاپ = همان مودال وسط‌چین قبلی. overflow-y-auto روی overlay برای
    // دسکتاپ می‌ماند؛ در موبایل خود پنل اسکرول داخلی دارد (max-h + overflow).
    <div
      className={`fixed inset-0 z-50 flex ${MOBILE_SHEET_OVERLAY} backdrop-blur-xs animate-in fade-in duration-150 overflow-y-auto ${overlayClassName}`}
      onClick={onClose}
    >
      <div
        ref={containerRef}
        tabIndex={-1}
        // BUG-56: dialog semantics + labelled-by wiring for screen readers.
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={`w-full ${widthClasses} ${MOBILE_SHEET_PANEL} bg-white shadow-2xl border border-stone-200 overflow-hidden text-stone-800 focus:outline-none`}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div className="flex items-center justify-between p-4.5 border-b border-stone-100 bg-[#fdfbf7]">
          <div>
            <h3 id={titleId} className="text-base font-bold text-amber-950">{title}</h3>
            {subtitle && <p className="text-xs text-stone-500 mt-0.5">{subtitle}</p>}
          </div>
          <button
            onClick={onClose}
            className="p-1.5 rounded-xl text-stone-400 hover:text-stone-700 hover:bg-stone-200/60 transition"
            aria-label="بستن"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* v1.5.7: اسکرول داخلی — در موبایل با dvh زیر کیبورد هم درست می‌ماند */}
        <div className="p-5 overflow-y-auto max-h-[calc(92dvh-64px)] sm:max-h-[calc(80vh-76px)]">{children}</div>
      </div>
    </div>
  );
};
