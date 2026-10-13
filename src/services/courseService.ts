/**
 * @file services/courseService.ts
 * @description Service for managing courses and lessons, simulating Nias Course WordPress plugin
 *
 * TODO: Official Nias Course WordPress database schemas, custom post types, lesson media access
 * tables, and REST endpoints are currently unknown and pending documentation.
 * Do not invent production Nias Course endpoints until documentation is provided.
 */

import { INITIAL_COURSES } from '../data/mockData';
import { Course, CourseCategory, Lesson, LessonNote, MembershipTier, UserProfile } from '../types';
import { authService } from './authService';
import { hasStringId, readJsonArray } from './storageGuard';
import { isLive } from '../utils/format';

const STORAGE_KEY_COURSES = 'mrdastbaaf_courses';
const STORAGE_KEY_NOTES = 'mrdastbaaf_notes';

const TIER_RANKS: Record<MembershipTier, number> = {
  free: 0,
  bronze: 1,
  silver: 2,
  gold: 3,
};

// BUG-29: corrupt courses storage no longer crashes the app on boot — the
// shared storage guard heals the key and quarantines malformed rows.
const inMemoryCourses: Course[] = readJsonArray<Course>(
  STORAGE_KEY_COURSES,
  INITIAL_COURSES,
  hasStringId
);

// BUG-30: seed note belongs to the demo student so the per-user notes demo is
// visible right away (legacy rows without userId stay hidden — see getNotes).
const SEED_NOTES: LessonNote[] = [
  {
    id: 'note-1',
    lessonId: 'lsn-carpet-1',
    userId: 'user-std-1',
    timestampSeconds: 120,
    content: 'نکته کلیدی: برای دار تبریز کشش نخ‌های چله باید صدادار شبیه تار موسیقی باشد.',
    createdAt: '2026-09-25T14:00:00Z',
  },
];

let inMemoryNotes: LessonNote[] = readJsonArray<LessonNote>(
  STORAGE_KEY_NOTES,
  SEED_NOTES,
  hasStringId
);

function persistNotes(): void {
  try {
    localStorage.setItem(STORAGE_KEY_NOTES, JSON.stringify(inMemoryNotes));
  } catch (e) {
    // ignore
  }
}

export const courseService = {
  /**
   * Get all courses with optional filters
   */
  async getCourses(filters?: {
    category?: CourseCategory | 'all';
    searchTerm?: string;
    level?: string;
  }): Promise<Course[]> {
    await new Promise((res) => setTimeout(res, 200));
    let result = [...inMemoryCourses];

    if (filters?.category && filters.category !== 'all') {
      result = result.filter((c) => c.category === filters.category);
    }

    if (filters?.searchTerm && filters.searchTerm.trim() !== '') {
      const q = filters.searchTerm.trim().toLowerCase();
      result = result.filter(
        (c) =>
          c.title.toLowerCase().includes(q) ||
          c.shortDescription.toLowerCase().includes(q) ||
          c.instructorName.toLowerCase().includes(q)
      );
    }

    if (filters?.level && filters.level !== 'all') {
      result = result.filter((c) => c.level === filters.level);
    }

    return result;
  },

  /**
   * Get single course by ID or slug
   */
  async getCourseById(courseId: string): Promise<Course | null> {
    await new Promise((res) => setTimeout(res, 150));
    return inMemoryCourses.find((c) => c.id === courseId || c.slug === courseId) || null;
  },

  /**
   * Check if a user can access a specific lesson
   */
  canAccessLesson(user: UserProfile | null, lesson: Lesson): {
    canAccess: boolean;
    reason: string;
    requiredTier: MembershipTier;
  } {
    // 1. Free preview lessons are open to everyone
    if (lesson.isFreePreview || lesson.requiredTier === 'free') {
      return {
        canAccess: true,
        reason: 'پیش‌نمایش رایگان این درس برای همه کاربران باز است.',
        requiredTier: 'free',
      };
    }

    // 2. Not logged in
    if (!user) {
      return {
        canAccess: false,
        reason: 'برای دسترسی به این درس، لطفاً با شماره موبایل خود وارد شوید.',
        requiredTier: lesson.requiredTier,
      };
    }

    // 3. Admin has master access for inspection
    if (user.role === 'admin') {
      return {
        canAccess: true,
        reason: 'دسترسی مدیریت ارشد پلتفرم',
        requiredTier: lesson.requiredTier,
      };
    }

    // 4. Check active membership tier
    // SECURITY FIX (BUG-08): `expiresAt` must be validated server-side in production,
    // and must ALSO be validated here. Previously an expired membership
    // (status still 'active' in localStorage) granted full lesson access.
    const userMembership = user.membership;
    // BUG-32: the manual `new Date(...).getTime() <= Date.now()` comparison made
    // NaN-expiry (corrupted date) evaluate to `NaN <= x` === false → access GRANTED.
    // The shared isLive() fails closed on invalid dates, matching
    // membershipService.isMembershipLive.
    const expiryIsLive = !!userMembership && isLive(userMembership.expiresAt);
    if (!userMembership || userMembership.status !== 'active' || !expiryIsLive) {
      return {
        canAccess: false,
        reason:
          userMembership && !expiryIsLive
            ? 'اشتراک شما منقضی شده است. برای دسترسی مجدد، اشتراک خود را تمدید کنید.'
            : `برای مشاهده این درس، نیاز به خرید یا تمدید اشتراک ${lesson.requiredTier === 'gold' ? 'طلایی' : lesson.requiredTier === 'silver' ? 'نقره‌ای' : 'برنزی'} دارید.`,
        requiredTier: lesson.requiredTier,
      };
    }

    const userRank = TIER_RANKS[userMembership.tier] ?? 0;
    const requiredRank = TIER_RANKS[lesson.requiredTier] ?? 1;

    if (userRank >= requiredRank) {
      return {
        canAccess: true,
        reason: `دسترسی فعال با اشتراک ${userMembership.planName}`,
        requiredTier: lesson.requiredTier,
      };
    }

    return {
      canAccess: false,
      reason: `این درس نیازمند اشتراک ${lesson.requiredTier === 'gold' ? 'طلایی' : 'نقره‌ای'} است (اشتراک فعلی شما: ${userMembership.planName}).`,
      requiredTier: lesson.requiredTier,
    };
  },

  /**
   * Get notes for a lesson
   */
  getNotes(lessonId: string): LessonNote[] {
    // BUG-30: notes are per-user — filter by the current user id.
    // Legacy rows without `userId` keep userId === '' on load, so they belong
    // to no one and stay hidden (soft migration, no rewrite script needed).
    const currentUserId = authService.getCurrentUser()?.id ?? 'guest';
    return inMemoryNotes.filter((n) => n.lessonId === lessonId && n.userId === currentUserId);
  },

  /**
   * Save a note
   */
  addNote(lessonId: string, timestampSeconds: number, content: string): LessonNote {
    // BUG-31: timestamp must be a finite non-negative number.
    const ts = Number(timestampSeconds);
    if (!Number.isFinite(ts) || ts < 0) {
      throw new Error('ثانیه یادداشت نامعتبر است');
    }
    // BUG-31: content must be non-empty after trim and at most 1000 chars.
    const trimmedContent = (content ?? '').trim();
    if (trimmedContent.length === 0) {
      throw new Error('متن یادداشت نمی‌تواند خالی باشد.');
    }
    if (trimmedContent.length > 1000) {
      throw new Error('متن یادداشت حداکثر ۱۰۰۰ کاراکتر است');
    }

    const newNote: LessonNote = {
      id: `note-${Date.now()}`,
      lessonId,
      // BUG-30: stamp the author so notes never leak across accounts.
      userId: authService.getCurrentUser()?.id ?? 'guest',
      timestampSeconds: ts,
      content: trimmedContent,
      createdAt: new Date().toISOString(),
    };
    inMemoryNotes.push(newNote);
    persistNotes();
    return newNote;
  },

  /**
   * BUG-31: delete one note by id (memory + storage). Exported for the
   * upcoming UI wiring (delete button on each note).
   */
  deleteNote(noteId: string): void {
    inMemoryNotes = inMemoryNotes.filter((n) => n.id !== noteId);
    persistNotes();
  },

  /**
   * Admin update course
   */
  async updateCourse(courseId: string, updates: Partial<Course>): Promise<Course> {
    const idx = inMemoryCourses.findIndex((c) => c.id === courseId);
    if (idx === -1) throw new Error('دوره یافت نشد.');
    inMemoryCourses[idx] = { ...inMemoryCourses[idx], ...updates };
    try {
      localStorage.setItem(STORAGE_KEY_COURSES, JSON.stringify(inMemoryCourses));
    } catch (e) {
      // ignore
    }
    return inMemoryCourses[idx];
  },
};
