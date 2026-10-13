/**
 * @file components/common/ConfirmDialog.tsx
 * @description Audit-friendly confirmation dialog for sensitive admin operations
 */

import React, { useEffect, useRef } from 'react';
import { AlertTriangle, CheckCircle, XCircle } from 'lucide-react';
import { Modal } from './Modal';

interface ConfirmDialogProps {
  isOpen: boolean;
  onClose: () => void;
  onConfirm: () => void;
  title: string;
  description: string;
  confirmText?: string;
  cancelText?: string;
  variant?: 'danger' | 'warning' | 'primary';
  isLoading?: boolean;
}

export const ConfirmDialog: React.FC<ConfirmDialogProps> = ({
  isOpen,
  onClose,
  onConfirm,
  title,
  description,
  confirmText = 'تایید و ادامه',
  cancelText = 'انصراف',
  variant = 'warning',
  isLoading = false,
}) => {
  const icon = {
    danger: <XCircle className="w-6 h-6 text-red-600" />,
    warning: <AlertTriangle className="w-6 h-6 text-amber-600" />,
    primary: <CheckCircle className="w-6 h-6 text-emerald-600" />,
  }[variant];

  const confirmBtnClass = {
    danger: 'bg-red-700 hover:bg-red-800 text-white',
    warning: 'bg-amber-700 hover:bg-amber-800 text-white',
    primary: 'bg-emerald-700 hover:bg-emerald-800 text-white',
  }[variant];

  const confirmBtnRef = useRef<HTMLButtonElement>(null);

  // BUG-57: focus the confirm button when the dialog opens.
  useEffect(() => {
    if (isOpen) confirmBtnRef.current?.focus();
  }, [isOpen]);

  // BUG-57: Esc closes the confirm dialog — but only while not loading. The
  // listener runs in the capture phase and stops propagation so the base
  // Modal's Esc handler (which has no loading awareness) never fires first.
  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return;
      e.stopPropagation();
      if (!isLoading) onClose();
    };
    document.addEventListener('keydown', handleKeyDown, true);
    return () => document.removeEventListener('keydown', handleKeyDown, true);
  }, [isOpen, isLoading, onClose]);

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title} maxWidth="sm">
      {/* BUG-57: explicit dialog semantics for the confirmation surface. */}
      <div role="dialog" aria-modal="true" aria-label={title} className="space-y-4 text-sm">
        <div className="flex items-start gap-3">
          <div className="shrink-0 p-2 rounded-xl bg-stone-100">{icon}</div>
          <p className="text-stone-600 text-xs leading-relaxed mt-1">{description}</p>
        </div>

        <div className="flex items-center justify-end gap-2 pt-2 border-t border-stone-100">
          <button
            type="button"
            onClick={onClose}
            disabled={isLoading}
            className="px-4 py-2 rounded-xl text-xs font-medium text-stone-600 hover:bg-stone-100 transition"
          >
            {cancelText}
          </button>
          <button
            ref={confirmBtnRef}
            type="button"
            onClick={onConfirm}
            disabled={isLoading}
            className={`px-4 py-2 rounded-xl text-xs font-semibold shadow-xs transition ${confirmBtnClass} ${
              isLoading ? 'opacity-60 cursor-not-allowed' : ''
            }`}
          >
            {isLoading ? 'در حال ثبت...' : confirmText}
          </button>
        </div>
      </div>
    </Modal>
  );
};
