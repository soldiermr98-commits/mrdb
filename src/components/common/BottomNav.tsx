/**
 * @file components/common/BottomNav.tsx
 * @description Mobile-first bottom navigation bar for Persian RTL PWA
 */

import React from 'react';
import { Home, BookOpen, Crown, User, ShieldCheck } from 'lucide-react';
import { UserRole } from '../../types';

interface BottomNavProps {
  activeView: string;
  onNavigate: (view: string) => void;
  userRole?: UserRole;
  isLoggedIn: boolean;
  onOpenLogin: () => void;
}

export const BottomNav: React.FC<BottomNavProps> = ({
  activeView,
  onNavigate,
  userRole,
  isLoggedIn,
  onOpenLogin,
}) => {
  return (
    <nav className="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-[#fdfbf7]/95 backdrop-blur-md border-t border-stone-200/80 px-2 py-1 shadow-lg pb-safe">
      <div className="flex items-center justify-around max-w-md mx-auto">
        {/* 1. Home */}
        <button
          onClick={() => onNavigate('home')}
          className={`flex flex-col items-center justify-center py-1.5 px-3 rounded-xl transition min-w-[60px] min-h-[48px] ${
            activeView === 'home'
              ? 'text-amber-800 font-bold'
              : 'text-stone-500 hover:text-stone-800'
          }`}
        >
          <Home className={`w-5 h-5 ${activeView === 'home' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">خانه</span>
        </button>

        {/* 2. Courses Catalogue */}
        <button
          onClick={() => onNavigate('catalogue')}
          className={`flex flex-col items-center justify-center py-1.5 px-3 rounded-xl transition min-w-[60px] min-h-[48px] ${
            activeView === 'catalogue'
              ? 'text-amber-800 font-bold'
              : 'text-stone-500 hover:text-stone-800'
          }`}
        >
          <BookOpen className={`w-5 h-5 ${activeView === 'catalogue' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">دوره‌ها</span>
        </button>

        {/* 3. Membership */}
        <button
          onClick={() => onNavigate('membership')}
          className={`flex flex-col items-center justify-center py-1.5 px-3 rounded-xl transition min-w-[60px] min-h-[48px] ${
            activeView === 'membership'
              ? 'text-amber-800 font-bold'
              : 'text-stone-500 hover:text-stone-800'
          }`}
        >
          <Crown className={`w-5 h-5 ${activeView === 'membership' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">عضویت</span>
        </button>

        {/* 4. User Dashboard */}
        <button
          onClick={() => {
            if (isLoggedIn) {
              onNavigate('dashboard');
            } else {
              onOpenLogin();
            }
          }}
          className={`flex flex-col items-center justify-center py-1.5 px-3 rounded-xl transition min-w-[60px] min-h-[48px] ${
            activeView === 'dashboard'
              ? 'text-amber-800 font-bold'
              : 'text-stone-500 hover:text-stone-800'
          }`}
        >
          <User className={`w-5 h-5 ${activeView === 'dashboard' ? 'stroke-[2.5]' : 'stroke-2'}`} />
          <span className="text-[10px] mt-1">داشبورد</span>
        </button>

        {/* 5. Admin Panel (if admin) */}
        {userRole === 'admin' && (
          <button
            onClick={() => onNavigate('admin')}
            className={`flex flex-col items-center justify-center py-1.5 px-3 rounded-xl transition min-w-[60px] min-h-[48px] ${
              activeView === 'admin'
                ? 'text-red-700 font-bold'
                : 'text-stone-500 hover:text-stone-800'
            }`}
          >
            <ShieldCheck className={`w-5 h-5 ${activeView === 'admin' ? 'stroke-[2.5]' : 'stroke-2'}`} />
            <span className="text-[10px] mt-1">مدیریت</span>
          </button>
        )}
      </div>
    </nav>
  );
};
