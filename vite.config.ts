import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { defineConfig, loadEnv } from 'vite';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig(({ command, mode }) => {
  // ---------------------------------------------------------------------------
  // Hardening (prelive 18-b): گارد بیلد پروداکشن — بدون VITE_API_BASE_URL معتبر (https)
  // بیلد build مجاز نیست؛ در غیر این صورت به‌سادگی یک باندل «دمو» روی پروداکشن
  // دیپلوی می‌شود (ریشهٔ دیباگ «چرا سایت من دمو است؟» — Task 17).
  // Dev (command === 'serve') و vitest از این گارد عبور می‌کنند و دمو همچنان کار می‌کند.
  //
  // v1.2.4: گارد دوم — مقدار باید «آدرس کامل پایهٔ پل» با مسیر /wp-json/... باشد.
  // ریشهٔ قطع‌شدن ارتباط واقعی استقرار سوم (درس/پلن خالی + خطای OTP «ارتباط با
  // سرور برقرار نشد») این بود که باندل با مقدار بدون مسیر (https://mrdastbaaf.com)
  // ساخته شده بود → همهٔ درخواست‌ها به صفحهٔ عادی سایت می‌رفتند (بدون هدر CORS).
  // ---------------------------------------------------------------------------
  if (command === 'build') {
    const env = loadEnv(mode, process.cwd(), '');
    const apiBase = (env.VITE_API_BASE_URL ?? '').trim();
    if (!apiBase || !/^https:\/\//i.test(apiBase)) {
      throw new Error(
        'بیلد پروداکشن بدون VITE_API_BASE_URL معتبر (https) مجاز نیست — برای بیلد دمو از dev استفاده کنید یا متغیر را در .env.production ست کنید.'
      );
    }
    if (!/\/wp-json\/[A-Za-z0-9_-]+\/v\d+/i.test(apiBase.replace(/\/+$/, ''))) {
      throw new Error(
        'VITE_API_BASE_URL باید آدرس کامل اندپوینت پل باشد؛ مثال صحیح: https://mrdastbaaf.com/wp-json/mrdastbaaf/v1 — مقدار بدون مسیر /wp-json/... باعث قطع ارتباط اپ با سرور می‌شود.'
      );
    }
  }

  return {
    plugins: [
      react(),
      tailwindcss(),
      VitePWA({
        // DEV-7: prompt mode — a new waiting service worker no longer activates
        // silently; PWAUpdatePrompt (virtual:pwa-register/react) surfaces the
        // update and only reloads when the user confirms.
        registerType: 'prompt',
        // v1.2.7: نام فایل‌های آیکون دوباره عوض شد (mrd-appicon-*) — آیکون لانچر اندروید
        // (WebAPK) با آپدیت PWA تازه نمی‌شود؛ تغییر src در مانیفست، کروم را به re-mint
        // ترغیب می‌کند. آیکن maskable هم با safe-zone استاندارد (لوگو ۷۵٪ روی بک‌گراند توپر) بازسازی شد.
        includeAssets: ['favicon.ico', 'mrd-favicon-32.png', 'mrd-apple-touch-180.png', 'logo-mrdastbaaf.png'],
        manifest: {
          id: '/',
          name: 'مستر دستباف | آموزش صنایع دستی',
          short_name: 'مستر دستباف',
          description: 'پلتفرم جامع آموزش آنلاین بافندگی، قالی‌بافی، گلیم‌بافی و صنایع دستی',
          theme_color: '#9a3412',
          background_color: '#fdfbf7',
          display: 'standalone',
          dir: 'rtl',
          lang: 'fa',
          start_url: '/',
          scope: '/',
          icons: [
            {
              src: '/mrd-appicon-192.png',
              sizes: '192x192',
              type: 'image/png',
              purpose: 'any',
            },
            {
              src: '/mrd-appicon-512.png',
              sizes: '512x512',
              type: 'image/png',
              purpose: 'any',
            },
            {
              src: '/mrd-appicon-maskable-512.png',
              sizes: '512x512',
              type: 'image/png',
              purpose: 'maskable',
            },
          ],
        },
        workbox: {
          globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2}'],
          // v1.2.6: قانون کش فونت‌های گوگل حذف شد — وزیرمتن حالا لوکال باندل می‌شود
          // (فونت در precache است؛ هیچ درخواستی به fonts.googleapis/gstatic نمی‌رود)
        },
        devOptions: {
          enabled: true,
          type: 'module',
        },
      }),
    ],
    resolve: {
      alias: {
        '@': path.resolve(__dirname, '.'),
      },
    },
    server: {
      hmr: process.env.DISABLE_HMR !== 'true',
      watch: process.env.DISABLE_HMR === 'true' ? null : {},
    },
  };
});
