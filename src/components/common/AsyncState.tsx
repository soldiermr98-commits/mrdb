/**
 * @file components/common/AsyncState.tsx
 * @description DEV-3: consistent loading / error / empty / success building block.
 *
 * One shared, RTL, mobile-friendly presentation for the three async outcomes
 * (spinner, error card with retry, empty-state card) so every view stops
 * hand-rolling its own variant. Amber/emerald palette, no blue/indigo.
 */

import React from 'react';
import { AlertTriangle, Inbox, RefreshCw } from 'lucide-react';

export type AsyncStatus = 'loading' | 'error' | 'empty' | 'success';

interface AsyncStateProps {
  status: AsyncStatus;
  /** Persian error message shown on the error card (status === 'error'). */
  error?: string;
  /** Retry handler — the retry button renders only when provided. */
  onRetry?: () => void;
  /** Title of the empty-state card (status === 'empty'). */
  emptyTitle?: string;
  /** Description of the empty-state card (status === 'empty'). */
  emptyDescription?: string;
  /** Spinner label (status === 'loading'). */
  loadingLabel?: string;
  /** Content rendered when status === 'success'. */
  children?: React.ReactNode;
}

export const AsyncState: React.FC<AsyncStateProps> = ({
  status,
  error,
  onRetry,
  emptyTitle = 'موردی یافت نشد',
  emptyDescription,
  loadingLabel = 'در حال بارگذاری...',
  children,
}) => {
  if (status === 'success') {
    return <>{children}</>;
  }

  if (status === 'loading') {
    return (
      <div className="py-14 text-center text-stone-500 text-xs" role="status" aria-live="polite">
        <div className="w-8 h-8 border-3 border-amber-800 border-t-transparent rounded-full animate-spin mx-auto mb-3" />
        {loadingLabel}
      </div>
    );
  }

  if (status === 'error') {
    return (
      <div className="bg-white rounded-2xl p-10 text-center border border-red-200 text-stone-500 space-y-3">
        <div className="w-12 h-12 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mx-auto">
          <AlertTriangle className="w-6 h-6" />
        </div>
        <h3 className="font-bold text-sm text-stone-800">بارگذاری با خطا مواجه شد</h3>
        <p className="text-xs text-stone-500 leading-relaxed">
          {error || 'مشکلی پیش آمد؛ لطفاً دوباره تلاش کنید.'}
        </p>
        {onRetry && (
          <button
            onClick={onRetry}
            className="inline-flex min-h-11 items-center gap-1.5 bg-amber-800 hover:bg-amber-900 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition"
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>تلاش مجدد</span>
          </button>
        )}
      </div>
    );
  }

  // status === 'empty'
  return (
    <div className="bg-white rounded-2xl p-10 text-center border border-stone-200 text-stone-500 space-y-2">
      <div className="w-12 h-12 rounded-2xl bg-stone-100 text-stone-400 flex items-center justify-center mx-auto mb-1">
        <Inbox className="w-6 h-6" />
      </div>
      <h3 className="font-bold text-sm text-stone-800">{emptyTitle}</h3>
      {emptyDescription && <p className="text-xs text-stone-500 leading-relaxed">{emptyDescription}</p>}
    </div>
  );
};

export default AsyncState;
