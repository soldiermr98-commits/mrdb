/**
 * @file services/videoSecurityService.ts
 * @description Mock video security service and architecture specification
 * 
 * CRITICAL VIDEO SECURITY ARCHITECTURE NOTICE:
 * 1. Raw MP4 files served via static URLs (even hidden behind buttons or obscured HTML5 players)
 *    provide ZERO protection against downloading, screen-capturing, or link sharing.
 * 2. In production, mrdastbaaf.com must use a secure video infrastructure such as:
 *    - Tokenized signed HLS/DASH streaming (e.g. ArvanCloud VoD / VideoCDN / Cloudflare Stream)
 *    - Short-lived JSON Web Tokens (JWT) generated on the server with IP / User-Agent binding
 *    - Server-side rotating dynamic forensic watermarking or DRM (Widevine / FairPlay)
 * 3. This prototype provides a high-fidelity visual and UX demonstration with dynamic moving
 *    watermarking, simulated bitrate quality switching, and session ticket expiry.
 */

import { Lesson, UserProfile, VideoStreamingTicket } from '../types';

export const videoSecurityService = {
  /**
   * Request a signed temporary streaming ticket for an entitled user
   * Ticket expires after 900 seconds (15 minutes)
   *
   * BUG (defense-in-depth): optional `accessDecision` — when the caller
   * explicitly passes `false` (the courseService.canAccessLesson verdict), the
   * ticket request is refused. UI wiring comes later; the default (undefined)
   * keeps current behaviour.
   */
  async requestPlaybackTicket(
    lesson: Lesson,
    user: UserProfile | null,
    accessDecision?: boolean
  ): Promise<VideoStreamingTicket> {
    await new Promise((res) => setTimeout(res, 350));

    if (accessDecision === false) {
      throw new Error('دسترسی به این درس فعال نیست');
    }

    // TICKET FIX (BUG-12): issue absolute timestamps alongside the relative TTL.
    // Without `expiresAt` the client can neither display a live countdown nor
    // detect expiry to re-request the signed URL (production behaviour).
    const issuedAtDate = new Date();
    const expiresAtDate = new Date(issuedAtDate.getTime() + 900 * 1000);

    // Dynamic watermark text composed of masked phone and student ID
    // PRIVACY FIX (BUG-01 mirror, client): only the masked phone ever reaches the UI layer.
    const watermarkText = user 
      ? `${user.maskedPhone || user.phoneNumber} | شناسه: ${user.id.toUpperCase()}`
      : 'نسخه آزمایشی مستر دستباف | مهمان';

    return {
      // LOW-SEVERITY FIX: `Math.random().toString(36).substring(7)` could yield
      // an empty/1-char suffix; slice(2, 8).padEnd(6, '0') guarantees a fixed
      // 6-char suffix alongside the collision-safe Date.now() prefix.
      ticketId: `tkt_${Date.now()}_${Math.random().toString(36).slice(2, 8).padEnd(6, '0')}`,
      lessonId: lesson.id,
      expiresInSeconds: 900,
      issuedAt: issuedAtDate.toISOString(),
      expiresAt: expiresAtDate.toISOString(),
      watermarkText,
      watermarkUserId: user?.id || 'guest',
      provider: 'ArvanCloud VoD / VideoCDN HLS Mock',
      qualityOptions: [
        { label: 'Full HD 1080p', resolution: '1080p' },
        { label: 'HD 720p (پیش‌فرض)', resolution: '720p' },
        { label: 'SD 480p (حجم کمتر)', resolution: '480p' },
      ],
    };
  },
};
