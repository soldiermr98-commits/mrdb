/**
 * @file components/common/InstructorAvatar.tsx
 * @description آواتار مدرس — v1.5.7 (گزارش کارفرما «آیکن svg استاد باید قابل لود شدن باشد»)
 *
 * قبلاً در حالت Real هیچ عکسی از سرور نمی‌آمد و <img src=""> خالی، آیکن شکستهٔ
 * مرورگر را نشان می‌داد (اسکرین‌شات کارفرما در کارت دوره). اکنون:
 *  - اگر عکس مدرس از پیشخوان (محتوای وب‌اپ ← عکس مدرس) تنظیم شده باشد → همان لود می‌شود؛
 *  - در نبود عکس یا خطای لود → آواتار برند شده با حرف اول نام مدرس («خ» خاموشیان)
 *    جایگزین می‌شود — هیچ‌وقت آیکن شکسته دیده نمی‌شود.
 */

import React, { useEffect, useState } from 'react';

interface InstructorAvatarProps {
  src?: string;
  name?: string;
  /** کلاس ابعاد/گردی — مثال: 'w-6 h-6 rounded-full' یا 'w-12 h-12 rounded-2xl' */
  className?: string;
}

export const InstructorAvatar: React.FC<InstructorAvatarProps> = ({
  src,
  name,
  className = 'w-6 h-6 rounded-full',
}) => {
  const [failed, setFailed] = useState(false);
  // تغییر src (مثلاً بعد از refetch با عکس تازه) گارد خطا را ریست می‌کند
  useEffect(() => setFailed(false), [src]);

  const initial = (name ?? '').trim().charAt(0) || 'ا';

  if (src && !failed) {
    return (
      <img
        src={src}
        alt={name ?? 'مدرس'}
        loading="lazy"
        onError={() => setFailed(true)}
        className={`${className} object-cover bg-amber-50`}
      />
    );
  }

  return (
    <div
      aria-label={name ?? 'مدرس'}
      role="img"
      className={`${className} shrink-0 flex items-center justify-center bg-gradient-to-br from-amber-100 to-amber-200 border border-amber-300/60 text-amber-900 font-bold select-none`}
    >
      <span className={/w-12|h-12|w-14|h-14/.test(className) ? 'text-lg' : 'text-[10px]'}>
        {initial}
      </span>
    </div>
  );
};
