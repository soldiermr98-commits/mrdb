/**
 * @file services/courseService.ts
 * @description Service for managing courses and lessons, simulating Nias Course WordPress plugin
 */

import { INITIAL_COURSES } from '../data/mockData';
import { Course, CourseCategory, Lesson, LessonNote, MembershipTier, UserProfile } from '../types';

const STORAGE_KEY_COURSES = 'mrdastbaaf_courses';
const STORAGE_KEY_NOTES = 'mrdastbaaf_notes';

const TIER_RANKS: Record<MembershipTier, number> = {
  free: 0,
  bronze: 1,
  silver: 2,
  gold: 3,
};

let inMemoryCourses: Course[] = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_COURSES);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return INITIAL_COURSES;
})();

let inMemoryNotes: LessonNote[] = (() => {
  try {
    const saved = localStorage.getItem(STORAGE_KEY_NOTES);
    if (saved) return JSON.parse(saved);
  } catch (e) {
    // ignore
  }
  return [
    {
      id: 'note-1',
      lessonId: 'lsn-carpet-1',
      timestampSeconds: 120,
      content: 'نکته کلیدی: برای دار تبریز کشش نخ‌های چله باید صدادار شبیه تار موسیقی باشد.',
      createdAt: '2026-09-25T14:00:00Z',
    },
  ];
})();

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
    const userMembership = user.membership;
    if (!userMembership || userMembership.status !== 'active') {
      return {
        canAccess: false,
        reason: `برای مشاهده این درس، نیاز به خرید یا تمدید اشتراک ${lesson.requiredTier === 'gold' ? 'طلایی' : lesson.requiredTier === 'silver' ? 'نقره‌ای' : 'برنزی'} دارید.`,
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
    return inMemoryNotes.filter((n) => n.lessonId === lessonId);
  },

  /**
   * Save a note
   */
  addNote(lessonId: string, timestampSeconds: number, content: string): LessonNote {
    const newNote: LessonNote = {
      id: `note-${Date.now()}`,
      lessonId,
      timestampSeconds,
      content,
      createdAt: new Date().toISOString(),
    };
    inMemoryNotes.push(newNote);
    try {
      localStorage.setItem(STORAGE_KEY_NOTES, JSON.stringify(inMemoryNotes));
    } catch (e) {
      // ignore
    }
    return newNote;
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
