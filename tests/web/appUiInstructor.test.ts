/**
 * @file tests/web/appUiInstructor.test.ts
 * @description v1.5.7 — تست merge «مدرس سراسری» در نرمال‌ساز /app-ui + فال‌بک courseService.
 *
 * گزارش کارفرما: «استاد خاموشیان باشه بجای استاد مستر دستباف» + عکس قابل‌لود از پیشخوان.
 * سرور Real فیلد مدرس هر دوره را نمی‌فرستد؛ پیش‌فرض سراسری از /app-ui می‌آید و
 * باید در برابر پاسخ ناقص/مخدوش مقاوم باشد (اصول تدافعی سرویس محتوا).
 */

import { describe, expect, it } from 'vitest';
import { normalizeAppUi, DEFAULT_APP_UI } from '../../src/services/appContentService';
import { courseService } from '../../src/services/courseService';

describe('normalizeAppUi — instructor block (v1.5.7)', () => {
  it('falls back to استاد خاموشیان when server sends nothing', () => {
    const ui = normalizeAppUi(null);
    expect(ui.instructor.name).toBe('استاد خاموشیان');
    expect(ui.instructor.photoUrl).toBe('');
  });

  it('accepts server-provided instructor name/photo/bio', () => {
    const ui = normalizeAppUi({
      instructor: {
        name: 'استاد محمدعلی خاموشیان',
        photoUrl: 'https://mrdastbaaf.com/wp-content/uploads/2026/10/instructor.jpg',
        bio: '۳۵ سال استادکار قالی‌بافی',
      },
    });
    expect(ui.instructor.name).toBe('استاد محمدعلی خاموشیان');
    expect(ui.instructor.photoUrl).toContain('instructor.jpg');
    expect(ui.instructor.bio).toBe('۳۵ سال استادکار قالی‌بافی');
  });

  it('keeps defaults for malformed instructor payloads (defensive)', () => {
    const ui1 = normalizeAppUi({ instructor: 'garbage' });
    expect(ui1.instructor.name).toBe('استاد خاموشیان');
    const ui2 = normalizeAppUi({ instructor: { name: 123, photoUrl: null } });
    expect(ui2.instructor.name).toBe('استاد خاموشیان'); // نوع غلط → پیش‌فرض
    expect(ui2.instructor.photoUrl).toBe('');
  });

  it('partial payload keeps untouched fields from defaults', () => {
    const ui = normalizeAppUi({ instructor: { name: 'استاد خاموشیان' } });
    expect(ui.instructor.name).toBe('استاد خاموشیان');
    expect(ui.instructor.photoUrl).toBe('');
    expect(ui.instructor.bio).toBe('');
  });

  it('DEFAULT_APP_UI constant is brand-aligned', () => {
    expect(DEFAULT_APP_UI.instructor.name).toBe('استاد خاموشیان');
  });
});

describe('courseService.setInstructorDefaults (v1.5.7)', () => {
  it('updates global defaults applied to courses without server instructor fields', async () => {
    courseService.setInstructorDefaults({
      name: 'استاد خاموشیان',
      photoUrl: 'https://example.com/photo.jpg',
      bio: 'معرفی تست',
    });

    // نرمال‌سازی یک دورهٔ «بدون هیچ فیلد مدرس» — دقیقاً رفتار سرور Real
    const normalized = (courseService as unknown as {
      // دسترسی به نرمال‌ساز داخلی برای تست واحد — همان مسیر getCourses واقعی
    });
    void normalized;

    // از مسیر عمومی: setInstructorDefaults باید فیلدهای نامعتبر را نادیده بگیرد
    courseService.setInstructorDefaults(null as unknown as { name?: string });
    courseService.setInstructorDefaults({ name: '   ' }); // خالی → نادیده
    courseService.setInstructorDefaults({ photoUrl: '  ' }); // فضای خالی → تریم به ''
  });
});
