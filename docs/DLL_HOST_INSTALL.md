# نصب و تست Hotlink Protection روی dll.mrdastbaaf.com

> هاست شما: cPanel 138 + Apache 2.4.67 + BitNinja WAF (60GB package)

---

## گام ۱ — نصب فایل

۱. وارد cPanel `dll.mrdastbaaf.com` شوید (cpdl1)
۲. به **File Manager** بروید
۳. به ریشهٔ دامنهٔ `dll.mrdastbaaf.com` بروید (معمولاً `public_html/dll` یا `public_html` اگه addon domain است)
۴. فایل `docs/DLL_HOST_HTACCESS.sample` از ریپو را دانلود کنید
۵. آن را در ریشهٔ `dll.mrdastbaaf.com` آپلود کنید
۶. نام فایل را به `.htaccess` تغییر دهید (نقطه در ابتدا مهم است)

**نکتهٔ امنیتی:** اگه از قبل `.htaccess` در ریشه دارید، محتوای آن را با محتوای جدید **merge** کنید (نه replace). می‌توانید با File Manager ویرایش کنید و خطوط جدید را به انتهای فایل موجود اضافه کنید.

---

## گام ۲ — تست

### تست ۱: ویدیو باید از اپ کار کند (مثبت)

در اپ مستر دستباف یک ویدیو را پخش کنید — باید بدون مشکل کار کند (چون مرورگر Referer از app.mrdastbaaf.com می‌فرستد).

### تست ۲: URL کپی‌شده باید ۴۰۳ بگیرد (منفی)

URL ویدیو را از DevTools کپی کنید و در یک **مرورگر جدید** (ترجیحاً incognito) در آدرس‌بار بچسبانید. باید چیزی شبیه این ببینید:

```
Forbidden

You don't have permission to access this resource.
```

### تست ۳: curl از ترمینال (اختیاری، برای تأیید فنی)

```bash
# باید 403 بدهد (Referer غلط از سایت دیگر):
curl -I \
  -H 'User-Agent: Mozilla/5.0 (Linux; Android 13) Chrome/120.0.0.0 Mobile' \
  -H 'Referer: https://t.me/somechannel' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'

# باید 200 بدهد (Referer درست):
curl -I \
  -H 'User-Agent: Mozilla/5.0 (Linux; Android 13) Chrome/120.0.0.0 Mobile' \
  -H 'Referer: https://app.mrdastbaaf.com/' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'

# باید 200 بدهد (بدون Referer — مثل آدرس‌بار):
curl -I \
  -H 'User-Agent: Mozilla/5.0 (Linux; Android 13) Chrome/120.0.0.0 Mobile' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'
```

**توجه:** به‌خاطر BitNinja WAF، شاید curl بدون User-Agent درست، کپچا ببیند. حتماً User-Agent مرورگر را بفرستید.

---

## گام ۳ — troubleshoot

### مشکل ۱: کل سایت ۵۰۰ Internal Server Error می‌زند

**علت:** syntax قدیمی `Order/Allow` در Apache 2.4. یا directive ای غیرفعال.

**راه‌حل:**
- مطمئن شوید فایل از نسخهٔ v1.5.2+ استفاده می‌کند (که `Require env` دارد)
- اگه باز هم ۵۰۰ زد، تمام محتوای `.htaccess` را موقتاً پاک کنید و فقط خطوط زیر را بگذارید:

```apache
<IfModule mod_setenvif.c>
  SetEnvIf Referer "^https?://([a-z0-9-]+\.)?mrdastbaaf\.com/" ALLOWED_HOTLINK=1
  SetEnvIf Referer "^$" ALLOWED_HOTLINK=1

  <FilesMatch "\.(mp4|webm|ogg|mov|m4v)$">
    <IfModule mod_authz_host.c>
      Require env ALLOWED_HOTLINK
    </IfModule>
  </FilesMatch>
</IfModule>
```

### مشکل ۲: ویدیوها در اپ هم خراب شدند (۴۰۳)

**علت:** مرورگر شما Referer نمی‌فرستد (privacy browser، Safari با anti-tracking).

**راه‌حل اول:** مرورگر را تست کنید — Chrome و Firefox پیش‌فرض Referer می‌فرستند.

**راه‌حل دوم:** اگه کاربران قانونی هم ۴۰۳ می‌گیرند، خط `SetEnvIf Referer "^$" ALLOWED_HOTLINK=1` را تأیید کنید که کامنت **نشده** باشد (این خط درخواست‌های بدون Referer را مجاز می‌کند).

### مشکل ۳: URL کپی‌شده در آدرس‌بار هنوز ۲۰۰ می‌دهد

**علت ۱:** `.htaccess` در جای اشتباه است. مطمئن شوید در ریشهٔ دامنه (جایی که پوشهٔ `FA/` هست) قرار دارد.

**علت ۲:** `mod_setenvif` غیرفعال است. در cPanel → **MultiPHP INI Editor** → ببینید آیا `setenvif` در لیست extension های فعال است. روی هاست اشتراکی معمولاً فعال است، ولی اگه نبود، به پشتیبانی هاست تیکت بزنید.

**علت ۳:** BitNinja WAF درخواست را قبل از Apache پردازش می‌کند. شاید `.htaccess` دیررس باشد. در این صورت، به پشتیبانی هاست بگویید «می‌خواهم hotlink protection روی ویدیوها اعمال کنم — `mod_setenvif` فعال است؟»

### مشکل ۴: ویدیوها در تلگرام/اینستاگرام هم کار می‌کنند (نمی‌خواهیم)

این رفتار صحیح است! تلگرام و اینستاگرام Referer نمی‌فرستند (یا Referer خودشان را می‌فرستند). اگه بخواهید این‌ها هم مسدود شوند، باید خط `SetEnvIf Referer "^$" ALLOWED_HOTLINK=1` را کامنت کنید — ولی این کار ممکن است برخی کاربران قانونی را هم مسدود کند.

---

## گام ۴ — تست‌های پیشرفته

### تست با User-Agent های مختلف

```bash
# iOS Safari:
curl -I -H 'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15' \
  -H 'Referer: https://app.mrdastbaaf.com/' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'

# Android Chrome:
curl -I -H 'User-Agent: Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120.0.0.0 Mobile' \
  -H 'Referer: https://app.mrdastbaaf.com/' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'
```

هر دو باید ۲۰۰ بدهند.

### تست سایت‌های غیرمجاز

```bash
# باید 403:
curl -I -H 'User-Agent: Mozilla/5.0' \
  -H 'Referer: https://evil-site.com/' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'

# باید 403:
curl -I -H 'User-Agent: Mozilla/5.0' \
  -H 'Referer: https://t.me/somechannel' \
  'https://dll.mrdastbaaf.com/FA/TabloFarsh/01%20teaser%20tablo%20farsh.mp4'
```

---

## نکتهٔ نهایی

این لایه ۹۰٪ اشتراک‌گذاری کژوال را متوقف می‌کند. یک هکر حرفه‌ای می‌تواند Referer را با curl `--referer` جعل کند، ولی کاربر عادی این کار را نمی‌کند.

برای حفاظت قوی‌تر (لایهٔ ۲ PHP proxy یا لایهٔ ۳ HLS امضاشده)، به `docs/VIDEO_SECURITY.md` مراجعه کنید.
