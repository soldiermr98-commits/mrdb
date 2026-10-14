/**
 * @file components/common/Modal.tsx
 * @description Accessible Modal overlay container
 */

import React, { useEffect, useId, useRef } from 'react';
import { X } from 'lucide-react';
import { acquireScrollLock } from '../../utils/scrollLock';

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
    return () => {
      releaseScrollLock();
      document.removeEventListener('keydown', handleKeyDown);
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
    <div
      className={`fixed inset-0 z-50 flex items-center justify-center p-4 bg-stone-900/60 backdrop-blur-xs animate-in fade-in duration-150 overflow-y-auto ${overlayClassName}`}
      onClick={onClose}
    >
      <div
        ref={containerRef}
        tabIndex={-1}
        // BUG-56: dialog semantics + labelled-by wiring for screen readers.
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        className={`w-full ${widthClasses} bg-white rounded-2xl shadow-2xl border border-stone-200 overflow-hidden my-auto animate-in zoom-in-95 duration-200 text-stone-800 focus:outline-none`}
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

        {/* Content */}
        <div className="p-5 max-h-[80vh] overflow-y-auto">{children}</div>
      </div>
    </div>
  );
};
