# پیش‌سخن

> 📌 **سند تاریخی (نسخهٔ 1.2.0):** این راستی‌آزمایی اجرا و در افزونهٔ 1.2.0 منتشر شد؛ سایت هم اکنون زنده است. برای وضعیت فعلی، [CHANGELOG.md](../CHANGELOG.md) و بخش ۱۴ [FullDoc.MD](../FullDoc.MD) را ببینید.


این سند، نتیجهٔ «فاز ۰ — خواندن و تایید» بریف hardening پیش‌لایو است: هر یافته با خواندن کد واقعی بررسی شده و حکم آن (تایید / تایید-جزئی / رد) در جدول زیر آمده است. برنچ کار: `fix/prelive-hardening` — هیچ کامیت مستقیمی روی main انجام نمی‌شود.

**محدودیت محیط توسعه:** PHP CLI و MySQL در این محیط در دسترس نیست؛ `php -l` و PHPUnit قابل اجرا نیستند. جایگزین‌ها: بازبینی استاتیک + چکر توکن‌ساز پایتون برای PHP، و تست‌های PHP به‌صورت هارنس قابل‌اجرای روی سرور (`tests/php/`) تحویل می‌شود. Vitest و tsc و vite build روی فرانت اجرا می‌شوند.

## جدول تایید یافته‌ها (فاز ۰)

| # | یافته | حکم | شواهد کد |
|---|---|---|---|
| 0-2 | هوک determine_current_user ممکن است دیر/هرگز فایر نشود | **تایید-جزئی** | فیلتر در پلاگین ثبت شده و در چرخهٔ عادی REST کار می‌کند (permission_callback → is_user_logged_in → wp_get_current_user → فیلتر فایر می‌شود)، اما اگر هر افزونهٔ دیگری پیش از serve_request و تعریف REST_REQUEST، `wp_get_current_user()` را فراخوانی کند، نتیجه منجمد و Bearer نادیده گرفته می‌شود. راه‌حل: permission_callback مشترک که اول `maybe_authenticate_bearer()` را صریح صدا می‌زند + محدودسازی Bearer به namespace افزونه |
| 1-1 | پیش‌فرض 'zibal' (سندباکس) در initiate/verify | **تایید** | class-payment-controller.php:155 و :247 |
| 1-2 | وضعیت سفارش/ریکنسیلیشن | **تایید** | success کلاینت در verify_zibal_order تصمیم‌ساز است (خط 293)؛ ۲۰۲ فوراً failed دائمی؛ کرون روزانه با آستانهٔ ۲۴ ساعت و سقف ۵۰؛ سفارش verifying گیرکرده بازیابی نمی‌شود؛ شکست initiate سفارش pending رها می‌کند (خط 181-188)؛ amount اختیاری است (خط 357)؛ تطبیق orderId با order_number وجود ندارد؛ ستون updated_at در جدول سفارش نیست |
| 1-3 | ledger اشتراک با UNIQUE(order_id) | **تایید** | grant_or_extend هیچ ایدمپوتنسی بر اساس order_id ندارد. زنجیرهٔ واقعی: verifying>۱۰دقیقه→pending→ادعای مجدد→grant دوباره = تمدید دوبرابر |
| 1-4 | منطق تمدید/ارتقا tier-aware | **تایید** | grant_or_extend در هر حالتی فقط از انقضای فعلی تمدید می‌کند؛ خرید سطح پایین‌تر توسط کاربر طلایی، tier رکورد فعال را بی‌صدا به bronze بازنویسی می‌کند |
| 1-5 | کوکی/نقش‌ها در verify-otp | **تایید** | wp_set_current_user + wp_set_auth_cookie (خط 168-169)؛ نقش کاربر جدید از تنظیمات پیش‌فرض سایت می‌آید نه مقدار صریح؛ هیچ گاردی برای نقش‌های مدیریتی روی OTP نیست |
| 1-6 | invalid_otp با 401 → پیام «جلسه منقضی» | **تایید** | سرور: خط 140 (401)؛ فرنت: apiClient هر 401 را session-expired می‌داند و پیام سرور (تلاش باقی‌مانده) را می‌بلعد |
| 1-7 | بازنویسی پیشرفت با [] | **تایید** | authService normalizeApiUser:225 همیشه `completedLessonIds: []` و بلافاصله upsertRegistryUser |
| 1-8 | MP4 دائمی + UI جعلی | **تایید** | video_url متن ساده؛ UI: سوییچ کیفیت جعلی و متن «HLS» در LessonPlayerModal (خط 840/1062)؛ allowlist هاست ویدیو وجود ندارد؛ لایهٔ provider/امضا نیست |
| 1-9 | تاریخچه سفارش دمو-محور | **تایید** | اندپوینت /me/orders وجود ندارد؛ داشبورد از localStorage می‌خواند |
| 1-10 | بررسی فیش | **تایید-جزئی** | تایید اتمیک است (pending→completed→grant→rollback) اما وضعیت میانی به‌جای verifying، completed است؛ reject بدون UPDATE مشروط و بدون بررسی نتیجه (خط 167)؛ audit log وجود ندارد؛ صفحهٔ پیشخوان برای بررسی فیش‌ها **وجود ندارد** (گزارش می‌شود، ساخت صفحه خارج از اسکوپ این برنچ) |
| 2-11 | OTP | **تایید** | کد ۵ رقمی (خط 75)؛ فقط قفل ۲دقیقه‌ای per-phone؛ IP فقط REMOTE_ADDR (پشت پروکسی همه یک IP)؛ سقف سراسری 200/s via update_option بدون اتمی (check-then-write) |
| 2-12 | توکن | **تایید** | توکن ۶۴کاراکتری plaintext در usermeta + lookup با get_users(meta_value)؛ logout/revoke وجود ندارد |
| 2-13 | apiClient | **تایید** | JSON نامعتبر روی response.ok → null برگردانده می‌شود؛ timeout با AbortController وجود ندارد؛ گارد بیلد پروداکشن در vite.config نیست |
| 2-14 | کد دمو در بیلد | **تایید-جزئی** | فعال‌سازی دمو در Real از قبل گارد شده (سوییچر/بنر/12345/switchRole-throw در این برنچ انجام می‌شود) اما INITIAL_USERS/INITIAL_ORDERS به‌دلیل import استاتیک در باندل می‌مانند. حذف کامل از باندل نیازمند code-splitting سرویس‌های دمو است — **انجام نشد** (ریسک رگرسیون بالا در آستانهٔ لایو؛ به‌عنوان کار آینده ثبت شد) |
| 2-15 | یافتن کاربر با billing_phone | **تایید** | فقط get_user_by('login') (خط 149) |
| 2-16 | کال‌بک زیبال با fragment | **تایید-جزئی** | پارس فرانت هر دو شکل را می‌فهمد (zibalCallback.ts)؛ cb_token به order مقید است ولی TTL ندارد و تو تست sandbox واقعی هم قابل اجرا نیست (merchant لازم است — در چک‌لیست staging آمده) |
| 2-17 | priceToman int | **تایید-جزئی** | سرور از قبل `(int)` cast می‌کند؛ سمت کلاینت پذیرش رشتهٔ عددی در normalizer اضافه می‌شود |
| 2-18 | configData | **تایید-جزئی** | sanitize اسکالر هست؛ allowlist scheme برای مقادیر URL-مانند نیست؛ رندر فرانت React JSX است (خطر innerHTML ندارد — تأیید می‌شود در فاز پیاده‌سازی) |
| 2-19 | CORS | **تایید** | remove_filter سراسری روی کل REST؛ Allow-Credentials فعال؛ Max-Age ندارد؛ مستندات .htaccess نیست |
| 2-20 | بلیط مهمان | **تایید** | playback-ticket هیچ rate-limit ندارد |
| 3 | جزئی | **تایید** | planName='اشتراک '+tier انگلیسی؛ rating/students ساختگی در پاسخ دوره‌ها؛ /courses/{slug} به دستهٔ دوره‌ها محدود نیست |

## قراردادهای قفل‌شده (بدون تغییر نام فیلدهای موجود)

- نسخهٔ افزونه: **1.2.0** — نسخهٔ اسکیما: **1.3.0** (جدول‌های NEW: `mrdastbaaf_membership_grants` با UNIQUE(order_id)، `mrdastbaaf_audit_log`؛ ستون NEW: `orders.updated_at`).
- اندپوینت‌های NEW: `POST /auth/logout`، `GET/PUT /me/progress`، `GET /me/orders`، `GET /cron/reconcile?token=` (کرون سرور واقعی).
- توکن Bearer: فرمت `{userId}.{secret}` — سرور فقط hash('sha256', secret) را نگه می‌دارد؛ شکل پاسخ verify-otp تغییر نمی‌کند.
- OTP: ۶ رقمی + قفل ساعتی/روزانه per-phone + IP واقعی از هدر پروکسی قابل‌تنظیم (`mrdastbaaf_trusted_proxy_header`).
- زیبال: بدون پیش‌فرض سندباکس؛ ثابت `MRDASTBAAF_ALLOW_SANDBOX` برای تست اختیاری؛ verify زیبال مرجع یکتا؛ TTL توکن کال‌بک ۴۸ ساعت.
- تصمیم ارتقا (برای PR): هم‌سطح=تمدید از انقضا؛ سطح بالاتر=اشتراک جدید از «الان» با تبدیل متناسب ارزش روزهای باقی‌مانده به روزِ پلن جدید (بر اساس قیمت/روز هر پلن)؛ سطح پایین‌تر=رد با پیام 409.
- سقف سراسری پیامک: log-only (خاموش‌نشدن سرویس در حملهٔ توزیع‌شده)؛ محدودیت‌های per-phone و per-IP blocking می‌مانند.

## تست Bearer (اسکریپت staging)

```bash
# ۱) دریافت توکن با OTP (شماره تستی staging)
curl -s -X POST https://mrdastbaaf.com/wp-json/mrdastbaaf/v1/auth/request-otp \
  -H 'Content-Type: application/json' -d '{"phone":"09XXXXXXXXX"}'
curl -s -X POST https://mrdastbaaf.com/wp-json/mrdastbaaf/v1/auth/verify-otp \
  -H 'Content-Type: application/json' -d '{"phone":"09XXXXXXXXX","otp_code":"XXXXXX"}'
TOKEN=...   # از پاسخ

# ۲) Bearer روی /auth/me — باید 200 با پروفایل بدهد؛ بدون توکن باید 401 بدهد
curl -s https://mrdastbaaf.com/wp-json/mrdastbaaf/v1/auth/me -H "Authorization: Bearer $TOKEN"
curl -s -o /dev/null -w '%{http_code}\n' https://mrdastbaaf.com/wp-json/mrdastbaaf/v1/auth/me   # انتظار: 401

# ۳) initiate با Bearer — بدون توکن 401، با توکن 200 (یا 503 «درگاه پیکربندی نشده» پیش از پیکربندی مرچنت)
curl -s -X POST https://mrdastbaaf.com/wp-json/mrdastbaaf/v1/payment/zibal/initiate \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"plan_id":"plan-bronze-1m"}'

# ۴) submit فیش با Bearer
curl -s -X POST https://mrdastbaaf.com/wp-json/mrdastbaaf/v1/payment/manual-transfer/submit \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"plan_id":"plan-bronze-1m","tracking_code":"TEST12345","card_last_four":"1234","deposit_date":"1404/07/22"}'
```

اگر مورد ۲/۳ بدون توکن 200 داد یا با توکن 401: محدودسازی namespace/permission مشترک درست اعمال نشده — به کامیت‌های این برنچ مراجعه شود.
