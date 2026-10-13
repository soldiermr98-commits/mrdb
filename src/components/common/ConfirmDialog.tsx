/**
 * @file components/common/ConfirmDialog.tsx
 * @description Audit-friendly confirmation dialog for sensitive admin operations
 */

import React from 'react';
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

  return (
    <Modal isOpen={isOpen} onClose={onClose} title={title} maxWidth="sm">
      <div className="space-y-4 text-sm">
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
