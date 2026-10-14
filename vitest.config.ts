import { defineConfig } from 'vitest/config';

/**
 * Hardening (prelive 18-b): پیکربندی مستقل تست‌های فرانت (tests/web).
 * عمداً جدا از vite.config.ts است تا افزونه‌های Tailwind/PWA در تست لود نشوند
 * و گارد بیلد پروداکشن (که فقط command==='build' را می‌گیرد) مسیر تست را نگیرد.
 */
export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/web/**/*.test.ts'],
  },
});
