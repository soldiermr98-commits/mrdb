/**
 * @file components/common/BottomNav.tsx
 * @description Mobile floating glass dock — bottom navigation for Persian RTL PWA (v1.2.5)
 */

import React from 'react';
import { Home, BookOpen, Crown, User, Menu } from 'lucide-react';
import { UserRole, AppUiDockItem } from '../../types';

interface BottomNavProps {
  activeView: string;
  onNavigate: (view: string) => void;
  userRole?: UserRole;
  isLoggedIn: boolean;
  onOpenLogin: () => void;
  /** v1.2.6: باز کردن شیت شیشه‌ای منو (صفحات سایت + راهنماها) */
  onOpenMenuSheet: () => void;
  /**
   * v1.2.7 — «محتوای وب‌اپ»: برچسب/نمایانی آیتم‌های داک از پیشخوان وردپرس
   * (GET /app-ui → menu.dock). undefined = دمو/خطا → پیش‌فرض‌های کد.
   * دکمهٔ «منو» همیشه ثابت می‌ماند (دسترسی به صفحات سایت/راهنماها).
   */
  dockConfig?: AppUiDockItem[];
}

const DEFAULT_DOCK: AppUiDockItem[] = [
  { key: 'home', label: 'خانه', enabled: true },
  { key: 'catalogue', label: 'دوره‌ها', enabled: true },
  { key: 'membership', label: 'عضویت', enabled: true },
  { key: 'dashboard', label: 'داشبورد', enabled: true },
];

const DOCK_ICONS: Record<AppUiDockItem['key'], React.ComponentType<{ className?: string }>> = {
  home: Home,
  catalogue: BookOpen,
  membership: Crown,
  dashboard: User,
};

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
  dockConfig,
}) => {
  // v1.2.7: آیتم‌های فعال از پیکربندی (پیش‌فرض کد وقتی پیکربندی نیست) — فقط آیتم‌های
  // مجاز رندر می‌شوند؛ اگر کارفرما همه را خاموش کند، دکمهٔ «منو» حداقل باقی می‌ماند.
  const dock = (dockConfig && dockConfig.length > 0 ? dockConfig : DEFAULT_DOCK).filter(
    (item) => item.enabled
  );

  return (
    <nav
      aria-label="ناوبری پایین"
      className="md:hidden fixed left-3 right-3 z-40 bottom-dock"
    >
      <div className="flex items-center justify-around max-w-md mx-auto rounded-[1.65rem] border border-white/60 bg-white/55 backdrop-blur-2xl backdrop-saturate-150 px-2 py-1.5 shadow-[0_16px_40px_-12px_rgba(120,53,15,0.35),inset_0_1px_0_0_rgba(255,255,255,0.7)]">
        {dock.map((item) => {
          const Icon = DOCK_ICONS[item.key];
          if (!Icon) return null;
          const isDashboard = item.key === 'dashboard';
          const targetView = item.key;
          return (
            <React.Fragment key={item.key}>
              {/* دکمهٔ منو در همان موقعیت قبلی: بین «عضویت» و «داشبورد» (v1.2.6) */}
              {isDashboard && (
                <button
                  onClick={onOpenMenuSheet}
                  aria-label="باز کردن منو"
                  aria-haspopup="dialog"
                  className={itemClass(activeView === 'app_page')}
                >
                  <Menu className="w-5 h-5 stroke-2" />
                  <span className="text-[10px] mt-1">منو</span>
                </button>
              )}
              <button
                onClick={() => {
                  if (isDashboard && !isLoggedIn) {
                    onOpenLogin();
                    return;
                  }
                  onNavigate(targetView);
                }}
                aria-label={item.label}
                aria-current={activeView === targetView ? 'page' : undefined}
                className={itemClass(activeView === targetView)}
              >
                <Icon
                  className={`w-5 h-5 ${activeView === targetView ? 'stroke-[2.5]' : 'stroke-2'}`}
                />
                <span className="text-[10px] mt-1">{item.label}</span>
              </button>
            </React.Fragment>
          );
        })}
      </div>
    </nav>
  );
};
