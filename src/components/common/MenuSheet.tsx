/**
 * @file components/common/MenuSheet.tsx
 * @description شیت شیشه‌ای «منو» (v1.2.6) — صفحات سایت از پیشخوان + راهنماها + میان‌برها
 *
 * طراحی هم‌خانواده با گلس‌مورفیسم v1.2.5: پنل پایین شیشه‌ای با بلور قوی،
 * رعایت safe-area و اسکرول داخلی برای فهرست بلند.
 */

import React from 'react';
import { X, FileText, Info, BookMarked, HelpCircle, Crown, ShieldCheck } from 'lucide-react';
import { AppPage, UserRole } from '../../types';
import { isRealApiMode } from '../../services/apiClient';

interface MenuSheetProps {
  isOpen: boolean;
  onClose: () => void;
  appPages: AppPage[];
  userRole?: UserRole;
  onNavigate: (view: string, params?: { pageId?: string; slug?: string }) => void;
}

const rowClass =
  'w-full flex items-center gap-3 px-4 py-3.5 min-h-[48px] rounded-2xl text-right transition text-stone-800 hover:bg-white/70 hover:text-amber-900 focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:outline-none';

const rowIcon = 'w-[18px] h-[18px] text-amber-800 shrink-0';

export const MenuSheet: React.FC<MenuSheetProps> = ({
  isOpen,
  onClose,
  appPages,
  userRole,
  onNavigate,
}) => {
  if (!isOpen) return null;

  const go = (view: string, params?: { pageId?: string; slug?: string }) => {
    onNavigate(view, params);
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-stone-950/45 backdrop-blur-xs animate-in fade-in"
      onClick={onClose}
      role="presentation"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label="منوی برنامه"
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-md mx-3 mb-3 max-h-[75vh] overflow-y-auto rounded-[1.65rem] border border-white/70 bg-white/85 backdrop-blur-2xl backdrop-saturate-150 shadow-[0_24px_60px_-16px_rgba(120,53,15,0.45),inset_0_1px_0_0_rgba(255,255,255,0.75)] animate-in slide-in-from-bottom-4"
        style={{ marginBottom: 'calc(0.75rem + env(safe-area-inset-bottom, 0px))' }}
      >
        {/* Header */}
        <div className="sticky top-0 z-10 flex items-center justify-between px-4 py-3 bg-white/70 backdrop-blur-xl border-b border-white/70 rounded-t-[1.65rem]">
          <h2 className="text-sm font-black text-amber-950">منوی مستر دستباف</h2>
          <button
            onClick={onClose}
            aria-label="بستن منو"
            className="w-9 h-9 flex items-center justify-center rounded-xl text-stone-500 hover:bg-white/80 hover:text-stone-900 transition"
          >
            <X className="w-[18px] h-[18px]" />
          </button>
        </div>

        <div className="p-3 space-y-5">
          {/* صفحات سایت (از پیشخوان وردپرس — با فیلتر سطح دسترسی سرور) */}
          {appPages.length > 0 && (
            <section aria-label="صفحات سایت">
              <p className="px-3 pb-1.5 text-[11px] font-bold text-stone-500">صفحات سایت</p>
              <div className="space-y-1">
                {appPages.map((page) => (
                  <button key={page.id} onClick={() => go('app_page', { pageId: page.id })} className={rowClass}>
                    <FileText className={rowIcon} />
                    <span className="text-xs font-semibold truncate">{page.title}</span>
                    {page.access === 'members' && (
                      <span className="ms-auto shrink-0 text-[10px] font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded-full ring-1 ring-emerald-700/10">
                        ویژه اعضا
                      </span>
                    )}
                  </button>
                ))}
              </div>
            </section>
          )}

          {/* راهنماها (سرمقاله‌های ثابت) */}
          <section aria-label="راهنما و درباره ما">
            <p className="px-3 pb-1.5 text-[11px] font-bold text-stone-500">راهنما و درباره ما</p>
            <div className="space-y-1">
              <button onClick={() => go('editorial', { slug: 'about-us' })} className={rowClass}>
                <Info className={rowIcon} />
                <span className="text-xs font-semibold">درباره ما</span>
              </button>
              <button onClick={() => go('editorial', { slug: 'guide-membership' })} className={rowClass}>
                <BookMarked className={rowIcon} />
                <span className="text-xs font-semibold">راهنمای عضویت و تمدید دستی</span>
              </button>
              <button onClick={() => go('editorial', { slug: 'faq' })} className={rowClass}>
                <HelpCircle className={rowIcon} />
                <span className="text-xs font-semibold">سوالات متداول</span>
              </button>
              <button onClick={() => go('membership')} className={rowClass}>
                <Crown className={rowIcon} />
                <span className="text-xs font-semibold">پلن‌های اشتراک</span>
              </button>
            </div>
          </section>

          {/* پنل مدیریت — فقط در حالت دمو (در حالت Real مدیریت از پیشخوان وردپرس است) */}
          {userRole === 'admin' && !isRealApiMode && (
            <section aria-label="مدیریت">
              <div className="space-y-1">
                <button
                  onClick={() => go('admin')}
                  className={`${rowClass} text-red-900 hover:bg-red-50/70`}
                >
                  <ShieldCheck className="w-[18px] h-[18px] text-red-700 shrink-0" />
                  <span className="text-xs font-bold">پنل مدیریت</span>
                </button>
              </div>
            </section>
          )}
        </div>
      </div>
    </div>
  );
};
