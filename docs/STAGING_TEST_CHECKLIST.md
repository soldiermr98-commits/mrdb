# چک‌لیست تست Staging — نسخه 1.2.0 (برنچ fix/prelive-hardening)

> 📌 **به‌روزرسانی:** نوشته‌شده برای 1.2.0؛ همچنان مبنای تست Staging است. موارد نسخه‌های بعد (خرید تک‌دوره‌ای/فروشگاه/کیفیت) را از بخش ۱۴ [FullDoc.MD](../FullDoc.MD) و [CHANGELOG.md](../CHANGELOG.md) اضافه کنید.


> پیش‌نیازها: افزونهٔ 1.2.0 نصب و فعال؛ WooCommerce فعال؛ دستهٔ `courses` موجود؛ پیکربندی «مستر دستباف → تنظیمات» کامل (مرچنت تستی زیبال، فرانت، هاست‌های ویدیو، پیامک). برای تست سندباکس زیبال در staging فقط: `define('MRDASTBAAF_ALLOW_SANDBOX', true);` در wp-config.php.

## ۱. تست Bearer (اول از همه — curl)

```bash
BASE=https://STAGING/wp-json/mrdastbaaf/v1
# توکن بگیر:
curl -s -X POST $BASE/auth/request-otp -H 'Content-Type: application/json' -d '{"phone":"09XXXXXXXXX"}'
curl -s -X POST $BASE/auth/verify-otp -H 'Content-Type: application/json' -d '{"phone":"09XXXXXXXXX","otp_code":"XXXXXX"}'
TOKEN=...
curl -s $BASE/auth/me -H "Authorization: Bearer $TOKEN"                          # انتظار: 200 پروفایل
curl -s -o /dev/null -w '%{http_code}\n' $BASE/auth/me                           # انتظار: 401
curl -s -X POST $BASE/payment/zibal/initiate -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"plan_id":"plan-bronze-1m"}'
curl -s -o /dev/null -w '%{http_code}\n' -X POST $BASE/payment/zibal/initiate -H 'Content-Type: application/json' -d '{"plan_id":"plan-bronze-1m"}'   # انتظار: 401
curl -s -X POST $BASE/payment/manual-transfer/submit -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"plan_id":"plan-bronze-1m","tracking_code":"TEST12345","card_last_four":"1234","deposit_date":"1404/07/22"}'
# CORS preflight هدر Authorization:
curl -s -o /dev/null -w '%{http_code}\n' -X OPTIONS $BASE/auth/me -H 'Origin: https://app.mrdastbaaf.com' -H 'Access-Control-Request-Method: GET' -H 'Access-Control-Request-Headers: authorization'
```

## ۲. OTP

- [ ] کد ۶ رقمی می‌آید؛ کد اشتباه → پیام «تلاش باقی‌مانده» (نه «جلسه منقضی»)
- [ ] ۵ تلاش غلط → قفل؛ ۶ درخواست کد در یک ساعت برای یک شماره → 429
- [ ] ورود با شمارهٔ کاربر مدیر وردپرس → 403 «ورود با این شماره به اپ هنرجو مجاز نیست»
- [ ] logout → توکن revoke می‌شود (الگوی curl: `curl -X POST $BASE/auth/logout -H "Authorization: Bearer $TOKEN"` سپس /auth/me با همان توکن → 401)

## ۳. پرداخت زیبال (merchant تستی + سندباکس با ثابت)

- [ ] بدون مرچنت در تنظیمات → initiate = 503 «درگاه پرداخت هنوز پیکربندی نشده است»
- [ ] پرداخت موفق → برگشت به `/#/payment/callback?orderId=…&cb_token=…&trackId=…&success=1` (دقت کن زیبال پارامترها را با `?` یا `&` می‌چسباند — هر دو باید کار کنند) → toast موفق → اشتراک فعال در داشبورد
- [ ] کال‌بک تکراری همان سفارش → پاسخ «قبلاً تایید گردیده است» (بدون تمدید دوباره)
- [ ] انصراف در درگاه → verify زیبال 202 → failed
- [ ] قطع شبکهٔ سرور حین verify (فایروال موقت به gateway.zibal.ir) → سفارش باید در pending بماند و ظرف ۱۰ دقیقه کرون دوباره verify کند
- [ ] بستن مرورگر قبل از کال‌بک → حداکثر ۱۰ دقیقه بعد کرون سفارش را کامل می‌کند (ریکنسیلیشن) — لاگ: `mrdastbaaf_last_reconcile_log`
- [ ] کال‌بک با cb_token درست ولی سفارش قدیمی‌تر از ۴۸ ساعت → 403
- [ ] کرون سرور واقعی: `curl -s "$BASE/cron/reconcile?token=TOKEN_از_تنظیمات"` → {success:true, log:…}

## ۴. اشتراک (تمدید/ارتقا/تنزل + ایدمپوتنسی)

- [ ] دو بار finalize همان سفارش (کال‌بک + کرون هم‌زمان) → فقط یک‌بار grant (جدول grants یک ردیف با order_id)
- [ ] تمدید هم‌سطح → انقضا = انقضای قبلی + مدت پلن
- [ ] ارتقا (برنزی فعال → خرید طلایی) → اشتراک طلایی از «الان» + روزهای اضافهٔ تبدیل‌شده؛ رکورد قبلی `upgraded`
- [ ] خرید سطح پایین‌تر با اشتراک فعال بالاتر → 409 با پیام فارسی

## ۵. فیش کارت به کارت

- [ ] ثبت فیش → pending در GET /me/orders (adminNote فقط در rejected)
- [ ] تایید و رد هم‌زمان توسط دو ادمین → فقط اولی برنده است (409 برای دومی)
- [ ] رد بدون علت → 400؛ پس از رد، همان tracking_code+کارت قابل ثبت مجدد است
- [ ] audit log برای تایید/رد ثبت می‌شود (جدول mrdastbaaf_audit_log)

## ۶. پخش ویدیو

- [ ] ذخیرهٔ درس با ویدیوی هاست خارج از allowlist → 400
- [ ] پیش‌نمایش رایگان مهمان → بلیط + پخش بدون لینک در هیچ پاسخ عمومی
- [ ] انقضای بلیط (۱۵ دقیقه) → تمدید از داخل پلیر
- [ ] ۳۱ درخواست بلیط در ساعت از یک IP → 429
- [ ] UI: در پخش واقعی سوییچ کیفیت جعلی نیست («کیفیت: طبق فایل اصلی»)

## ۷. فرانت Real

- [ ] ورود → پیشرفت قبلی (درس‌های تکمیل‌شده/نشان‌شده) حفظ می‌شود؛ تغییر بعدی ظرف ~۲ ثانیه PUT می‌شود
- [ ] داشبورد → سفارش‌ها از سرور با برچسب فارسی وضعیت
- [ ] `switchRole` غیرفعال/throw؛ دیتای دمو پس از logout پاک می‌شود

## ۸. آپدیت خودکار PWA (نسخهٔ 1.5.5/1.7.4 به بعد)

- [ ] `curl "$BASE/version"` → `pwa_version` برابر فیلد «نسخهٔ مستقر PWA» در پیشخوان (تنظیمات ← کارت «نسخه و به‌روزرسانی PWA»)
- [ ] تغییر فیلد نسخه به عدد «بزرگ‌تر» + ذخیره → تا ۲ دقیقه بعد (یا اولین بازگشت به اپ)، کلاینت 1.5.4+ خودکار reload می‌شود و در `#/debug` نسخهٔ جدید را نشان می‌دهد
- [ ] تغییر فیلد نسخه به عدد «کوچک‌تر» + ذخیره → هیچ reload خودکاری رخ نمی‌دهد (ضد حلقه — فیکس v1.5.5)
- [ ] ورود مقدار نامعتبر در فیلد نسخه (مثلاً «abc») → ذخیره نمی‌شود؛ مقدار قبلی حفظ می‌شود
- [ ] ذخیرهٔ فیلد نسخه → `content_hash` در `GET /version` عوض می‌شود → ویوها داده‌ها را بدون reload کامل refetch می‌کنند (قیمت‌ها/پلن‌ها)
- [ ] پس از نصب افزونهٔ 1.7.4 روی سایتی که قبلاً برایش SQL دستی INSERT زده شده → فقط یک ردیف `mrdastbaaf_pwa_version` در wp_options می‌ماند (فلگ `mrdastbaaf_options_norm_174`)
- [ ] اپ نصب‌شده (standalone) → همان رفتار reload خودکار را دارد؛ تست نصب روی اندروید (WebAPK) حداقل یک‌بار
