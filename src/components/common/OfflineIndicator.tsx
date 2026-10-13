/**
 * @file components/common/OfflineIndicator.tsx
 * @description Non-intrusive offline state banner
 */

import React from 'react';
import { WifiOff } from 'lucide-react';
import { useOnlineStatus } from '../../hooks/useOnlineStatus';

export const OfflineIndicator: React.FC = () => {
  const isOnline = useOnlineStatus();

  if (isOnline) return null;

  return (
    <div className="fixed bottom-20 left-4 right-4 md:left-auto md:right-4 z-50 flex items-center gap-2 rounded-xl bg-amber-700/95 backdrop-blur-sm px-4 py-2 text-xs font-medium text-white shadow-xl border border-amber-500/30 animate-in fade-in slide-in-from-bottom-2">
      <WifiOff className="w-4 h-4 text-amber-200 shrink-0" />
      <span>حالت آفلاین — داده‌های ذخیره شده در حافظه محلی نمایش داده می‌شوند.</span>
    </div>
  );
};
