#!/usr/bin/env bash
# ===========================================================================
# encode-qualities.sh — چندکیفیته‌سازی دسته‌جمعی ویدیوهای مستر دستباف
# ---------------------------------------------------------------------------
# این اسکریپت برای کارفرما/ادمین سایت است تا بتواند فایل‌های ویدیوی ۴۸۰p/
# ۷۲۰p/۱۰۸۰p را برای هر درس بسازد. خروجی‌ها را روی هاست مجاز ویدیو
# (dll.mrdastbaaf.com) آپلود کنید و سپس URL ها را در پیشخوان وردپرس:
#   مستر دستباف ← درس‌ها و ویدیوها ← ویرایش درس
# در فیلدهای «کیفیت ۴۸۰p (URL)» / «۷پ۲p» / «۱۰۸۰p» ثبت کنید.
#
# پس از آن، منوی کیفیت در پلیر اپ به‌صورت خودکار فعال می‌شود (انتخاب‌گر واقعی
# با حفظ جای پخش).
#
# ---------------------------------------------------------------------------
# پیش‌نیاز: ffmpeg روی سیستم نصب باشد.
#   macOS:  brew install ffmpeg
#   Linux: sudo apt install ffmpeg
#   Windows: از https://ffmpeg.org دانلود کنید
#
# نصب بودن ffmpeg را با `ffmpeg -version` تست کنید.
#
# ---------------------------------------------------------------------------
# استفاده:
#
#   ۱) تک فایل:
#       ./scripts/encode-qualities.sh path/to/input.mp4
#
#   ۲) همهٔ فایل‌های یک پوشه (batch):
#       ./scripts/encode-qualities.sh path/to/videos/
#
#   ۳) فقط کیفیت‌های خاص (پیش‌فرض: همه):
#       QUALITIES="480,720" ./scripts/encode-qualities.sh input.mp4
#
# خروجی: کنار هر فایل ورودی، سه فایل با پسوند -480p.mp4 / -720p.mp4 /
#        -1080p.mp4 ساخته می‌شود. فایل اصلی دست‌نخورده می‌ماند.
#
# ---------------------------------------------------------------------------
# امنیت واترمارک: این اسکریپت فقط کیفیت/رزولوشن فایل را تغییر می‌دهد؛
# واترمارک اختصاصی کاربر (موبایل ماسک‌شده + user_id) در زمان پخش توسط سرور
# به‌صورت DOM overlay اعمال می‌شود و نیازی به embed در فایل ویدیو ندارد.
# ===========================================================================

set -euo pipefail

# ---------------------------------------------------------------------------
# پیکربندی — قابل override با env
# ---------------------------------------------------------------------------
QUALITIES="${QUALITIES:-480,720,1080}"
# میزان CRF (کیفیت فشرده‌سازی) — عدد کمتر = کیفیت بهتر + حجم بیشتر.
# ۲۳ پیش‌فرض ffmpeg است. برای ویدیوی آموزشی، ۲۸ برای ۴۸۰p و ۲۳ برای ۱۰۸۰p منطقی است.
CRF_480="${CRF_480:-28}"
CRF_720="${CRF_720:-25}"
CRF_1080="${CRF_1080:-23}"
# preset: ultrafast/superfast/veryfast/faster/fast/medium/slow/slower/veryslow
# medium پیش‌فرض است؛ slow حجم کمتر می‌دهد ولی طول می‌کشد.
PRESET="${PRESET:-medium}"
# audio bitrate
AUDIO_BITRATE="${AUDIO_BITRATE:-128k}"

# ---------------------------------------------------------------------------
# رنگ برای پیام‌ها (اگر ترمینال پشتیبانی کرد)
# ---------------------------------------------------------------------------
if [ -t 1 ]; then
  GREEN='\033[0;32m'
  YELLOW='\033[0;33m'
  RED='\033[0;31m'
  CYAN='\033[0;36m'
  NC='\033[0m'
else
  GREEN='' YELLOW='' RED='' CYAN='' NC=''
fi

log()   { echo -e "${GREEN}[✓]${NC} $*"; }
warn()  { echo -e "${YELLOW}[!]${NC} $*"; }
err()   { echo -e "${RED}[✗]${NC} $*" >&2; }
info()  { echo -e "${CYAN}[i]${NC} $*"; }

# ---------------------------------------------------------------------------
# بررسی نصب ffmpeg
# ---------------------------------------------------------------------------
if ! command -v ffmpeg >/dev/null 2>&1; then
  err "ffmpeg نصب نیست."
  echo ""
  echo "نصب:"
  echo "  macOS:  brew install ffmpeg"
  echo "  Linux:  sudo apt install ffmpeg   یا   sudo dnf install ffmpeg"
  echo "  Windows: از https://ffmpeg.org دانلود کنید"
  exit 1
fi

# ---------------------------------------------------------------------------
# پارس آرگومان
# ---------------------------------------------------------------------------
if [ $# -lt 1 ]; then
  echo "استفاده:"
  echo "  $0 <file-or-dir>            — پردازش یک فایل یا همهٔ فایل‌های یک پوشه"
  echo "  QUALITIES=480,720 $0 <dir>  — فقط کیفیت‌های مشخص"
  echo ""
  echo "مثال‌ها:"
  echo "  $0 ~/Downloads/lesson-1.mp4"
  echo "  $0 /var/www/videos/"
  echo "  QUALITIES=720 $0 /var/www/videos/   — فقط 720p"
  exit 1
fi

INPUT="$1"
IFS=',' read -ra Q_ARRAY <<< "$QUALITIES"

# ---------------------------------------------------------------------------
# تبدیل تک فایل
# ---------------------------------------------------------------------------
encode_file() {
  local input="$1"
  local basename
  local dirname

  basename="$(basename "$input" .mp4)"
  dirname="$(dirname "$input")"

  # اگه فایل ورودی خودش پسوند -480p/-720p/-1080p دارد، رد شو (جلوگیری از پردازش دوبل)
  if [[ "$basename" =~ -[0-9]+p$ ]]; then
    warn "رد شد (خروجی کیفیت است): $input"
    return 0
  fi

  info "در حال پردازش: $input"
  local input_info
  input_info="$(ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=s=x:p=0 "$input" 2>/dev/null || echo "?")"
  info "رزولوشن ورودی: ${input_info}"

  for q in "${Q_ARRAY[@]}"; do
    local output="${dirname}/${basename}-${q}p.mp4"
    local scale_filter
    local crf

    case "$q" in
      480)  scale_filter="scale=-2:480";  crf="$CRF_480" ;;
      720)  scale_filter="scale=-2:720";  crf="$CRF_720" ;;
      1080) scale_filter="scale=-2:1080"; crf="$CRF_1080" ;;
      *)    err "کیفیت نامعتبر: $q (معتبر: 480, 720, 1080)"; continue ;;
    esac

    if [ -f "$output" ]; then
      warn "وجود دارد، رد شد: $output"
      continue
    fi

    info "ساختن ${q}p ... (CRF=${crf}, preset=${PRESET})"
    if ffmpeg -y -i "$input" \
      -vf "$scale_filter" \
      -c:v libx264 -preset "$PRESET" -crf "$crf" \
      -profile:v high -level 4.0 \
      -pix_fmt yuv420p \
      -c:a aac -b:a "$AUDIO_BITRATE" -ac 2 \
      -movflags +faststart \
      "$output" 2>&1 | tail -5; then
      local size_h
      size_h="$(du -h "$output" | cut -f1)"
      log "${q}p ساخته شد: ${output} (${size_h})"
    else
      err "شکست در ${q}p: $input"
      return 1
    fi
  done

  log "تمام: $input"
}

# ---------------------------------------------------------------------------
# پردازش فایل یا پوشه
# ---------------------------------------------------------------------------
if [ -f "$INPUT" ]; then
  encode_file "$INPUT"
elif [ -d "$INPUT" ]; then
  info "حالت batch — پردازش همهٔ فایل‌های .mp4 در: $INPUT"
  count=0
  failed=0
  while IFS= read -r -d '' f; do
    if encode_file "$f"; then
      count=$((count + 1))
    else
      failed=$((failed + 1))
    fi
  done < <(find "$INPUT" -type f -name '*.mp4' -not -name '*-[0-9]*p.mp4' -print0)
  echo ""
  log "خلاصه: ${count} فایل موفق، ${failed} شکست"
else
  err "مسیر یافت نشد: $INPUT"
  exit 1
fi

echo ""
echo "───────────────────────────────────────────────────────────────"
echo "مرحلهٔ بعدی:"
echo "  ۱) فایل‌های -480p.mp4 / -720p.mp4 / -1080p.mp4 را روی هاست مجاز"
echo "     ویدیو (dll.mrdastbaaf.com) آپلود کنید."
echo "  ۲) در پیشخوان وردپرس ← مستر دستباف ← درس‌ها و ویدیوها ← ویرایش"
echo "     درس، فیلدهای کیفیت 480p/720p/1080p را با URL کامل https پر کنید."
echo "  ۳) منوی کیفیت در پلیر اپ به‌صورت خودکار فعال می‌شود."
echo "───────────────────────────────────────────────────────────────"
