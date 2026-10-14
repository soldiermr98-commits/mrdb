/**
 * @file components/common/Header.tsx
 * @description Mobile-first Persian RTL Header with demo role-switcher and auth status
 */

import React from 'react';
import { User, ShieldCheck, LogIn, Sparkles, BookOpen } from 'lucide-react';
import { UserProfile } from '../../types';
import { isRealApiMode } from '../../services/apiClient';
import { PWAInstallButton } from './PWAInstallButton';

interface HeaderProps {
  currentUser: UserProfile | null;
  onOpenLogin: () => void;
  onSwitchRole: (role: 'student' | 'admin') => void;
  onNavigate: (view: string) => void;
  activeView: string;
}

export const Header: React.FC<HeaderProps> = ({
  currentUser,
  onOpenLogin,
  onSwitchRole,
  onNavigate,
  activeView,
}) => {
  return (
    <header className="sticky top-0 z-40 bg-[#fdfbf7]/95 backdrop-blur-md border-b border-amber-900/10 shadow-xs">
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
            className="w-10 h-10 rounded-full shadow-sm group-hover:scale-105 transition transform"
          />
          <div>
            <div className="flex items-center gap-1.5">
              <span className="font-black text-lg text-amber-950 tracking-tight">مستر دستباف</span>
              <span className="hidden sm:inline-block text-[11px] font-medium text-amber-800 bg-amber-100/70 px-2 py-0.5 rounded-full">
                آموزش آنلاین
              </span>
            </div>
            <p className="text-[11px] text-stone-500 font-normal">هنر کهن، آموزش نوین</p>
          </div>
        </button>

        {/* Desktop Navigation Links */}
        <nav className="hidden md:flex items-center gap-5 text-sm font-medium text-stone-700">
          <button
            onClick={() => onNavigate('home')}
            className={`transition hover:text-amber-800 ${
              activeView === 'home' ? 'text-amber-800 font-bold border-b-2 border-amber-800 pb-0.5' : ''
            }`}
          >
            صفحه اصلی
          </button>
          <button
            onClick={() => onNavigate('catalogue')}
            className={`transition hover:text-amber-800 ${
              activeView === 'catalogue' ? 'text-amber-800 font-bold border-b-2 border-amber-800 pb-0.5' : ''
            }`}
          >
            دوره‌ها
          </button>
          <button
            onClick={() => onNavigate('membership')}
            className={`transition hover:text-amber-800 ${
              activeView === 'membership' ? 'text-amber-800 font-bold border-b-2 border-amber-800 pb-0.5' : ''
            }`}
          >
            پلن‌های اشتراک
          </button>
          {currentUser && (
            <button
              onClick={() => onNavigate('dashboard')}
              className={`transition hover:text-amber-800 ${
                activeView === 'dashboard' ? 'text-amber-800 font-bold border-b-2 border-amber-800 pb-0.5' : ''
              }`}
            >
              داشبورد من
            </button>
          )}
          {/* v1.1.0: demo-only — in Real-API mode management happens in the
              WordPress dashboard, so the in-app admin tab is hidden entirely. */}
          {currentUser?.role === 'admin' && !isRealApiMode && (
            <button
              onClick={() => onNavigate('admin')}
              className={`flex items-center gap-1 text-red-800 font-bold ${
                activeView === 'admin' ? 'border-b-2 border-red-800 pb-0.5' : ''
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
          <div className="hidden lg:flex items-center gap-1 bg-stone-100 p-1 rounded-xl border border-stone-200 text-xs">
            <span className="text-[10px] text-stone-500 px-1 font-mono">نقش تستی:</span>
            <button
              onClick={() => onSwitchRole('student')}
              className={`px-2 py-1 rounded-lg transition ${
                currentUser?.role === 'student'
                  ? 'bg-amber-700 text-white font-semibold shadow-xs'
                  : 'text-stone-600 hover:text-stone-900'
              }`}
            >
              هنرجو
            </button>
            <button
              onClick={() => onSwitchRole('admin')}
              className={`px-2 py-1 rounded-lg transition ${
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
              className="flex items-center gap-2 bg-stone-100 hover:bg-stone-200/80 text-stone-800 px-3 py-1.5 rounded-xl text-xs font-medium transition border border-stone-200"
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
              className="flex items-center gap-1.5 bg-amber-800 hover:bg-amber-900 text-white px-3.5 py-1.5 rounded-xl text-xs font-semibold transition shadow-xs"
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
