/**
 * @file components/common/Header.tsx
 * @description Mobile-first Persian RTL Header — glassmorphism (v1.2.5) + demo role-switcher + auth status
 */

import React from 'react';
import { User, ShieldCheck, LogIn, Sparkles, BookOpen, Menu } from 'lucide-react';
import { UserProfile, AppPage } from '../../types';
import { isRealApiMode } from '../../services/apiClient';
import { PWAInstallButton } from './PWAInstallButton';

interface HeaderProps {
  currentUser: UserProfile | null;
  onOpenLogin: () => void;
  onSwitchRole: (role: 'student' | 'admin') => void;
  onNavigate: (view: string, params?: { pageId?: string }) => void;
  activeView: string;
  /** v1.2.6: صفحات سایت از پیشخوان (سطح دسترسی سمت سرور فیلتر شده) */
  appPages?: AppPage[];
  /** v1.2.6: باز کردن شیت منو */
  onOpenMenuSheet?: () => void;
}

/**
 * v1.2.5 — Glassmorphism: نوار نیمه‌شفاف با بلور قوی، هایلایت شیشه‌ای لبهٔ بالا و
 * سایهٔ نرم گرم. لینک فعال به‌جای زیرخط، یک «قرص شیشه‌ای» دارد.
 */
const NAV_BASE = 'transition rounded-full px-3 py-1.5 focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:outline-none';
const navLinkClass = (active: boolean) =>
  active
    ? `${NAV_BASE} text-amber-900 font-bold bg-white/65 ring-1 ring-amber-900/10 shadow-sm`
    : `${NAV_BASE} text-stone-700 hover:text-amber-800 hover:bg-white/50`;

export const Header: React.FC<HeaderProps> = ({
  currentUser,
  onOpenLogin,
  onSwitchRole,
  onNavigate,
  activeView,
  appPages = [],
  onOpenMenuSheet,
}) => {
  return (
    <header
      className="sticky top-0 z-40 border-b border-white/60 bg-gradient-to-b from-[#fdfbf7]/80 via-[#fdfbf7]/60 to-[#fdfbf7]/50 backdrop-blur-2xl backdrop-saturate-150 shadow-[0_8px_32px_-16px_rgba(120,53,15,0.35),inset_0_1px_0_0_rgba(255,255,255,0.65)]"
    >
      <div className="max-w-6xl mx-auto px-4 py-2.5 flex items-center justify-between gap-3">
        {/* Brand identity */}
        <button
          onClick={() => onNavigate('home')}
          className="flex items-center gap-2.5 text-right group focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:outline-none rounded-xl"
        >
          {/* BRANDING v1.2.1: لوگوی رسمی مستردستباف جایگزین آیکون موقت دارکوب */}
          <img
            src="/logo-mrdastbaaf.png"
            alt="لوگوی مستر دستباف"
            width={40}
            height={40}
            decoding="async"
            className="w-10 h-10 rounded-full shadow-sm ring-1 ring-white/70 group-hover:scale-105 transition transform"
          />
          <div>
            <div className="flex items-center gap-1.5">
              <span className="font-black text-lg text-amber-950 tracking-tight">مستر دستباف</span>
              <span className="hidden sm:inline-block text-[11px] font-medium text-amber-800 bg-white/55 ring-1 ring-amber-900/10 px-2 py-0.5 rounded-full">
                آموزش آنلاین
              </span>
            </div>
            <p className="text-[11px] text-stone-500 font-normal">هنر کهن، آموزش نوین</p>
          </div>
        </button>

        {/* Desktop Navigation Links */}
        <nav className="hidden md:flex items-center gap-1.5 text-sm font-medium" aria-label="ناوبری اصلی">
          <button onClick={() => onNavigate('home')} className={navLinkClass(activeView === 'home')}>
            صفحه اصلی
          </button>
          <button onClick={() => onNavigate('catalogue')} className={navLinkClass(activeView === 'catalogue')}>
            دوره‌ها
          </button>
          <button onClick={() => onNavigate('membership')} className={navLinkClass(activeView === 'membership')}>
            پلن‌های اشتراک
          </button>
          {currentUser && (
            <button onClick={() => onNavigate('dashboard')} className={navLinkClass(activeView === 'dashboard')}>
              داشبورد من
            </button>
          )}
          {/* v1.2.6: دو صفحهٔ اول سایت مستقیم در ناوبری دسکتاپ + دکمهٔ منو برای همهٔ صفحات */}
          {appPages.slice(0, 2).map((page) => (
            <button
              key={page.id}
              onClick={() => onNavigate('app_page', { pageId: page.id })}
              className={navLinkClass(false)}
              title={page.title}
            >
              {page.title}
            </button>
          ))}
          {appPages.length > 0 && onOpenMenuSheet && (
            <button
              onClick={onOpenMenuSheet}
              className={`${NAV_BASE} text-stone-700 hover:text-amber-800 hover:bg-white/50 flex items-center gap-1`}
              aria-label="باز کردن منو"
            >
              <Menu className="w-4 h-4" />
              منو
            </button>
          )}
          {/* v1.1.0: demo-only — in Real-API mode management happens in the
              WordPress dashboard, so the in-app admin tab is hidden entirely. */}
          {currentUser?.role === 'admin' && !isRealApiMode && (
            <button
              onClick={() => onNavigate('admin')}
              className={`flex items-center gap-1 rounded-full px-3 py-1.5 transition ${
                activeView === 'admin'
                  ? 'text-red-900 font-bold bg-red-50/80 ring-1 ring-red-900/10 shadow-sm'
                  : 'text-red-800 hover:bg-red-50/60'
              }`}
            >
              <ShieldCheck className="w-4 h-4 text-red-700" />
              پنل مدیریت
            </button>
          )}
        </nav>

        {/* Actions & Role Switcher */}
        <div className="flex items-center gap-2">
          {/* Quick prototype switcher for easy evaluation (demo mode only) */}
          {!isRealApiMode && (
          <div className="hidden lg:flex items-center gap-1 bg-white/50 ring-1 ring-white/70 backdrop-blur-sm p-1 rounded-2xl text-xs shadow-sm">
            <span className="text-[10px] text-stone-500 px-1 font-mono">نقش تستی:</span>
            <button
              onClick={() => onSwitchRole('student')}
              className={`px-2 py-1 rounded-xl transition ${
                currentUser?.role === 'student'
                  ? 'bg-amber-700 text-white font-semibold shadow-xs'
                  : 'text-stone-600 hover:text-stone-900'
              }`}
            >
              هنرجو
            </button>
            <button
              onClick={() => onSwitchRole('admin')}
              className={`px-2 py-1 rounded-xl transition ${
                currentUser?.role === 'admin'
                  ? 'bg-red-800 text-white font-semibold shadow-xs'
                  : 'text-stone-600 hover:text-stone-900'
              }`}
            >
              مدیر ارشد
            </button>
          </div>
          )}

          <PWAInstallButton variant="header" />

          {/* User profile / Login */}
          {currentUser ? (
            <button
              onClick={() => onNavigate('dashboard')}
              className="flex items-center gap-2 bg-white/55 hover:bg-white/75 backdrop-blur-sm text-stone-800 px-3 py-1.5 rounded-xl text-xs font-medium transition ring-1 ring-white/70 shadow-sm"
              title="مشاهده داشبورد"
              // BUG-67: icon-only on mobile (avatar) — needs an accessible name.
              aria-label={`مشاهده داشبورد ${currentUser.fullName}`}
            >
              <div className="w-6 h-6 rounded-full bg-amber-800 text-white flex items-center justify-center text-[10px] font-bold">
                {currentUser.fullName.slice(0, 1)}
              </div>
              <span className="hidden sm:inline-block max-w-[100px] truncate">{currentUser.fullName}</span>
            </button>
          ) : (
            <button
              onClick={onOpenLogin}
              className="flex items-center gap-1.5 bg-amber-800/90 hover:bg-amber-900 text-white px-3.5 py-1.5 rounded-xl text-xs font-semibold transition shadow-sm ring-1 ring-white/25"
              aria-label="ورود با شماره موبایل"
            >
              <LogIn className="w-3.5 h-3.5" />
              <span>ورود با شماره موبایل</span>
            </button>
          )}
        </div>
      </div>
    </header>
  );
};
