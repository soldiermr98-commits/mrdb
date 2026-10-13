/**
 * @file types/index.ts
 * @description Domain types and interfaces for Mr. Dastbaaf Handicraft Learning Platform
 */

export type UserRole = 'student' | 'instructor' | 'admin';

export type MembershipTier = 'free' | 'bronze' | 'silver' | 'gold';

export interface UserMembership {
  tier: MembershipTier;
  planId: string;
  planName: string;
  status: 'active' | 'expired' | 'pending';
  startedAt: string; // ISO date
  expiresAt: string; // ISO date
  formattedExpireDateJalali: string; // e.g. "۱۴۰۵/۰۲/۱۵"
  daysRemaining: number;
}

export interface UserProfile {
  id: string;
  phoneNumber: string; // e.g. "09123456789"
  maskedPhone: string; // e.g. "0912***6789"
  fullName: string;
  avatarUrl?: string;
  role: UserRole;
  membership?: UserMembership;
  completedLessonIds: string[];
  bookmarkedLessonIds: string[];
  createdAt: string;
}

export type CourseLevel = 'مقدماتی' | 'متوسط' | 'پیشرفته';

export type CourseCategory = 
  | 'قالی‌بافی و گلیم'
  | 'رودوزی‌های سنتی'
  | 'مکرمه و منسوجات'
  | 'رنگرزی طبیعی'
  | 'طراحی نقشه و چله‌کشی';

export interface LessonNote {
  id: string;
  lessonId: string;
  timestampSeconds: number;
  content: string;
  createdAt: string;
}

export interface Lesson {
  id: string;
  courseId: string;
  sectionId: string;
  title: string;
  durationMinutes: number;
  order: number;
  isFreePreview: boolean;
  requiredTier: MembershipTier;
  description: string;
  videoTokenId?: string; // Token ID for secure stream (never raw MP4 url)
}

export interface CourseSection {
  id: string;
  courseId: string;
  title: string;
  order: number;
  lessons: Lesson[];
}

export interface Course {
  id: string;
  title: string;
  slug: string;
  shortDescription: string;
  fullDescription: string;
  category: CourseCategory;
  level: CourseLevel;
  instructorName: string;
  instructorBio: string;
  instructorAvatar: string;
  thumbnailUrl: string;
  coverImageUrl: string;
  totalDurationHours: number;
  totalLessonsCount: number;
  requiredTier: MembershipTier; // Minimum tier required to watch all lessons
  priceToman: number; // One-time purchase alternative price
  featured: boolean;
  sections: CourseSection[];
  learningOutcomes: string[];
  prerequisites: string[];
}

export type PlanDuration = '1_month' | '3_months' | '6_months' | '12_months';

export interface MembershipPlan {
  id: string;
  tier: MembershipTier;
  title: string;
  description: string;
  priceToman: number;
  originalPriceToman?: number;
  duration: PlanDuration;
  durationLabel: string; // e.g. "۱ ماهه", "۳ ماهه", "۱ ساله"
  features: string[];
  isPopular?: boolean;
  isActive: boolean;
  colorScheme: 'slate' | 'amber' | 'stone' | 'yellow';
}

export type PaymentGatewayType = 'zibal' | 'card_to_card';
export type OrderStatus = 'pending' | 'processing' | 'completed' | 'failed' | 'rejected';

export interface ManualTransferDetails {
  trackingCode: string;
  sourceCardLastFour: string;
  depositDateJalali: string;
  receiptImageName?: string;
  senderNotes?: string;
  adminReviewNote?: string;
  reviewedAt?: string;
}

export interface PaymentOrder {
  id: string;
  orderNumber: string;
  userId: string;
  userPhone: string;
  userFullName: string;
  itemType: 'membership' | 'course';
  itemId: string;
  itemTitle: string;
  amountToman: number;
  gateway: PaymentGatewayType;
  status: OrderStatus;
  createdAt: string;
  createdAtJalali: string;
  verifiedAt?: string;
  zibalTrackId?: string;
  zibalRefNumber?: string;
  manualTransferDetails?: ManualTransferDetails;
}

export interface HomepageSectionConfig {
  id: string;
  key: 'hero' | 'categories' | 'featured_courses' | 'membership_plans' | 'artisan_story' | 'faq';
  title: string;
  subtitle?: string;
  isEnabled: boolean;
  sortOrder: number;
  configData?: Record<string, any>;
}

export interface EditorialPage {
  slug: string;
  title: string;
  content: string;
  excerpt: string;
  lastUpdatedJalali: string;
}

export interface VideoStreamingTicket {
  ticketId: string;
  lessonId: string;
  expiresInSeconds: number;
  /** TICKET FIX (BUG-12): absolute ISO expiry so the player can compute live TTL and re-auth. */
  issuedAt: string;
  expiresAt: string;
  watermarkText: string;
  watermarkUserId: string;
  provider: 'ArvanCloud VoD / VideoCDN HLS Mock';
  qualityOptions: Array<{ label: string; resolution: string }>;
}
