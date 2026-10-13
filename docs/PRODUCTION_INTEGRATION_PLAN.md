# سند معماری و نقشه راه یکپارچه‌سازی تجاری مستر دستباف (Production Integration Plan)
**مجموعه آموزشی مستر دستباف (mrdastbaaf.com)**
**وضعیت:** مستند معماری و تحلیل پیش از پیاده‌سازی (Ready for Technical Sign-Off)
**تاریخ:** اکتبر ۲۰۲۶

---

## ۱. تایید وضعیت فایل‌ها، وابستگی‌ها و بیلد واقعی (Codebase Verification)

### مشخصات فنی احراز شده:
- **فریم‌ورک و هسته:** React `^19.0.1` با TypeScript `^7.0.2` بر بستر Vite `^8.3.0`
- **موتور استایل‌دهی:** Tailwind CSS `@tailwindcss/vite` `^4.3.3` با فونت راست‌چین وزیرمتن
- **قابلیت PWA:** افزونه `vite-plugin-pwa` `^2.0.0` همراه با وب‌مانیفست، رجیستریشن سرویس‌ورکر و کش استاتیک Workbox
- **آیکون‌ها و موشن:** `lucide-react` `^0.546.0` و `motion` `^12.23.24`
- **اسکریپت‌های npm:**
  - `npm run dev`: اجرای محیط توسعه با Vite روی پورت ۳۰۰۰
  - `npm run lint`: `tsc --noEmit` جهت اعتبارسنجی ایستا و تایپ‌ها
  - `npm run build`: `vite build` کامپایل کامل فایل‌های استاتیک و PWA
  - `npm run preview`: پیش‌نمایش باندل نهایی

### خروجی واقعی تست بیلد (`npm run lint && npm run build`):
```text
> tsc --noEmit (Exit Code 0 - 0 errors)
> vite build
✓ 1688 modules transformed.
dist/registerSW.js                0.13 kB
dist/manifest.webmanifest         0.64 kB
dist/index.html                   2.15 kB │ gzip:   0.98 kB
dist/assets/index-BWYHyGF6.css   59.33 kB │ gzip:  10.07 kB
dist/assets/index-DykfZhXX.js   696.81 kB │ gzip: 184.04 kB
✓ built in 894ms
PWA v2.0.0: mode=generateSW, precache=17 entries (755.48 KiB)
Files generated: dist/sw.js, dist/workbox-afac4cd2.js
```

### تحلیل وضعیت نگهداری داده‌ها (State Persistence Analysis):
در پروتوتایپ فعلی، داده‌ها به شکل ترکیبی در **`localStorage` کلاینت با فال‌بک درون‌حافظه‌ای (In-Memory)** نگهداری می‌شوند:
1. `authService.ts`: ذخیره `mrdastbaaf_current_user` و `mrdastbaaf_auth_token` در `localStorage`. در صورت عدم وجود، به عنوان فال‌بک از `INITIAL_USERS[0]` استفاده می‌کند.
2. `courseService.ts`: ذخیره دوره‌ها در `mrdastbaaf_courses` و یادداشت‌های کاربر روی ویدیو در `mrdastbaaf_notes`.
3. `membershipService.ts`: ذخیره پلن‌های عضویت در `mrdastbaaf_plans`.
4. `paymentService.ts`: ذخیره تاریخچه سفارش‌ها در `mrdastbaaf_orders`.
5. `transferService.ts`: ذخیره فیش‌های کارت به کارت در `mrdastbaaf_orders`.
6. `adminService.ts`: ذخیره چیدمان سکشن‌ها در `mrdastbaaf_home_sections` و صفحات متنی در `mrdastbaaf_editorial_pages`.
7. `videoSecurityService.ts`: کاملاً **درون‌حافظه‌ای (Ephemeral)**؛ توکن‌های اعتبارسنجی موقت ۱۵ دقیقه‌ای تولید می‌کند و وابستگی به حافظه پایدار ندارد.

> ⚠️ **نتیجه معماری:** در فاز تجاری، هیچ‌یک از موارد حقوق دسترسی، وضعیت پرداخت، توکن احراز هویت یا موجودیت پلن‌ها نباید از `localStorage` به عنوان منبع حقیقت استفاده کنند. منبع حقیقت منحصراً سرور امن وردپرس و پایگاه‌داده آن خواهد بود.

---

## ۲. دیاگرام متنی معماری سامانه (System Architecture Diagram)

```text
+----------------------------------------------------------------------------------------------------+
|                                    CLIENT TIER (PWA)                                               |
|  - Mobile-First RTL Interface (React 19 / Vite / PWA Service Worker)                               |
|  - Offline Caching of Shell, Manifest & Static Assets via Workbox                                  |
|  - In-App Install Prompt (Android / iOS Safari Guide)                                              |
|  - Branded Video Player UI with Dynamic Moving Watermark Overlay                                   |
|  - STRICT CLIENT RULE: No direct DB calls, no merchant keys, no raw video URLs, no role promotion  |
+-------------------------------------------------+--------------------------------------------------+
                                                  | HTTPS / WSS
                                                  | Bearer Token / HttpOnly Secure Cookies
                                                  v
+----------------------------------------------------------------------------------------------------+
|                               SECURE BACKEND / API PROXY GATEWAY                                   |
|  [Custom WordPress REST API Endpoints OR Dedicated Node.js / Express Proxy / Edge Gateway]         |
|                                                                                                    |
|  RESPONSIBILITIES & PERIMETERS:                                                                    |
|  1. Rate Limiting (IP/Phone rate limits on OTP requests & receipt uploads)                         |
|  2. Token Verification & Session Management (Issues short-lived JWT / Secure Session)               |
|  3. Authorization & RBAC Enforcement (current_user_can('manage_options') for admin)                |
|  4. Server-to-Server Payment Verification with Zibal IPG (POST /v1/verify)                         |
|  5. Video Stream Ticket Signing (Generates ephemeral Signed HLS URLs / Tokenized playback URL)     |
|  6. Audit Logging & Replay-Attack Prevention                                                       |
+--------+------------------+-------------------+--------------------+------------------+------------+
         |                  |                   |                    |                  |
         | Internal Calls   | Internal DB/REST  | Internal DB/REST   | Server-to-Server | CDN Token
         v                  v                   v                    v                  v
+-----------------+ +-----------------+ +------------------+ +----------------+ +------------------+
| WORDPRESS /     | | NIAS COURSE     | | MEMBERSHIP &     | | ZIBAL IPG      | | SECURE VIDEO     |
| OTINO OTP       | | LMS SYSTEM      | | ENTITLEMENTS     | | GATEWAY        | | PROVIDER (HLS)   |
|                 | |                 | |                  | |                | |                  |
| - wp_users      | | - Course CPT    | | - Custom tables/ | | - Server Init  | | - Encrypted HLS/ |
| - Phone Lookup  | | - Lesson meta   | |   wp_usermeta    | |   /v1/request  | |   DASH segments  |
| - SMS Provider  | | - Syllabus tree | | - Order History  | | - Server Verify| | - Signed Token |
|   (Kavenegar/   | | - Free preview  | | - Manual Bank    | |   /v1/verify   | |   validation     |
|   FarazSMS)     | |   flags         | |   Receipt queue  | | - Webhook IP   | | - Dynamic Server |
|                 | |                 | | - Expiry Cron    | |   allowlist    | |   Watermarking   |
+-----------------+ +-----------------+ +------------------+ +----------------+ +------------------+
```

---

## ۳. فهرست عملیات API و تفکیک مرجع حقیقت و مجوزها (API Operations Matrix)

| گروه عملیاتی | شناسه و شرح عملیات API | سیستم مالک منبع حقیقت (Source of Truth) | سیستم مسئول صدور مجوز (Authorizer) |
| :--- | :--- | :--- | :--- |
| **Authentication** | `POST /api/auth/otp/request`<br>درخواست ارسال پیامک کد تایید به شماره موبایل | WordPress (`wp_users`) + درگاه پیامک اوتینو | سرور بک‌اند (با اعمال محدودیت نرخ درخواست Rate Limit) |
| **Authentication** | `POST /api/auth/otp/verify`<br>اعتبارسنجی کد ۵ رقمی و صدور سشن/توکن | WordPress (`wp_users`) + اوتینو | سرور وردپرس (تایید کد منقضی‌نشده) |
| **Authentication** | `GET /api/auth/me`<br>استعلام پروفایل، نقش و وضعیت جاری سشن | پایگاه‌داده وردپرس (`wp_users` و متادیتا) | سرور با بررسی امضای توکن/سشن |
| **Courses** | `GET /api/courses`<br>دریافت کاتالوگ دوره‌ها، دسته‌بندی‌ها و مدرسین | پایگاه‌داده نیاس کورس / وردپرس (`wp_posts`) | عمومی (Public Cached) |
| **Courses** | `GET /api/courses/{id}`<br>دریافت ساختار سرفصل‌ها و جلسات دوره | نیاس کورس (`wp_posts` و `wp_postmeta`) | عمومی (آدرس‌های محافظت‌شده ویدیو هرگز برگردانده نمی‌شوند) |
| **Memberships** | `GET /api/memberships/plans`<br>دریافت پلن‌های فعال و تعرفه‌های دستی | جدول پلن‌های عضویت در دیتابیس اختصاصی مستر دستباف | عمومی (Public) |
| **Memberships** | `GET /api/memberships/my-status`<br>استعلام سطح فعال، تاریخ انقضا و روزهای باقی‌مانده | جدول اعتبارات عضویت کاربر (`user_memberships`) | کاربر صاحب حساب (Bearer Token) |
| **Checkout** | `POST /api/orders/create`<br>ایجاد پیش‌فاکتور خرید یا تمدید دستی اشتراک | جدول سفارش‌های پلتفرم (`mrdastbaaf_orders`) | کاربر احراز هویت شده |
| **Checkout (Zibal)** | `POST /api/payment/zibal/initiate`<br>درخواست ایجاد تراکنش و دریافت TrackId زیبال | سرور پرداخت پلتفرم + وب‌سرویس زیبال | سرور بک‌اند با مرچنت‌کد محرمانه |
| **Payment Verify** | `POST /api/payment/zibal/verify`<br>تایید دوطرفه سرور-به-سرور تراکنش زیبال | سرور رسمی درگاه زیبال (`gateway.zibal.ir`) | سرور بک‌اند پلتفرم (احراز `result == 100`) |
| **Manual Transfer** | `POST /api/payment/manual-transfer/submit`<br>ثبت فیش واریزی و شماره پیگیری کارت‌به‌کارت | جدول سفارشات (وضعیت اولیه: `pending_review`) | کاربر احراز هویت شده (منع فعال‌سازی خودکار) |
| **Manual Transfer** | `PUT /api/admin/receipts/{orderId}/review`<br>تایید یا رد فیش کارت‌به‌کارت با درج علت | جدول سفارشات و جدول عضویت کاربر | سرور وردپرس با مجوز `manage_options` |
| **Video Auth** | `POST /api/lessons/{id}/playback-ticket`<br>استعلام حق دسترسی و صدور توکن امضاشده HLS | جدول تطبیق دسترسی (کاربر + نیاس + عضویت) | سرور بک‌اند (احراز فعال بودن اشتراک متناسب) |
| **Admin** | `GET /api/admin/analytics`<br>گزارش مالی، اعضای فعال و صف فیش‌ها | پایگاه داده سفارشات و اعضا | سرور وردپرس با مجوز `manage_options` |
| **Admin** | `PUT /api/admin/plans/{id}`<br>ویرایش قیمت، عنوان و امکانات پلن عضویت | جدول پلن‌های عضویت | سرور وردپرس با مجوز `manage_options` |
| **Admin** | `PUT /api/admin/homepage-sections`<br>تغییر چیدمان، ترتیب و متن بنرهای صفحه نخست | تنظیمات وردپرس (`wp_options`) | سرور وردپرس با مجوز `manage_options` |

---

## ۴. طرح مدل داده اختصاصی عضویت و دسترسی‌ها (Data Model Proposal)

با توجه به اینکه **نباید فرض کنیم افزونه نیاس کورس سیستم اشتراک دوره‌ای (Subscription) دارد**، مدل داده زیر به صورت جدول‌های مستقل یا ساختار متای بهینه‌شده در وردپرس پیاده‌سازی می‌شود:

### ۴.۱ جدول پلن‌های عضویت (`wp_mrdastbaaf_plans`)
- `id`: شناسه یکتا (VARCHAR / UUID)
- `tier`: سطح عضویت (`bronze`, `silver`, `gold`)
- `title`: نام فارسی پلن (مثال: «اشتراک نقره‌ای ۳ ماهه»)
- `price_toman`: مبلغ ریالی/تومانی
- `original_price_toman`: مبلغ قبل از تخفیف (اختیاری)
- `duration_days`: مدت اعتبار به روز (۳۰, ۹۰, ۱۸۰, ۳۶۵)
- `duration_label`: برچسب فارسی (مثال: «۳ ماهه»)
- `features_json`: آرایه JSON از امکانات و دسترسی‌ها
- `is_popular`: نشان بولین برای انتخاب پیشنهادی
- `is_active`: فعال بودن برای فروش
- `created_at` / `updated_at`: تاریخچه تغییرات

### ۴.۲ جدول وضعیت عضویت هنرجویان (`wp_mrdastbaaf_user_memberships`)
- `id`: شناسه یکتا
- `user_id`: کلید خارجی به `wp_users.ID`
- `plan_id`: کلید خارجی به `wp_mrdastbaaf_plans.id`
- `tier`: سطح فعال جاری (`bronze`, `silver`, `gold`)
- `status`: وضعیت اشتراک (`active`, `expired`, `grace_period`, `cancelled`)
- `started_at`: زمان آغاز اعتبار (UTC Timestamp)
- `expires_at`: زمان دقیق پایان اعتبار (UTC Timestamp)
- `last_order_id`: کلید خارجی به آخرین سفارش مرتبط
- `auto_renew`: **همواره مقدار FALSE** (به منظور عدم فعال‌سازی تمدید خودکار)
- *ایندکس‌ها:* بر روی `user_id` و `expires_at` جهت سرعت بالای استعلام دسترسی

### ۴.۳ جدول سفارش‌ها و تلاش‌های پرداخت (`wp_mrdastbaaf_orders`)
- `id`: شناسه سیستمی سفارش
- `order_number`: شماره پیگیری خوانا (مثال: `MD-84910`)
- `user_id`: شناسه خریدار در `wp_users`
- `plan_id`: شناسه پلن درخواستی
- `amount_toman`: مبلغ خالص فاکتور
- `gateway`: نوع درگاه (`zibal`, `card_to_card`)
- `status`: وضعیت فاکتور (`pending`, `completed`, `failed`, `rejected`)
- `zibal_track_id`: شناسه یکتای پیگیری درگاه زیبال
- `zibal_ref_number`: شماره مرجع شاپرک (پس از واریز موفق)
- `verified_at`: زمان دقیق تایید سرور
- `created_at`: زمان ایجاد سفارش

### ۴.۴ جدول جزئیات فیش کارت‌به‌کارت (`wp_mrdastbaaf_manual_transfers`)
- `order_id`: کلید خارجی به `wp_mrdastbaaf_orders.id`
- `tracking_code`: شماره پیگیری یا شماره ارجاع فیش بانکی
- `card_last_four`: ۴ رقم پایانی کارت مبدا
- `deposit_date_jalali`: تاریخ واریز اعلامی کاربر
- `receipt_file_path`: آدرس امن فایل آپلودشده فیش در سرور
- `sender_notes`: توضیحات کاربر
- `reviewer_admin_id`: شناسه ادمین بررسی‌کننده در `wp_users`
- `admin_notes`: علت رد یا یادداشت تایید ادمین
- `reviewed_at`: زمان بررسی

---

## ۵. چک‌لیست امنیتی جامع (Security Checklist)

### ۵.۱ مدیریت OTP و جلوگیری از سوءاستفاده پیامکی (SMS Rate Limiting):
- [ ] اعمال Rate Limit سخت‌گیرانه بر روی IP و شماره موبایل (حداکثر ۱ پیامک در هر ۲ دقیقه برای هر شماره؛ حداکثر ۵ درخواست در ساعت برای هر IP).
- [ ] طول عمر کد OTP حداکثر ۲ دقیقه؛ مسدودسازی شماره پس از ۳ بار ورود کد اشتباه برای ۳۰ دقیقه.
- [ ] ذخیره کد هش‌شده OTP در کش ردیس/ترنزینت وردپرس (عدم لاگ کردن متن کد در فایل‌ها).

### ۵.۲ مدیریت سشن و احراز هویت:
- [ ] صدور توکن سشن به صورت کوکی امن با فلگ‌های `HttpOnly` ،`Secure` و `SameSite=Lax`.
- [ ] منع کامل ذخیره توکن‌های دارای سطح دسترسی ادمین در `localStorage` کلاینت.
- [ ] انقضای سشن‌های کاربری و امکان ابطال دسته‌جمعی از سمت سرور (Revoke All Sessions).

### ۵.۳ محافظت CSRF و پیکربندی CORS:
- [ ] هدر `X-WP-Nonce` یا توکن اختصاصی ضد جعل CSRF برای تمام درخواست‌های با متد `POST`, `PUT`, `DELETE`.
- [ ] بستن CORS سرور و مجاز دانستن صرف دامنه اختصاصی فرانت‌اند (`https://mrdastbaaf.com`).

### ۵.۴ تفکیک نقش‌ها و اختیارات (RBAC Enforcement):
- [ ] هیچ اندپوینت مدیریتی (تایید فیش، ویرایش پلن، چیدمان هوم‌پیج) نباید به فیلد ارسالی نقش از سمت کلاینت اعتماد کند.
- [ ] تمام کنترلرهای سرور باید تابع `current_user_can('manage_options')` وردپرس را ارزیابی کنند.

### ۵.۵ تایید تراکنش و جلوگیری از Replay Attack در زیبال:
- [ ] منع اکید فعال‌سازی دسترسی بر مبنای پارامترهای بازگشتی URL مرورگر (`callback?success=true`).
- [ ] استعلام مستقیم سرور-به-سرور به `https://gateway.zibal.ir/v1/verify` با ارسال `trackId` و `merchantKey`.
- [ ] بررسی یکتایی `zibal_ref_number` در پایگاه داده جهت جلوگیری از ثبت تکراری یک تراکنش (Idempotency Key).
- [ ] اطمینان از تطابق دقیق مبلغ تایید شده توسط درگاه با مبلغ اولیه فاکتور (`amount == order.amount`).

### ۵.۶ حفاظت از استریم ویدیو:
- [ ] منع مطلق انتشار آدرس خام MP4 در وب یا دسترسی مستقیم بدون مجوز.
- [ ] استفاده از پلیر استریم HLS با چانک‌های رمزنگاری شده (AES-128 / Widevine).
- [ ] صدور توکن‌های موقت دارای امضای دیجیتال (Signed Playback Tokens) با انقضای ۱۵ دقیقه‌ای مقید به IP و User-Agent.
- [ ] واترمارک متحرک شامل شناسه کاربر و شماره موبایل ماسک‌شده در لایه رندر پلیر.

### ۵.۷ حریم خصوصی و لاگ‌گیری:
- [ ] ماسک کردن شماره تلفن‌ها در لاگ‌ها و رابط‌های کاربری عمومی (`۰۹۱۲***۶۷۸۹`).
- [ ] پاکسازی متادیتای حساس فایل‌های فیش بانکی (EXIF) پیش از ذخیره در هاست.

---

## ۶. مجهولات نیازمند مستندات رسمی (Official Unknowns)

پیش از اقدام به کدنویسی یکپارچه‌سازی، موارد زیر مجهول بوده و نیازمند دریافت از پشتیبانی فنی است:

1. **افزونه پیامک اوتینو (Otino):**
   - آیا اوتینو مسیر REST API استاندارد دارد یا وب‌هوک داخلی است؟
   - متدهای ذخیره نشست در وردپرس چیست؟
   - الگوی پترن (Pattern Code) ثبت شده در سامانه پیامکی چیست؟
2. **افزونه دوره‌ها (Nias Course):**
   - آیا جداول دیتابیس نیاس کورس از ساختار استاندارد پست‌تایپ وردپرس تبعیت می‌کنند یا جداول سفارشی دارند؟
   - فیلدهای تعیین دروس رایگان/پیش‌نمایش (`free_preview`) با چه کلید متایی ذخیره شده‌اند؟
3. **درگاه پرداخت زیبال (Zibal):**
   - دریافت کلید تستی (Sandbox Merchant) زیبال جهت راه‌اندازی استیجینگ بدون پرداخت وجه واقعی.
   - تایید دامنه ثبت شده در پنل زیبال برای Callback URL.
4. **میزبانی و استریم ویدیو:**
   - سرویس قطعی استریم ویدیو (ابرآروان، ویدیو سی‌دی‌ان، هاست اختصاصی یا اسپات‌پلیر) کدام است؟
   - مستندات متد API صدور Signed URL سرویس انتخابی چیست؟

---

## ۷. نقشه راه تست در محیط استیجینگ (Staging Test Plan)

تست مرحله‌ای روی محیط Staging صرفاً با **حساب‌های فیک و درگاه تستی**:
1. **تست احراز هویت اوتینو در حالت Sandboxed:**
   - استفاده از ۲ شماره موبایل آزمایشی ثابت برای تست کدهای ورود.
   - سنجش مسدودسازی شماره پس از درخواست‌های رگباری (Rate Limit Test).
2. **تست استعلام کاتالوگ و کنترل دسترسی نیاس کورس:**
   - ورود با اکانت فاقد اشتراک -> درس‌های پیش‌نمایش قابل مشاهده، درس‌های اصلی قفل.
   - بررسی عدم افشای آدرس ویدیو در تب Network ابزار توسعه‌دهنده (DevTools).
3. **تست پرداخت در سندباکس زیبال:**
   - سناریوی الف: تراکنش موفق در محیط Sandbox -> تایید سرور -> فعال‌سازی آنی اشتراک و نمایش در داشبورد.
   - سناریوی ب: بستن صفحه درگاه یا لغو کاربر -> عدم فعال‌سازی و ثبت وضعیت لغو.
   - سناریوی ج: تست شبیه‌سازی قطعی ارتباط بعد از پرداخت -> ثبت در صف تراکنش‌های معلق جهت استعلام مجدد.
4. **تست فیش کارت‌به‌کارت:**
   - ارسال فیش فرضی توسط هنرجوی تستی -> قرارگیری در وضعیت `در انتظار بررسی`.
   - ورود با حساب ادمین تستی -> بررسی فیش -> تایید -> فعال‌سازی دسترسی هنرجو.
   - سناریوی رد فیش با درج علت -> مشاهده پیام علت رد در داشبورد هنرجو بدون فعال‌سازی دسترسی.
5. **تست انقضای زمانی و تمدید دستی:**
   - تنظیم دستی تاریخ انقضای اکانت تستی به گذشته -> قفل شدن خودکار دروس -> اطمینان از عدم برداشت خودکار وجه -> هدایت کاربر به صفحه تمدید دستی.

---

## ۸. مراحل پیاده‌سازی و معیارهای پذیرش (Phased Implementation Plan)

### فاز ۱: زیرساخت سرور و لایه واسط امنیتی (Bridge API Infrastructure)
- **اقدامات:** پیاده‌سازی اندپوینت‌های REST در وردپرس برای ارتباط ایمن با PWA، تعریف ساختار جداول پلن‌ها و عضویت‌ها.
- **معیار پذیرش (Acceptance Criteria):** ارتباط سرور با احراز هویت توکن‌های امضاشده تایید شود و خروجی ساختاریافته بدون نیاز به کلاینت مستقیماً تست شود.

### فاز ۲: اتصال احراز هویت اوتینو (Otino Phone Auth Integration)
- **اقدامات:** اتصال سرویس `authService.ts` به API واقعی اوتینو، فعال‌سازی تایمر پیامک و حذف کلیدهای دمو با حفظ لاگ ایمن.
- **معیار پذیرش:** ارسال پیامک واقعی روی موبایل تستی و ایجاد سشن در `wp_users` همراه با اعمال محدودیت نرخ درخواست.

### فاز ۳: همگام‌سازی کاتالوگ دوره‌ها و دروس نیاس (Nias Catalogue Sync)
- **اقدامات:** اتصال `courseService.ts` به کوئری‌های استاندارد نیاس در وردپرس.
- **معیار پذیرش:** سرفصل‌ها و توضیحات دوره‌ها مستقیماً از محتوای دیتابیس وردپرس بارگذاری شده و دروس محافظت‌شده بدون توکن معتبر پاسخ ندهند.

### فاز ۴: اتصال درگاه زیبال و فیش‌های کارت‌به‌کارت (Zibal & Manual Transfers)
- **اقدامات:** اتصال `paymentService.ts` به وب‌سرویس زیبال با متد تایید سرور-به-سرور (`verify`)، فعال‌سازی فرم آپلود فیش کارت‌به‌کارت با صف بررسی ادمین.
- **معیار پذیرش:** خرید موفق در سندباکس زیبال اشتراک را فعال کند؛ فیش کارت‌به‌کارت تنها با تایید دستی ادمین فعال شود.

### فاز ۵: پلیر استریم امن و واترمارک سازمانی (Secure Video Delivery)
- **اقدامات:** اتصال پلیر به سرویس استریم با Signed HLS و واترمارک پویا بر اساس اطلاعات کاربر لاگین‌شده.
- **معیار پذیرش:** ویدیو در کیفیت‌های مختلف استریم شده و هیچ لینک دانلودی در کدهای فرانت‌اند آشکار نباشد.

---

## ۹. مدارک و اطلاعات مورد نیاز پیش از شروع اولین اتصال واقعی (Blocking Requirements)

برای شروع پیاده‌سازی **اولین اتصال واقعی (فاز ۲ و فاز ۴)**، اقلام زیر باید رسماً در اختیار تیم توسعه قرار گیرد:
1. **دسترسی به محیط استیجینگ وردپرس (Staging URL & Admin Test Credentials)** جهت بررسی ساختار افزونه‌های نصب‌شده اوتینو و نیاس.
2. **مستندات وب‌سرویس یا نمونه کدهای افزونه اوتینو (Otino Documentation)** برای متد ارسال و اعتبارسنجی پیامک.
3. **مرچنت‌آیدی تستی یا سندباکس زیبال (Zibal Sandbox Key)** جهت تست پیاده‌سازی وب‌هوک و وریفای سروری.
4. **مشخصات هاست استریم یا ارائه‌دهنده ویدیوی پلتفرم (Video Provider API Docs)** جهت تنظیم کلید خصوصی امضای استریم (Signing Key).
