/**
 * @file views/EditorialPageView.tsx
 * @description Reader view for WordPress editorial pages (about us, guides, FAQ)
 */

import React, { useState, useEffect, useCallback } from 'react';
import { ArrowRight, Calendar, BookOpen, Lock, LogIn, Crown } from 'lucide-react';
import { EditorialPage } from '../types';
import { adminService } from '../services/adminService';
import { appPagesService } from '../services/appPagesService';
import { ApiError } from '../services/apiClient';
import { AsyncState } from '../components/common/AsyncState';

interface EditorialPageViewProps {
  slug: string;
  /** v1.2.6: وقتی ست باشد، محتوا از صفحات وب‌اپ پیشخوان (GET /app-pages/{id}/content) خوانده می‌شود */
  pageId?: string;
  onNavigate: (view: string, params?: { pageId?: string; slug?: string }) => void;
}

/** v1.2.8: افزودن پارامتر mrdapp=1 به نشانی برگه — افزونه با این پارامتر قالب
 *  Canvas (بدون هدر/فوتر سایت) رندر می‌کند و استایل المنتور کامل می‌ماند. */
function buildAppFrameUrl(url: string): string {
  try {
    const parsed = new URL(url, window.location.origin);
    parsed.searchParams.set('mrdapp', '1');
    return parsed.toString();
  } catch {
    return `${url}${url.includes('?') ? '&' : '?'}mrdapp=1`;
  }
}

export const EditorialPageView: React.FC<EditorialPageViewProps> = ({ slug, pageId, onNavigate }) => {
  const [page, setPage] = useState<EditorialPage | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  // v1.2.6: حالت «دسترسی ناکافی» — صفحهٔ ویژهٔ اعضا برای مهمان/بدون اشتراک
  const [accessDenied, setAccessDenied] = useState(false);
  // v1.2.6: HTML رندرشدهٔ برگهٔ وردپرس (فقط در حالت pageId — فال‌بک رندر iframe)
  const [apiHtml, setApiHtml] = useState<string | null>(null);
  // v1.2.8: نشانی برگه برای رندر iframe (فیکس استایل صفحات المنتور)
  const [frameUrl, setFrameUrl] = useState<string | null>(null);
  const [frameLoaded, setFrameLoaded] = useState(false);

  const loadPage = useCallback(() => {
    setIsLoading(true);
    setLoadError(false);
    setAccessDenied(false);

    // v1.2.6 — صفحات وب‌اپ پیشخوان: محتوای HTML رندرشدهٔ برگهٔ وردپرس
    if (pageId) {
      appPagesService
        .getPageContent(pageId)
        .then((content) => {
          setPage({
            slug: pageId,
            title: content.title,
            content: '',
            excerpt: 'صفحهٔ رسمی سایت مستر دستباف',
            lastUpdatedJalali: content.updatedAtJalali,
          });
          // v1.2.8: اگر سرور نشانی برگه را داد، رندر iframe (قالب Canvas افزونه)
          // استایل المنتور را سالم نگه می‌دارد؛ HTML فقط فال‌بک است.
          setFrameUrl(content.url ? buildAppFrameUrl(content.url) : null);
          setFrameLoaded(false);
          // HTML رندرشدهٔ سرور جداگانه نگه داشته می‌شود (dangerouslySetInnerHTML در رندر)
          setApiHtml(content.html);
          setIsLoading(false);
        })
        .catch((e: unknown) => {
          if (e instanceof ApiError && (e.status === 403 || e.status === 401)) {
            setAccessDenied(true);
          } else {
            setLoadError(true);
          }
          setIsLoading(false);
        });
      return;
    }

    // BUG-43b: the old effect had no catch — a rejected load kept the raw
    // «در حال بارگذاری صفحه...» text forever with no recovery path.
    adminService
      .getEditorialPageBySlug(slug || 'about-us')
      .then((p) => {
        setApiHtml(null);
        setPage(p);
        setIsLoading(false);
      })
      .catch(() => {
        setLoadError(true);
        setIsLoading(false);
      });
  }, [slug, pageId]);

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

  // v1.2.6: سطح دسترسی ناکافی — صفحهٔ «اعضای فعال»/«مدیر کل» با ۴۰۳ سرور
  if (accessDenied) {
    return (
      <div className="max-w-3xl mx-auto px-4 py-6 space-y-4">
        <button
          onClick={() => onNavigate('home')}
          className="flex items-center gap-1.5 text-xs font-semibold text-stone-600 hover:text-amber-850 transition"
        >
          <ArrowRight className="w-4 h-4" />
          <span>بازگشت به صفحه اصلی</span>
        </button>
        <div className="bg-white rounded-3xl border border-amber-200 p-8 text-center space-y-4 shadow-xs">
          <div className="w-14 h-14 rounded-full bg-amber-100 text-amber-800 flex items-center justify-center mx-auto">
            <Lock className="w-7 h-7" />
          </div>
          <div className="space-y-1">
            <h1 className="text-lg font-black text-amber-950">دسترسی به این صفحه محدود است</h1>
            <p className="text-xs text-stone-600 leading-relaxed">
              این صفحه فقط برای اعضای فعال (یا سطح دسترسی بالاتر) منتشر شده است. برای مشاهده، وارد حساب شوید یا اشتراک فعال تهیه کنید.
            </p>
          </div>
          <div className="flex flex-wrap items-center justify-center gap-3 pt-1">
            <button
              onClick={() => onNavigate('membership')}
              className="bg-amber-800 hover:bg-amber-900 text-white font-bold px-4 py-2.5 rounded-xl text-xs flex items-center gap-1.5 transition"
            >
              <Crown className="w-4 h-4" />
              <span>مشاهده پلن‌های اشتراک</span>
            </button>
            <button
              onClick={() => onNavigate('dashboard')}
              className="bg-stone-100 hover:bg-stone-200 text-stone-800 font-semibold px-4 py-2.5 rounded-xl text-xs flex items-center gap-1.5 transition"
            >
              <LogIn className="w-4 h-4" />
              <span>داشبورد من</span>
            </button>
          </div>
        </div>
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

        {/* Content Body — v1.2.8: اولویت رندر iframe (قالب Canvas — فیکس استایل صفحات
            المنتور)؛ فال‌بک: HTML رندرشدهٔ سرور (pageId) یا متن سرمقاله‌ای دمو */}
        {frameUrl ? (
          <div className="relative rounded-2xl overflow-hidden border border-stone-200 bg-stone-100">
            {!frameLoaded && (
              <div className="absolute inset-0 flex items-center justify-center bg-white" aria-hidden="true">
                <div className="w-8 h-8 border-3 border-amber-700 border-t-transparent rounded-full animate-spin" />
              </div>
            )}
            <iframe
              src={frameUrl}
              title={page.title}
              onLoad={() => setFrameLoaded(true)}
              className="w-full h-[78vh] min-h-[420px] block bg-white"
              referrerPolicy="no-referrer-when-downgrade"
            />
          </div>
        ) : apiHtml ? (
          <div
            className="mrd-wp-page text-xs sm:text-sm text-stone-700 leading-loose [&_a]:text-amber-800 [&_a]:underline [&_h1]:text-lg [&_h1]:font-black [&_h1]:text-amber-950 [&_h2]:text-base [&_h2]:font-bold [&_h2]:text-amber-950 [&_h3]:text-sm [&_h3]:font-bold [&_h3]:text-stone-900 [&_p]:my-3 [&_ul]:list-disc [&_ul]:pe-5 [&_ul]:space-y-1 [&_ol]:list-decimal [&_ol]:pe-5 [&_ol]:space-y-1 [&_img]:rounded-xl [&_img]:max-w-full [&_img]:h-auto [&_blockquote]:border-e-4 [&_blockquote]:border-amber-300 [&_blockquote]:bg-amber-50/60 [&_blockquote]:px-3 [&_blockquote]:py-2 [&_blockquote]:rounded-lg [&_table]:w-full [&_table]:text-[11px] [&_th]:border [&_th]:border-stone-200 [&_th]:p-2 [&_td]:border [&_td]:border-stone-200 [&_td]:p-2"
            dangerouslySetInnerHTML={{ __html: apiHtml }}
          />
        ) : (
          <div className="text-xs sm:text-sm text-stone-700 leading-loose space-y-4 whitespace-pre-line font-normal">
            {page.content}
          </div>
        )}
      </div>
    </div>
  );
};
