# Changelog — مستر دستباف (Mr. Dastbaaf)

تمام تغییرات مهم این پروژه در این فایل ثبت می‌شود.
قالب بر اساس [Keep a Changelog](https://keepachangelog.com/) و نسخه‌بندی معنایی (SemVer) است.

---

## [1.0.2] — فاز دوم: رفع باگ‌های High (High-Severity Bug Fixes — Phase 2)

> شرح کامل هر باگ در بخش «۱۱. گزارش فاز دوم» سند `FullDoc.MD` آمده است.

### امنیت افزونه وردپرس (wordpress-plugin/mrdastbaaf-bridge) — نسخه افزونه: 1.0.2

- **[SECURITY] BUG-13:** صفر شدن بی‌صدای قیمت پلن در `PUT /admin/plans/{id}` — قبلاً `intval(null) === 0` و شرط `($price >= 0)` برقرار بود؛ هر درخواست بدون پارامتر `priceToman` قیمت پلن را صفر می‌کرد (فروش رایگان). اکنون قیمت فقط با پارامتر صریح، عددی و نامنفی به‌روزرسانی می‌شود. (`class-admin-controller.php`)
- **[SECURITY] BUG-14:** فعال‌سازی بی‌صدای اشتراک با پلن نامعتبر — `grant_or_extend()` قبلاً با `$plan = null` یک اشتراک «برنزی ۳۰ روزه» پیش‌فرض می‌ساخت (پول اشتراک طلایی هدر می‌رفت). اکنون با پلن نامعتبر/خطای DB مقدار `false` برمی‌گردد و فراخواننده‌ها (verify زیبال + تایید فیش ادمین) سفارش را در وضعیت بررسی دستی نگه می‌دارند و خطای 409 می‌دهند. ترتیب عملیات نیز اصلاح شد: فعال‌سازی اشتراک **قبل از** `completed` شدن سفارش. (`class-membership-helper.php`، `class-payment-controller.php`، `class-admin-controller.php`)
- **[DATA-INTEGRITY] BUG-15:** شکست بی‌صدای ثبت سفارش — `order_number` دارای UNIQUE KEY است اما با `wp_rand(10000,99999)` تولید می‌شد؛ در برخورد، `insert` ناموفق بی‌صدا رد می‌شد و کاربر پیام موفقیت می‌گرفت بدون آنکه سفارشی ثبت شود (پرداخت بدون فاکتور). اکنون: مولد شماره یکتا با retry + بررسی نتیجه `insert` (خطای 500 در صورت شکست) + حذف سفارش یتیم اگر ثبت جدول فیش شکست بخورد. (`class-payment-controller.php`)
- **[SECURITY] BUG-16:** اعتبارسنجی و Rate-Limit ثبت فیش کارت‌به‌کارت — `tracking_code` (۴–۳۰ کاراکتر عدد/حرف انگلیسی)، `card_last_four` (دقیقاً ۴ رقم)، `deposit_date` و طول توضیحات اکنون سمت سرور اعتبارسنجی می‌شوند؛ سقف ۵ فیش در ساعت برای هر کاربر با transient. (`class-payment-controller.php`)
- **[LOGIC] BUG-17:** خطای شبکه موقت به زیبال دیگر سفارش را «failed» نمی‌کند — تایم‌اوت/خطای `wp_remote_post` در verify (که پول ممکن است کسر شده باشد) سفارش را `pending` نگه می‌دارد تا کال‌بک بعدی یا ریکنسیلیشن دوره‌ای دوباره verify کند؛ فقط ردِ صریح زیبال سفارش را failed می‌کند. (`class-payment-controller.php`)

### وب‌اپلیکیشن PWA (src/)

- **[SECURITY] BUG-18:** نمایش «فعال» و دوره‌های باز برای اشتراک منقضی در داشبورد — فیلتر دوره‌های در دسترس و بنر وضعیت فقط `status` را چک می‌کردند (چک `expiresAt` فقط در گیت درس‌ها بود). هلپر مشترک `membershipService.isMembershipLive()` اکنون در داشبورد هنرجو و صفحه عضویت استفاده می‌شود؛ حالت منقضی با بج کهربایی و پیام تمدید نمایش داده می‌شود. (`UserDashboardView.tsx`، `MembershipView.tsx`، `membershipService.ts`)
- **[FIX] BUG-19:** تمدید کلاینت از انقضای فعلی — آینه سرور-side فیکس BUG-04؛ `membershipService.grantOrExtend()` انقضای جدید را از انقضای زنده قبلی محاسبه می‌کند (حفظ روزهای باقی‌مانده) و در هر دو مسیر پرداخت (زیبال + تایید فیش) جایگزین `createMembershipRecord` شد. (`membershipService.ts`، `paymentService.ts`، `transferService.ts`)
- **[DATA-INTEGRITY] BUG-20:** مولدهای یکتای شناسه/شماره سفارش — `ord-${Date.now()}` و شماره ۵ رقمی تصادفی برخوردپذیر بودند؛ `orderStore.nextOrderId()/nextOrderNumber()` با شمارنده + پسوند base36 + کنترل تکراری بودن جایگزین شدند. (`orderStore.ts`، `paymentService.ts`، `transferService.ts`)
- **[SECURITY] BUG-21:** گارد مالکیت verify — `verifyZibalPayment` اکنون بررسی می‌کند سفارش متعلق به کاربر لاگین فعلی باشد (آینه محافظت IDOR سرور)؛ در غیر این صورت خطا می‌دهد. (`paymentService.ts`)
- **[FIX] BUG-22:** اعتبارسنجی فرم فیش کارت‌به‌کارت — نرمال‌سازی ارقام فارسی و اعتبارسنجی کلاینت دقیقاً هم‌خوان با سرور (BUG-16): tracking code، ۴ رقم آخر کارت و تاریخ واریز. (`ManualTransferModal.tsx`، `utils/format.ts`)
- **[FIX] BUG-23:** حذف تاریخ‌های جعلی ذخیره‌شده روی سفارش‌ها — مقدار «هم‌اکنون»/«امروز» برای همیشه در تاریخچه می‌ماند؛ اکنون تاریخ واقعی جلالی (با util مشترک) هنگام ثبت ذخیره و هنگام نمایش زنده از `createdAt` رندر می‌شود (fallback برای رکوردهای قدیمی). (`utils/format.ts`، `paymentService.ts`، `transferService.ts`، `UserDashboardView.tsx`)
- **[FIX] BUG-24:** اعتبارسنجی قیمت پلن در پنل ادمین کلاینت — جلوگیری از NaN/منفی (آینه فیکس سرور BUG-13) با نرمال‌سازی ارقام فارسی + پیش‌نمایش زنده قیمت. (`AdminDashboardView.tsx`)
- **[FIX] BUG-24b:** روز باقی‌مانده و تاریخ انقضای اشتراک زنده محاسبه می‌شوند (`daysUntil`/`formatJalali`) به‌جای مقادیر منجمد زمان خرید. (`utils/format.ts`، `UserDashboardView.tsx`، `MembershipView.tsx`)

### مستندات

- افزودن بخش «۱۱. گزارش فاز دوم: رفع باگ‌های High» به `FullDoc.MD` شامل جدول کامل باگ‌ها، فیکس‌ها و نکات مهاجرت.
- به‌روزرسانی Production Checklist (نکته ریکنسیلیشن زیبال + rate-limit فیش).

### صحت‌سنجی

- `tsc --noEmit`: بدون خطا.
- `vite build` + تولید Service Worker موفق.
- بازبینی دستی فایل‌های PHP ویرایش‌شده (PHP CLI در محیط توسعه در دسترس نبود).

---

## [1.0.1] — فاز اول: رفع باگ‌های بحرانی (Critical Bug Fixes — Phase 1)

> شرح کامل هر باگ در بخش «۱۰. گزارش فاز اول» سند `FullDoc.MD` آمده است.

### امنیت افزونه وردپرس (wordpress-plugin/mrdastbaaf-bridge) — نسخه افزونه: 1.0.1

- **[SECURITY] BUG-01:** نشت شماره موبایل خام در واترمارک بلیط استریم — اکنون فقط فرم ماسک‌شده (`0912***6789`) از سرور صادر می‌شود. (`class-courses-controller.php`)
- **[SECURITY] BUG-02:** افزودن محافظت Brute-Force به `/auth/verify-otp` — حداکثر ۵ تلاش نامعتبر per-OTP؛ با اتمام بودجه، کد سوزانده می‌شود. (`class-auth-controller.php`)
- **[SECURITY] BUG-03:** ایمن‌سازی `/payment/zibal/verify` — توکن کال‌بک HMAC-SHA256 (`cb_token`) با اعتبارسنجی `hash_equals`، تطبیق مبلغ پاسخ verify با فاکتور (جلوگیری از partial payment attack) و idempotency سفارش‌های تکراری. (`class-payment-controller.php`)
- **[FIX] BUG-04:** منطق تمدید اشتراک — کلاس مشترک `MrDastbaaf_Membership_Helper::grant_or_extend()`؛ تمدید از انقضای فعلی (حفظ روزهای باقی‌مانده) به‌جای ساخت رکورد تکراری. فایل جدید `class-membership-helper.php` + اصلاح `class-payment-controller.php` و `class-admin-controller.php`.
- **[SECURITY] BUG-05:** رفع Stored XSS در `PUT /admin/homepage-sections` — Whitelist کلیدها و پاکسازی کامل مقادیر پیش از `update_option`. (`class-admin-controller.php`)
- **[SECURITY] BUG-06:** حذف `http://localhost:3000` از لیست CORS پیش‌فرض پروداکشن — دامنه‌های مجاز از Option وردپرس `mrdastbaaf_allowed_origins` خوانده می‌شوند + هدر `Vary: Origin`. (`mrdastbaaf-bridge.php`)

### وب‌اپلیکیشن PWA (src/)

- **[SECURITY] BUG-07:** خروج واقعی از حساب — حذف fallback خودکار به کاربر نمونه در بوت اپ؛ پس از logout و refresh کاربر مهمان می‌ماند. (`authService.ts`)
- **[SECURITY] BUG-08:** بستن حفره دسترسی با اشتراک منقضی — بررسی `expiresAt` در `canAccessLesson` به‌علاوه status؛ پیام اختصاصی تمدید اشتراک. (`courseService.ts`)
- **[FIX] BUG-09:** یکپارچگی سفارش‌ها — استور مشترک جدید `src/services/orderStore.ts` به‌عنوان منبع واحد حقیقت؛ فیش‌های کارت‌به‌کارت بلافاصله در پنل ادمین دیده می‌شوند؛ گارد idempotency در verify کلاینت. (`paymentService.ts`، `transferService.ts`)
- **[FIX] BUG-10:** تبدیل واقعی تاریخ جلالی انقضای اشتراک با `Intl.DateTimeFormat('fa-IR-u-ca-persian')` به‌جای محاسبه تقریبی `سال − ۶۲۱` (خطای ~۲–۳ ماه). (`membershipService.ts`)
- **[FIX] BUG-11:** سه نقص پلیر ویدیو — ریست تایم‌لاین هنگام تعویض درس، فعال‌سازی کنترل سرعت پخش (قبلاً مرده بود)، پاک‌سازی تیکت قدیمی هنگام سوییچ درس. (`LessonPlayerModal.tsx`)
- **[SECURITY] BUG-12:** تیکت استریم با زمان مطلق `issuedAt`/`expiresAt` (کلاینت + پاسخ REST سرور)، شمارنده زنده TTL در پلیر و تمدید خودکار تیکت در انقضا. (`types/index.ts`، `videoSecurityService.ts`، `LessonPlayerModal.tsx`، `class-courses-controller.php`)

### مستندات

- افزودن بخش «۱۰. گزارش فاز اول: رفع باگ‌های بحرانی» به `FullDoc.MD` شامل جدول کامل باگ‌ها، فیکس‌ها و نکات مهاجرت.
- به‌روزرسانی Production Checklist (الزام `cb_token` در کال‌بک زیبال + Option جدید CORS).

### صحت‌سنجی

- `tsc --noEmit`: بدون خطا.
- بازبینی دستی فایل‌های PHP ویرایش‌شده (PHP CLI در محیط توسعه در دسترس نبود).

---

## [1.0.0] — نسخه اولیه پروتوتایپ

- انتشار اولیه وب‌اپ PWA «مستر دستباف» با ۷ نما (خانه، کاتالوگ، جزئیات دوره، عضویت، داشبورد هنرجو، پنل مدیریت، صفحات تحریریه) و ۷ سرویس شبیه‌سازی‌شده.
- افزونه وردپرس `mrdastbaaf-bridge` v1.0.0 با کنترلرهای Auth، Courses، Memberships، Payment و Admin + جداول اختصاصی دیتابیس.
- ورود پیامکی Mock (کد ثابت ۱۲۳۴۵)، درگاه زیبال شبیه‌سازی‌شده، پلیر ویدیو با واترمارک پویا و تیکت ۱۵ دقیقه‌ای.
- سند `FullDoc.MD` و پلن `docs/PRODUCTION_INTEGRATION_PLAN.md`.
