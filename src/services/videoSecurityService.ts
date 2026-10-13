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
   */
  async requestPlaybackTicket(
    lesson: Lesson,
    user: UserProfile | null
  ): Promise<VideoStreamingTicket> {
    await new Promise((res) => setTimeout(res, 350));

    // Dynamic watermark text composed of masked phone and student ID
    const watermarkText = user 
      ? `${user.maskedPhone || user.phoneNumber} | شناسه: ${user.id.toUpperCase()}`
      : 'نسخه آزمایشی مستر دستباف | مهمان';

    return {
      ticketId: `tkt_${Date.now()}_${Math.random().toString(36).substring(7)}`,
      lessonId: lesson.id,
      expiresInSeconds: 900,
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
