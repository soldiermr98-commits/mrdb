/**
 * @file components/common/BottomNav.tsx
 * @description Mobile floating glass dock — bottom navigation for Persian RTL PWA (v1.2.5)
 */

import React from 'react';
import { Home, BookOpen, Crown, User, Menu } from 'lucide-react';
import { UserRole } from '../../types';

interface BottomNavProps {
  activeView: string;
  onNavigate: (view: string) => void;
  userRole?: UserRole;
  isLoggedIn: boolean;
  onOpenLogin: () => void;
  /** v1.2.6: باز کردن شیت شیشه‌ای منو (صفحات سایت + راهنماها) */
  onOpenMenuSheet: () => void;
}

/**
 * v1.2.5 — Glassmorphism dock شناور: نوار شیشه‌ای گرد با بلور قوی، هایلایت لبهٔ
 * بالا و سایهٔ نرم؛ آیتم فعال «قرص شیشه‌ای» کهربایی دارد. موقعیت عمودی با
 * bottom-dock بالای ناحیهٔ امن (safe-area) قرار می‌گیرد.
 */
const itemClass = (active: boolean, danger = false) =>
  `flex flex-col items-center justify-center py-1.5 px-3 rounded-2xl transition min-w-[60px] min-h-[48px] ${
    active
      ? danger
        ? 'text-red-800 font-bold bg-red-50/80 ring-1 ring-red-900/10'
        : 'text-amber-800 font-bold bg-amber-800/10 ring-1 ring-amber-900/15'
      : 'text-stone-500 hover:text-stone-800 hover:bg-white/50'
  }`;

export const BottomNav: React.FC<BottomNavProps> = ({
  activeView,
  onNavigate,
  isLoggedIn,
  onOpenLogin,
  onOpenMenuSheet,
}) => {
  return (
    <nav
      aria-label="ناوبری پایین"
      className="md:hidden fixed left-3 right-3 z-40 bottom-dock"
    >
      <div className="flex items-center justify-around max-w-md mx-auto rounded-[1.65rem] border border-white/60 bg-white/55 backdrop-blur-2xl backdrop-saturate-150 px-2 py-1.5 shadow-[0_16px_40px_-12px_rgba(120,53,15,0.35),inset_0_1px_0_0_rgba(255,255,255,0.7)]">
        {/* 1. Home */}
        <button
          onClick={() => onNavigate('home')}
          aria-label="خانه"
          aria-current={activeView === 'home' ? 'page' : undefined}
          className={itemClass(activeView === 'home')}
        >
          <Home className={`w-5 h-5 ${activeView === 'home' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">خانه</span>
        </button>

        {/* 2. Courses Catalogue */}
        <button
          onClick={() => onNavigate('catalogue')}
          aria-label="دوره‌ها"
          aria-current={activeView === 'catalogue' ? 'page' : undefined}
          className={itemClass(activeView === 'catalogue')}
        >
          <BookOpen className={`w-5 h-5 ${activeView === 'catalogue' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">دوره‌ها</span>
        </button>

        {/* 3. Membership */}
        <button
          onClick={() => onNavigate('membership')}
          aria-label="عضویت"
          aria-current={activeView === 'membership' ? 'page' : undefined}
          className={itemClass(activeView === 'membership')}
        >
          <Crown className={`w-5 h-5 ${activeView === 'membership' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">عضویت</span>
        </button>

        {/* 4. Menu Sheet — v1.2.6: صفحات سایت (سطح دسترسی از پیشخوان) + راهنماها */}
        <button
          onClick={onOpenMenuSheet}
          aria-label="باز کردن منو"
          aria-haspopup="dialog"
          className={itemClass(activeView === 'app_page')}
        >
          <Menu className="w-5 h-5 stroke-2" />
          <span className="text-[10px] mt-1">منو</span>
        </button>

        {/* 5. User Dashboard */}
        <button
          onClick={() => {
            if (isLoggedIn) {
              onNavigate('dashboard');
            } else {
              onOpenLogin();
            }
          }}
          aria-label={isLoggedIn ? 'داشبورد من' : 'ورود به حساب'}
          aria-current={activeView === 'dashboard' ? 'page' : undefined}
          className={itemClass(activeView === 'dashboard')}
        >
          <User className={`w-5 h-5 ${activeView === 'dashboard' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">داشبورد</span>
        </button>
      </div>
    </nav>
  );
};
