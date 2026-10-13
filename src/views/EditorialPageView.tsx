/**
 * @file views/EditorialPageView.tsx
 * @description Reader view for WordPress editorial pages (about us, guides, FAQ)
 */

import React, { useState, useEffect, useCallback } from 'react';
import { ArrowRight, Calendar, BookOpen } from 'lucide-react';
import { EditorialPage } from '../types';
import { adminService } from '../services/adminService';
import { AsyncState } from '../components/common/AsyncState';

interface EditorialPageViewProps {
  slug: string;
  onNavigate: (view: string) => void;
}

export const EditorialPageView: React.FC<EditorialPageViewProps> = ({ slug, onNavigate }) => {
  const [page, setPage] = useState<EditorialPage | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);

  const loadPage = useCallback(() => {
    setIsLoading(true);
    setLoadError(false);
    // BUG-43b: the old effect had no catch — a rejected load kept the raw
    // «در حال بارگذاری صفحه...» text forever with no recovery path.
    adminService
      .getEditorialPageBySlug(slug || 'about-us')
      .then((p) => {
        setPage(p);
        setIsLoading(false);
      })
      .catch(() => {
        setLoadError(true);
        setIsLoading(false);
      });
  }, [slug]);

  useEffect(() => {
    loadPage();
  }, [loadPage]);

  if (isLoading) {
    return <AsyncState status="loading" loadingLabel="در حال بارگذاری صفحه..." />;
  }

  if (loadError) {
    // BUG-43b: explicit error card with retry
    return (
      <div className="max-w-3xl mx-auto px-4 py-6 space-y-4">
        <button
          onClick={() => onNavigate('home')}
          className="flex items-center gap-1.5 text-xs font-semibold text-stone-600 hover:text-amber-850 transition"
        >
          <ArrowRight className="w-4 h-4" />
          <span>بازگشت به صفحه اصلی</span>
        </button>
        <AsyncState
          status="error"
          error="بارگذاری صفحه با خطا مواجه شد؛ لطفاً دوباره تلاش کنید."
          onRetry={loadPage}
        />
      </div>
    );
  }

  if (!page) {
    // BUG-43b: an unknown slug no longer pretends to load forever — explicit
    // not-found card with a way back home.
    return (
      <div className="max-w-3xl mx-auto px-4 py-6 space-y-4">
        <button
          onClick={() => onNavigate('home')}
          className="flex items-center gap-1.5 text-xs font-semibold text-stone-600 hover:text-amber-850 transition"
        >
          <ArrowRight className="w-4 h-4" />
          <span>بازگشت به صفحه اصلی</span>
        </button>
        <AsyncState
          status="empty"
          emptyTitle="صفحه یافت نشد"
          emptyDescription="صفحه‌ای با این نشانی وجود ندارد یا جابه‌جا شده است."
        />
      </div>
    );
  }

  return (
    <div className="max-w-3xl mx-auto px-4 py-6 space-y-6 pb-28">
      <button
        onClick={() => onNavigate('home')}
        className="flex items-center gap-1.5 text-xs font-semibold text-stone-600 hover:text-amber-850 transition"
      >
        <ArrowRight className="w-4 h-4" />
        <span>بازگشت به صفحه اصلی</span>
      </button>

      <div className="bg-white rounded-3xl border border-stone-200/90 p-6 sm:p-8 shadow-xs space-y-4">
        <div className="space-y-2 border-b border-stone-100 pb-4">
          <div className="flex items-center gap-2 text-[11px] text-stone-500">
            <span className="text-amber-800 font-semibold">مستر دستباف</span>
            <span>·</span>
            <span className="flex items-center gap-1">
              <Calendar className="w-3.5 h-3.5 text-stone-400" />
              آخرین ویرایش: {page.lastUpdatedJalali}
            </span>
          </div>

          <h1 className="text-xl sm:text-2xl font-black text-amber-950">{page.title}</h1>
          <p className="text-xs text-stone-500 leading-relaxed">{page.excerpt}</p>
        </div>

        {/* Content Body with Persian Paragraphs */}
        <div className="text-xs sm:text-sm text-stone-700 leading-loose space-y-4 whitespace-pre-line font-normal">
          {page.content}
        </div>
      </div>
    </div>
  );
};
