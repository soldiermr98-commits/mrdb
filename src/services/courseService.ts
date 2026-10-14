/**
 * @file services/courseService.ts
 * @description سرویس دوره‌ها Dual-Mode: دمو (mock محلی) و Real-API (پل وردپرس v1.1.0+)
 *
 * v1.2.0: قرارداد /courses و /courses/{id} فریز شده و نرمال‌سازها به غیبت
 * فیلدهای اختیاری (rating/students که از v1.2.0 حذف شده‌اند، duration: null و
 * lessons خالی) با defaultهای ایمن تحمل می‌دهند.
 */

import { INITIAL_COURSES } from '../data/mockData';
import { Course, CourseCategory, CourseLevel, CourseSection, Lesson, LessonNote, MembershipTier, UserProfile } from '../types';
import { ApiError, apiFetch, isRealApiMode } from './apiClient';
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

// ---------------------------------------------------------------------------
// v1.1.0 — Real-API: نرمال‌سازی شکل دوره/درس از «GET /courses» و «GET /courses/{id}».
// قرارداد فریز‌شده: id=slug، تصاویر URL کامل، lessonsCount...؛ درس‌های جزئیات:
// [{id: string, title, isFreePreview, requiredTier: 'bronze'|'silver'|'gold', duration: null}]
// فیلدهای جاافتاده با default پر می‌شوند تا شکل Course/Lesson کلاینت همیشه برقرار بماند.
// ---------------------------------------------------------------------------

interface ApiLessonShape {
  id?: unknown;
  title?: unknown;
  isFreePreview?: unknown;
  requiredTier?: unknown;
  /** قرارداد: null در سرور — در صورت عدد، همان استفاده می‌شود. */
  duration?: unknown;
  description?: unknown;
}

interface ApiCourseShape {
  id?: unknown;
  slug?: unknown;
  /** v1.2.6: شناسهٔ عددی محصول ووکامرس (اندپوینت by-id) */
  productId?: unknown;
  /** v1.2.6: تعداد درس‌های پیش‌نمایش رایگان */
  freePreviewCount?: unknown;
  title?: unknown;
  shortDescription?: unknown;
  fullDescription?: unknown;
  category?: unknown;
  level?: unknown;
  instructorName?: unknown;
  instructorBio?: unknown;
  instructorAvatar?: unknown;
  thumbnailUrl?: unknown;
  coverImageUrl?: unknown;
  image?: unknown;
  totalDurationHours?: unknown;
  lessonsCount?: unknown;
  totalLessonsCount?: unknown;
  requiredTier?: unknown;
  priceToman?: unknown;
  featured?: unknown;
  lessons?: unknown;
  sections?: unknown;
  learningOutcomes?: unknown;
  prerequisites?: unknown;
}

const API_TIERS: MembershipTier[] = ['free', 'bronze', 'silver', 'gold'];

// ---------------------------------------------------------------------------
// v1.2.6 — نقشهٔ id (اسلاگ) → productId عددی از پاسخ سرور؛ جزئیات دوره اول
// با /courses/by-id/{productId} پرسیده می‌شود (قطعی — بدون وابستگی به شکل
// اسلاگ فارسی) و ۴۰۴ آن به مسیر اسلاگ فال‌بک می‌کند.
// ---------------------------------------------------------------------------
const PID_MAP_KEY = 'mrdastbaaf_course_pid_map';
const courseIdToProductId = new Map<string, number>();

try {
  const rawMap = window.localStorage.getItem(PID_MAP_KEY);
  if (rawMap) {
    const parsed = JSON.parse(rawMap) as unknown;
    if (parsed && typeof parsed === 'object') {
      Object.entries(parsed as Record<string, unknown>).forEach(([key, value]) => {
        if (typeof key === 'string' && key !== '' && typeof value === 'number' && Number.isFinite(value) && value > 0) {
          courseIdToProductId.set(key, Math.round(value));
        }
      });
    }
  }
} catch {
  // storage خراب → نقشه خالی؛ با اولین پاسخ سرور دوباره پر می‌شود
}

function rememberProductId(courseId: string, rawProductId: unknown): void {
  const pid = typeof rawProductId === 'number' && Number.isFinite(rawProductId) && rawProductId > 0
    ? Math.round(rawProductId)
    : null;
  if (!pid || courseId === '') return;
  courseIdToProductId.set(courseId, pid);
  try {
    const snapshot: Record<string, number> = {};
    courseIdToProductId.forEach((value, key) => {
      snapshot[key] = value;
    });
    window.localStorage.setItem(PID_MAP_KEY, JSON.stringify(snapshot));
  } catch {
    // quota/privacy mode — نقشهٔ درون حافظه کافی است
  }
}

function apiTier(value: unknown, fallback: MembershipTier = 'bronze'): MembershipTier {
  return API_TIERS.includes(value as MembershipTier) ? (value as MembershipTier) : fallback;
}

function apiCount(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.round(value) : 0;
}

function apiString(value: unknown, fallback = ''): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function apiStringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
}

function normalizeApiLesson(raw: ApiLessonShape, courseId: string, index: number): Lesson {
  return {
    id: apiString(raw.id, `lesson-${courseId}-${index + 1}`),
    courseId,
    sectionId: `${courseId}-curriculum`,
    title: apiString(raw.title, `جلسه ${index + 1}`),
    // قرارداد v1.1.0: duration از سرور null است → ۰ (پلیر در حالت Real از مدت واقعی ویدیو استفاده می‌کند).
    durationMinutes: typeof raw.duration === 'number' && Number.isFinite(raw.duration) && raw.duration > 0
      ? Math.round(raw.duration)
      : 0,
    order: index + 1,
    isFreePreview: raw.isFreePreview === true,
    requiredTier: apiTier(raw.requiredTier),
    description: apiString(raw.description),
  };
}

function normalizeApiSection(rawSection: unknown, courseId: string, index: number): CourseSection {
  const section = (rawSection && typeof rawSection === 'object' ? rawSection : {}) as {
    id?: unknown;
    title?: unknown;
    lessons?: unknown;
  };
  const lessons = Array.isArray(section.lessons)
    ? section.lessons.map((l, i) => normalizeApiLesson(l as ApiLessonShape, courseId, i))
    : [];
  return {
    id: apiString(section.id, `${courseId}-section-${index + 1}`),
    courseId,
    title: apiString(section.title, 'سرفصل‌های دوره'),
    order: index + 1,
    lessons,
  };
}

function normalizeApiCourse(raw: ApiCourseShape): Course {
  const id = apiString(raw.id) || apiString(raw.slug) || `course-${Date.now()}`;
  // v1.2.6: ذخیرهٔ productId برای مسیر by-id در fetchهای بعدی
  rememberProductId(id, raw.productId);
  const thumbnail = apiString(raw.thumbnailUrl) || apiString(raw.coverImageUrl) || apiString(raw.image);
  const sections = Array.isArray(raw.sections)
    ? (raw.sections as unknown[]).map((s, i) => normalizeApiSection(s, id, i))
    : Array.isArray(raw.lessons)
      ? [
          // قرارداد v1.1.0: جزئیات دوره فهرست درس‌های تخت (flat) دارد — یک سکشن مصنوعی
          // ساخته می‌شود تا UI فعلی (course.sections.flatMap(...)) دست‌نخورده کار کند.
          {
            id: `${id}-curriculum`,
            courseId: id,
            title: 'سرفصل‌های دوره',
            order: 1,
            lessons: (raw.lessons as unknown[]).map((l, i) => normalizeApiLesson(l as ApiLessonShape, id, i)),
          },
        ]
      : [];
  const lessonsCount = sections.reduce((sum, s) => sum + s.lessons.length, 0);
  return {
    id,
    slug: apiString(raw.slug) || id,
    title: apiString(raw.title, 'دوره بدون عنوان'),
    shortDescription: apiString(raw.shortDescription),
    fullDescription: apiString(raw.fullDescription),
    // دسته/سطح متن فارسی از سرور می‌آید؛ مقدار ناشناخته فقط فیلتر چیپ‌ها را از دست می‌دهد نه نمایش را.
    category: apiString(raw.category, 'قالی‌بافی و گلیم') as CourseCategory,
    level: apiString(raw.level, 'مقدماتی') as CourseLevel,
    instructorName: apiString(raw.instructorName, 'استاد مستر دستباف'),
    instructorBio: apiString(raw.instructorBio),
    instructorAvatar: apiString(raw.instructorAvatar),
    thumbnailUrl: thumbnail,
    coverImageUrl: apiString(raw.coverImageUrl) || thumbnail,
    totalDurationHours: apiCount(raw.totalDurationHours),
    totalLessonsCount: apiCount(raw.totalLessonsCount ?? raw.lessonsCount) || lessonsCount,
    requiredTier: apiTier(raw.requiredTier, 'bronze'),
    priceToman: apiCount(raw.priceToman),
    featured: raw.featured === true,
    sections,
    learningOutcomes: apiStringArray(raw.learningOutcomes),
    prerequisites: apiStringArray(raw.prerequisites),
    productId:
      typeof raw.productId === 'number' && Number.isFinite(raw.productId) && raw.productId > 0
        ? Math.round(raw.productId)
        : null,
    freePreviewCount: apiCount(raw.freePreviewCount),
  };
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
    // v1.1.0 Real-API: GET /courses?category=&search= → Course[] (id=slug، تصویر URL کامل)
    if (isRealApiMode) {
      const params = new URLSearchParams();
      if (filters?.category && filters.category !== 'all') {
        params.set('category', filters.category);
      }
      if (filters?.searchTerm && filters.searchTerm.trim() !== '') {
        params.set('search', filters.searchTerm.trim());
      }
      const query = params.toString();
      const data = await apiFetch<ApiCourseShape[]>(`/courses${query ? `?${query}` : ''}`);
      const courses = (Array.isArray(data) ? data : []).map((c) => normalizeApiCourse(c));
      // فیلتر سطح (level) در قرارداد API نیست — مثل دمو سمت کلاینت اعمال می‌شود.
      if (filters?.level && filters.level !== 'all') {
        return courses.filter((c) => c.level === filters.level);
      }
      return courses;
    }

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
    // v1.2.6 Real-API: اول مسیر عددی /courses/by-id/{productId} (اگر productId شناخته است)
    // سپس فال‌بک اسلاگ — ۴۰۴ نهایی یعنی دوره واقعاً نیست (UI کارت «دوره یافت نشد» دارد).
    if (isRealApiMode) {
      try {
        const pid = courseIdToProductId.get(courseId);
        if (pid) {
          try {
            const byId = await apiFetch<ApiCourseShape>(`/courses/by-id/${pid}`);
            return normalizeApiCourse(byId);
          } catch (byIdError) {
            // فقط ۴۰۴ به مسیر اسلاگ فال‌بک می‌کند؛ سایر خطاها (شبکه/۵xx) بالا می‌روند
            if (!(byIdError instanceof ApiError) || byIdError.status !== 404) {
              throw byIdError;
            }
          }
        }
        const data = await apiFetch<ApiCourseShape>(`/courses/${encodeURIComponent(courseId)}`);
        return normalizeApiCourse(data);
      } catch (e) {
        // UI فعلی برای ۴۰۴ کارت «دوره یافت نشد» دارد — null معنای همان است.
        if (e instanceof ApiError && e.status === 404) return null;
        throw e;
      }
    }

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
