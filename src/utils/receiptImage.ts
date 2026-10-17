/**
 * @file utils/receiptImage.ts
 * @description فشرده‌سازی تصویر فیش کارت‌به‌کارت در سمت مرورگر — v1.5.7
 *
 * گزارش کارفرما: «تصویر فیش تو پیشخوان باید دیده بشه» + تاکید بر حجم/بهینگی.
 * عکس با دوربین موبایل معمولاً ۳-۸ مگابایت است؛ قبل از ارسال به سرور با canvas
 * به حداکثر ۱۲۸۰px و JPEG کیفیت ~۰.۷۸ فشرده می‌شود (معمولاً ۱۵۰-۳۵۰ کیلوبایت)
 * تا هم آپلود روی اینترنت ایران سریع باشد هم پهنای‌باند/دیسک هاست کم مصرف شود.
 *
 * گاردها: خروجی هرگز بزرگ‌تر از فایل اصلی نمی‌شود؛ اگر canvas/فایل به هر دلیل
 * نشکست، خود فایل اصلی (با سقف اندازهٔ سرور) برگردانده می‌شود تا ثبت فیش مسدود نشود.
 */

export const RECEIPT_IMAGE_MAX_DIM = 1280;
export const RECEIPT_IMAGE_QUALITY = 0.78;
/** سقف سخت فایل خام — هم‌تراز سقف سرور (افزونه) */
export const RECEIPT_IMAGE_MAX_RAW_BYTES = 4 * 1024 * 1024;

export interface CompressedReceiptImage {
  blob: Blob;
  /** نام نمایشی برای UI — پسوند بر اساس خروجی واقعی تصحیح می‌شود */
  fileName: string;
}

function isSupportedImageType(type: string): boolean {
  return type === 'image/jpeg' || type === 'image/png' || type === 'image/webp';
}

/**
 * فشرده‌سازی تصویر فیش — Promise روی FileReader/createImageBitmap/canvas.
 * هر خطا → null (تماس‌گیرنده فایل خام را می‌فرستد).
 */
export async function compressReceiptImage(file: File): Promise<CompressedReceiptImage | null> {
  if (!file || !isSupportedImageType(file.type)) return null;
  // GIF/SVG/HEIC و... از قبل رد شده‌اند؛ فایل‌های خیلی کوچک اصلاً فشرده نمی‌شوند
  if (file.size > RECEIPT_IMAGE_MAX_RAW_BYTES) {
    return { blob: file, fileName: file.name };
  }
  if (typeof document === 'undefined' || typeof URL?.createObjectURL !== 'function') return null;

  try {
    const bitmap = await loadBitmap(file);
    if (!bitmap) return null;

    const bitmapW = 'width' in bitmap ? (bitmap.width as number) : 1;
    const bitmapH = 'height' in bitmap ? (bitmap.height as number) : 1;
    const scale = Math.min(1, RECEIPT_IMAGE_MAX_DIM / Math.max(bitmapW || 1, bitmapH || 1));
    const targetW = Math.max(1, Math.round(bitmapW * scale));
    const targetH = Math.max(1, Math.round(bitmapH * scale));

    const canvas = document.createElement('canvas');
    canvas.width = targetW;
    canvas.height = targetH;
    const ctx = canvas.getContext('2d');
    if (!ctx) {
      closeBitmap(bitmap);
      return null;
    }
    // پس‌زمینهٔ سفید: فیش‌های PNG شفاف روی JPEG سیاه نشوند
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, targetW, targetH);
    ctx.drawImage(bitmap as CanvasImageSource, 0, 0, targetW, targetH);
    closeBitmap(bitmap);

    const blob = await canvasToJpeg(canvas, RECEIPT_IMAGE_QUALITY);
    if (!blob) return null;
    // اگر فشرده‌سازی بی‌فایده بود (بزرگ‌تر از خام)، خام می‌رود
    if (blob.size >= file.size) return { blob: file, fileName: file.name };

    return {
      blob,
      fileName: buildCompressedName(file.name),
    };
  } catch {
    return null;
  }
}

async function loadBitmap(file: File): Promise<ImageBitmap | HTMLImageElement | null> {
  const url = URL.createObjectURL(file);
  try {
    if (typeof createImageBitmap === 'function') {
      return await createImageBitmap(file);
    }
    return await new Promise<HTMLImageElement>((resolve, reject) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => reject(new Error('image decode failed'));
      img.src = url;
    });
  } catch {
    return null;
  } finally {
    // ImageBitmap در صورت ساخت از file، URL را لازم ندارد؛ HTMLImageElement هم تا onload لود شده
    window.setTimeout(() => URL.revokeObjectURL(url), 2000);
  }
}

/** آزادسازی حافظهٔ منبع تصویر — ImageBitmap.close در مرورگرهای جدید، HTMLImageElement نیازی ندارد */
function closeBitmap(bitmap: ImageBitmap | HTMLImageElement): void {
  if (typeof (bitmap as ImageBitmap).close === 'function') {
    (bitmap as ImageBitmap).close();
  }
}

function canvasToJpeg(canvas: HTMLCanvasElement, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => {
    if (typeof canvas.toBlob !== 'function') {
      resolve(null);
      return;
    }
    canvas.toBlob(
      (blob) => resolve(blob && blob.type === 'image/jpeg' ? blob : null),
      'image/jpeg',
      quality
    );
  });
}

function buildCompressedName(originalName: string): string {
  const base = (originalName || 'receipt').replace(/\.[^.]+$/, '').replace(/[^\w\u0600-\u06FF-]+/g, '-');
  return `${(base || 'receipt').slice(0, 40)}-optimized.jpg`;
}
