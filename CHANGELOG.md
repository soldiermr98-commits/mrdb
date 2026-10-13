# Changelog — مستر دستباف (Mr. Dastbaaf)

تمام تغییرات مهم این پروژه در این فایل ثبت می‌شود.
قالب بر اساس [Keep a Changelog](https://keepachangelog.com/) و نسخه‌بندی معنایی (SemVer) است.

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
