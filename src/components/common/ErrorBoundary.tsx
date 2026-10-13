/**
 * @file components/common/ErrorBoundary.tsx
 * @description DEV-3: global render-error boundary with a Persian fallback card.
 *
 * Catches render/lifecycle exceptions anywhere below <App/> so a single broken
 * view never blanks the whole PWA. Wrap is applied inside main.tsx render
 * (around <App/>, not around the root call itself).
 */

import React from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';

interface ErrorBoundaryProps {
  children: React.ReactNode;
}

interface ErrorBoundaryState {
  hasError: boolean;
}

export class ErrorBoundary extends React.Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false };
  }

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { hasError: true };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo): void {
    // DEV-3: log for diagnostics — prototype has no remote error sink yet.
    console.error('[ErrorBoundary] خطای غیرمنتظره در رابط کاربری:', error, info.componentStack);
  }

  render(): React.ReactNode {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center p-6 bg-[#fdfbf7]" dir="rtl">
          <div className="bg-white rounded-3xl border border-stone-200 shadow-lg p-8 max-w-sm w-full text-center space-y-4">
            <div className="w-14 h-14 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mx-auto">
              <AlertTriangle className="w-7 h-7" />
            </div>
            <div className="space-y-1.5">
              <h1 className="text-base font-black text-stone-900">خطایی رخ داد</h1>
              <p className="text-xs text-stone-600 leading-relaxed">
                مشکلی در نمایش این بخش پیش آمد. با بارگذاری مجدد صفحه، تلاش کنید دوباره وارد شوید.
              </p>
            </div>
            <button
              onClick={() => window.location.reload()}
              className="w-full min-h-11 bg-amber-800 hover:bg-amber-900 text-white text-xs font-bold px-4 py-3 rounded-xl transition flex items-center justify-center gap-2"
            >
              <RefreshCw className="w-4 h-4" />
              <span>بارگذاری مجدد صفحه</span>
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}

export default ErrorBoundary;
