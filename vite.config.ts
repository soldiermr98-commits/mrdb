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
  // ---------------------------------------------------------------------------
  if (command === 'build') {
    const env = loadEnv(mode, process.cwd(), '');
    const apiBase = (env.VITE_API_BASE_URL ?? '').trim();
    if (!apiBase || !/^https:\/\//i.test(apiBase)) {
      throw new Error(
        'بیلد پروداکشن بدون VITE_API_BASE_URL معتبر (https) مجاز نیست — برای بیلد دمو از dev استفاده کنید یا متغیر را در .env.production ست کنید.'
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
        includeAssets: ['favicon.ico', 'favicon-32.png', 'apple-touch-icon.png', 'logo-mrdastbaaf.png'],
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
              src: '/pwa-192x192.png',
              sizes: '192x192',
              type: 'image/png',
              purpose: 'any',
            },
            {
              src: '/pwa-512x512.png',
              sizes: '512x512',
              type: 'image/png',
              purpose: 'any',
            },
            {
              src: '/pwa-maskable-512x512.png',
              sizes: '512x512',
              type: 'image/png',
              purpose: 'maskable',
            },
          ],
        },
        workbox: {
          globPatterns: ['**/*.{js,css,html,ico,png,svg,woff,woff2}'],
          runtimeCaching: [
            {
              urlPattern: /^https:\/\/fonts\.googleapis\.com\/.*/i,
              handler: 'CacheFirst',
              options: {
                cacheName: 'google-fonts-cache',
                expiration: {
                  maxEntries: 10,
                  maxAgeSeconds: 60 * 60 * 24 * 365,
                },
                cacheableResponse: {
                  statuses: [0, 200],
                },
              },
            },
            {
              urlPattern: /^https:\/\/fonts\.gstatic\.com\/.*/i,
              handler: 'CacheFirst',
              options: {
                cacheName: 'gstatic-fonts-cache',
                expiration: {
                  maxEntries: 10,
                  maxAgeSeconds: 60 * 60 * 24 * 365,
                },
                cacheableResponse: {
                  statuses: [0, 200],
                },
              },
            },
          ],
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
