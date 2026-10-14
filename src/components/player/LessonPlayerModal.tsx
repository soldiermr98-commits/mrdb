/**
 * @file components/player/LessonPlayerModal.tsx
 * @description Branded video lesson player modal with dynamic forensic watermark, syllabus, and notes
 */

import React, { useState, useEffect, useRef } from 'react';
import {
  Play,
  Pause,
  RotateCcw,
  Volume2,
  VolumeX,
  Maximize,
  Minimize,
  Shield,
  Bookmark,
  CheckCircle,
  ChevronRight,
  ChevronLeft,
  ListVideo,
  FileText,
  Clock,
  AlertCircle,
  Trash2,
} from 'lucide-react';
import { Course, Lesson, LessonNote, UserProfile, VideoStreamingTicket } from '../../types';
import { videoSecurityService } from '../../services/videoSecurityService';
import { courseService } from '../../services/courseService';
import { authService } from '../../services/authService';
import { acquireScrollLock } from '../../utils/scrollLock';

interface LessonPlayerModalProps {
  isOpen: boolean;
  onClose: () => void;
  course: Course;
  currentLesson: Lesson;
  onSelectLesson: (lesson: Lesson) => void;
  currentUser: UserProfile | null;
  /** Legacy toast-only callback (previous App wiring). Prefer onCompleteLesson. */
  onLessonCompleted?: (lessonId: string) => void;
  /** BUG-59: parent-owned completion handler; falls back to authService persistence. */
  onCompleteLesson?: (lessonId: string) => void;
  /** BUG-59: parent-owned bookmark toggle; falls back to authService persistence. */
  onToggleBookmark?: (lessonId: string) => void;
}

export const LessonPlayerModal: React.FC<LessonPlayerModalProps> = ({
  isOpen,
  onClose,
  course,
  currentLesson,
  onSelectLesson,
  currentUser,
  onLessonCompleted,
  onCompleteLesson,
  onToggleBookmark,
}) => {
  const [isPlaying, setIsPlaying] = useState(true);
  const [currentTime, setCurrentTime] = useState(145); // simulated 2:25 into lesson
  // v1.1.0 Real-API: مدت واقعی ویدیو از متادیتای <video> — قرارداد سرور duration: null می‌فرستد.
  const [realVideoDuration, setRealVideoDuration] = useState(0);
  // v1.1.0 Real-API: آخرین لحظهٔ پخش — برای resume پس از تمدید بلیط (ویدیوی تازه از همان‌جا ادامه می‌دهد).
  const resumeTimeRef = useRef<number | null>(null);
  const totalDuration =
    currentLesson.durationMinutes * 60 ||
    (Number.isFinite(realVideoDuration) && realVideoDuration > 0 ? Math.ceil(realVideoDuration) : 0);
  const [playbackSpeed, setPlaybackSpeed] = useState<'0.75' | '1' | '1.25' | '1.5'>('1');
  const [selectedQuality, setSelectedQuality] = useState('720p');
  const [isMuted, setIsMuted] = useState(false);
  const [ticket, setTicket] = useState<VideoStreamingTicket | null>(null);
  // v1.1.0 Real-API: وقتی بلیط videoUrl واقعی دارد، پخش بومی <video> جایگزین شبیه‌ساز می‌شود.
  const isRealPlayback = !!ticket?.videoUrl;
  const [activeTab, setActiveTab] = useState<'syllabus' | 'notes' | 'security'>('syllabus');
  const [newNoteText, setNewNoteText] = useState('');
  const [notes, setNotes] = useState<LessonNote[]>([]);
  const [simulatedNetworkState, setSimulatedNetworkState] = useState<'good' | 'buffering' | 'error'>('good');
  // BUG-61: frozen note timestamp — captured when the textarea gains focus so
  // the label cannot drift while the playhead keeps moving during typing.
  const [noteTs, setNoteTs] = useState<number | null>(null);
  const [noteError, setNoteError] = useState<string | null>(null);
  // BUG-60: end-of-lesson state (replaces the silent loop back to 0).
  const [isEnded, setIsEnded] = useState(false);
  // BUG-58: fullscreen state driven by the fullscreenchange event.
  const [isFullscreen, setIsFullscreen] = useState(false);
  // BUG-62: ticket request failure / unrecoverable expiry.
  const [isTicketFailed, setIsTicketFailed] = useState(false);
  const [isRenewing, setIsRenewing] = useState(false);
  // BUG-59: locally-persisted ids (authService fallback path) so the ✓ / filled
  // bookmark states reflect immediately even when the parent owns user state.
  const [localCompletedIds, setLocalCompletedIds] = useState<string[]>([]);
  const [localBookmarkedIds, setLocalBookmarkedIds] = useState<string[]>([]);
  const [localToast, setLocalToast] = useState<string | null>(null);

  const stageRef = useRef<HTMLDivElement>(null);
  // v1.1.0 Real-API: المان ویدیوی واقعی (فقط در حالت اتصال واقعی mount می‌شود).
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const toastTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const autoCompletedRef = useRef<string | null>(null);

  const showLocalToast = (message: string) => {
    // BUG-59: lightweight in-player toast for the fallback persistence paths.
    setLocalToast(message);
    if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    toastTimerRef.current = setTimeout(() => setLocalToast(null), 3000);
  };

  // BUG-59: completion — prefer the parent-provided handler (onCompleteLesson,
  // legacy onLessonCompleted); otherwise persist directly through authService's
  // whitelisted completedLessonIds merge and surface a toast.
  const persistComplete = (lessonId: string) => {
    const handler = onCompleteLesson ?? onLessonCompleted;
    if (handler) {
      handler(lessonId);
      return;
    }
    try {
      const user = authService.getCurrentUser();
      if (!user) {
        showLocalToast('برای ثبت تکمیل جلسه ابتدا وارد حساب کاربری شوید.');
        return;
      }
      const completed = user.completedLessonIds ?? [];
      if (!completed.includes(lessonId)) {
        authService.updateCurrentUser({ completedLessonIds: [...completed, lessonId] });
        setLocalCompletedIds((prev) => (prev.includes(lessonId) ? prev : [...prev, lessonId]));
      }
      showLocalToast('این جلسه با موفقیت تکمیل شد!');
    } catch (err: any) {
      showLocalToast(err?.message || 'خطا در ثبت تکمیل جلسه');
    }
  };

  // BUG-59: bookmark toggle — mirrors the completion fallback logic
  // (toggle inside bookmarkedLessonIds + updateCurrentUser).
  const persistToggleBookmark = (lessonId: string) => {
    if (onToggleBookmark) {
      onToggleBookmark(lessonId);
      return;
    }
    try {
      const user = authService.getCurrentUser();
      if (!user) {
        showLocalToast('برای بوکمارک کردن جلسه ابتدا وارد حساب کاربری شوید.');
        return;
      }
      const bookmarked = user.bookmarkedLessonIds ?? [];
      const willBookmark = !bookmarked.includes(lessonId);
      authService.updateCurrentUser({
        bookmarkedLessonIds: willBookmark
          ? [...bookmarked, lessonId]
          : bookmarked.filter((id) => id !== lessonId),
      });
      setLocalBookmarkedIds((prev) =>
        willBookmark
          ? prev.includes(lessonId)
            ? prev
            : [...prev, lessonId]
          : prev.filter((id) => id !== lessonId)
      );
      showLocalToast(willBookmark ? 'جلسه به بوکمارک‌ها اضافه شد.' : 'جلسه از بوکمارک‌ها حذف شد.');
    } catch (err: any) {
      showLocalToast(err?.message || 'خطا در ثبت بوکمارک');
    }
  };

  // BUG-58: Fullscreen API toggle on the player stage container.
  const toggleFullscreen = () => {
    const stage = stageRef.current;
    if (!stage) return;
    if (document.fullscreenElement) {
      if (document.exitFullscreen) document.exitFullscreen().catch(() => {});
    } else if (stage.requestFullscreen) {
      stage.requestFullscreen().catch(() => {});
    }
  };

  // BUG-62: manual ticket renewal from the expired/failed overlay.
  const handleRenewTicket = async () => {
    setIsTicketFailed(false);
    setIsRenewing(true);
    try {
      const access = courseService.canAccessLesson(currentUser, currentLesson);
      const t = await videoSecurityService.requestPlaybackTicket(
        currentLesson,
        currentUser,
        access.canAccess
      );
      setTicket(t);
    } catch {
      setIsTicketFailed(true);
    } finally {
      setIsRenewing(false);
    }
  };

  // BUG-58: shared clamped ±5s seek helper for the keyboard shortcuts + slider.
  // v1.1.0 Real-API: در پخش واقعی، همان seek روی خود <video> هم اعمال می‌شود.
  const seekBy = (delta: number) =>
    setCurrentTime((t) => {
      const next = Math.max(0, Math.min(totalDuration, t + delta));
      const video = videoRef.current;
      if (video) {
        try {
          video.currentTime = next;
        } catch {
          // برخی مرورگرها تا load کامل seek را نمی‌پذیرند — fatal نیست.
        }
      }
      return next;
    });

  // BUG-55: the raw fixed overlay participates in the ref-counted body
  // scroll lock so stacked overlays can't clobber each other's overflow.
  useEffect(() => {
    if (!isOpen) return;
    const releaseScrollLock = acquireScrollLock();
    return () => releaseScrollLock();
  }, [isOpen]);

  // BUG-58: global keyboard shortcuts (Esc close, Space/K, ←/→, M, F).
  useEffect(() => {
    if (!isOpen) return;
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        // Inside OS fullscreen, let the browser exit fullscreen natively first.
        if (document.fullscreenElement) return;
        onClose();
        return;
      }
      // BUG-58: ignore playback shortcuts while typing in form fields.
      const target = e.target as HTMLElement | null;
      if (
        target &&
        (target.tagName === 'INPUT' ||
          target.tagName === 'TEXTAREA' ||
          target.tagName === 'SELECT' ||
          target.isContentEditable)
      ) {
        return;
      }
      switch (e.key) {
        case ' ':
        case 'k':
        case 'K':
          e.preventDefault(); // Space would otherwise scroll the page
          setIsPlaying((p) => !p);
          break;
        case 'ArrowLeft':
          e.preventDefault();
          seekBy(-5);
          break;
        case 'ArrowRight':
          e.preventDefault();
          seekBy(5);
          break;
        case 'm':
        case 'M':
          setIsMuted((m) => !m);
          break;
        case 'f':
        case 'F':
          toggleFullscreen();
          break;
      }
    };
    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose, totalDuration]);

  // BUG-58: track fullscreen state so the toggle icon switches.
  useEffect(() => {
    if (!isOpen) return;
    const handleFullscreenChange = () => {
      setIsFullscreen(document.fullscreenElement === stageRef.current);
    };
    document.addEventListener('fullscreenchange', handleFullscreenChange);
    return () => document.removeEventListener('fullscreenchange', handleFullscreenChange);
  }, [isOpen]);

  // BUG-59: clear the local toast timer on unmount.
  useEffect(
    () => () => {
      if (toastTimerRef.current) clearTimeout(toastTimerRef.current);
    },
    []
  );

  // Fetch ticket and check security
  useEffect(() => {
    if (!isOpen) return;

    let mounted = true;
    // PLAYER FIX (BUG-11c): drop the previous lesson's ticket immediately so the
    // watermark/ticket UI can never show a stale ticket for a different lesson.
    setTicket(null);
    // BUG-62: reset any previous failure state when (re)requesting.
    setIsTicketFailed(false);
    // BUG-62: mounted flag + .catch — a failed request no longer throws an
    // unhandled rejection / leaves a permanent ticketless state; the
    // «اعتبار پخش منقضی شد» overlay takes over.
    // BUG-62: pass the canAccessLesson verdict as 3rd arg (defense-in-depth).
    const access = courseService.canAccessLesson(currentUser, currentLesson);
    videoSecurityService
      .requestPlaybackTicket(currentLesson, currentUser, access.canAccess)
      .then((t) => {
        if (mounted) setTicket(t);
      })
      .catch(() => {
        if (mounted) setIsTicketFailed(true);
      });

    const currentNotes = courseService.getNotes(currentLesson.id);
    setNotes(currentNotes);

    return () => {
      mounted = false;
    };
  }, [isOpen, currentLesson, currentUser]);

  // PLAYER FIX (BUG-11a): reset the simulated playhead whenever the lesson changes,
  // otherwise the scrubber/notes timestamps carry over from the previous lesson.
  useEffect(() => {
    setCurrentTime(0);
    // v1.1.0 Real-API: مدت/رزومهٔ ویدیوی درس قبلی نباید به درس بعدی نشت کند.
    setRealVideoDuration(0);
    resumeTimeRef.current = null;
    // BUG-60/61: also reset the ended panel, note freeze/error and the
    // once-per-lesson auto-complete guard.
    setIsEnded(false);
    setNoteTs(null);
    setNoteError(null);
    autoCompletedRef.current = null;
  }, [currentLesson.id]);

  // Simulated playback time advancement
  useEffect(() => {
    let timer: any;
    // v1.1.0 Real-API: در پخش واقعی، تایم‌لاین از خود <video> (timeupdate) می‌آید — تایمر شبیه‌ساز خاموش است.
    if (isPlaying && !isRealPlayback && simulatedNetworkState === 'good') {
      // PLAYER FIX (BUG-11b): the speed selector was dead UI. The interval now
      // advances the playhead according to the selected playback speed.
      const speed = parseFloat(playbackSpeed) || 1;
      const stepMs = Math.round(1000 / speed);
      timer = setInterval(() => {
        // BUG-60: the playhead STOPS at the end (ended panel takes over)
        // instead of looping silently back to 0.
        setCurrentTime((prev) => Math.min(totalDuration, prev + 1));
      }, stepMs);
    }
    return () => clearInterval(timer);
  }, [isPlaying, totalDuration, simulatedNetworkState, playbackSpeed, isRealPlayback]);

  // v1.1.0 Real-API: همگام‌سازی وضعیت پلیر با <video> واقعی — فقط وقتی videoUrl از سرور آمده
  // (در دمو videoRef همیشه null است و این اثر no-op می‌ماند؛ رفتار دمو صددرصد مثل قبل).
  useEffect(() => {
    const video = videoRef.current;
    if (!video) return;
    video.muted = isMuted;
    video.playbackRate = parseFloat(playbackSpeed) || 1;
    if (isPlaying) {
      video.play().catch(() => {
        // autoplay بدون تعامل کاربر ممکن است مسدود شود — کاربر با دکمه پخش ادامه می‌دهد.
      });
    } else {
      video.pause();
    }
  }, [isPlaying, isMuted, playbackSpeed, isRealPlayback]);

  // BUG-60: reaching the end of the lesson shows the «جلسه تمام شد» panel and
  // pauses playback instead of looping.
  useEffect(() => {
    if (!isOpen || totalDuration <= 0) return;
    if (currentTime < totalDuration) {
      // Seeking back from the end re-opens the player.
      if (isEnded) setIsEnded(false);
      return;
    }
    if (!isEnded) setIsEnded(true);
    setIsPlaying(false);
  }, [isOpen, currentTime, totalDuration, isEnded]);

  // BUG-60: auto-mark the lesson complete (BUG-59 path) once per ended lesson.
  useEffect(() => {
    if (!isOpen || !isEnded) return;
    if (autoCompletedRef.current === currentLesson.id) return;
    autoCompletedRef.current = currentLesson.id;
    persistComplete(currentLesson.id);
  }, [isOpen, isEnded, currentLesson.id]);

  // TICKET EXPIRY FIX (BUG-12): the ticket now carries an absolute expiry timestamp.
  // A 1-second heartbeat keeps the remaining TTL live and auto-expires the ticket,
  // mirroring the production signed-URL behaviour (پلیر باید بلیط منقضی را دوباره درخواست کند).
  const [nowTick, setNowTick] = useState(() => Date.now());
  useEffect(() => {
    if (!isOpen || !ticket) return;
    const tick = setInterval(() => setNowTick(Date.now()), 1000);
    return () => clearInterval(tick);
  }, [isOpen, ticket]);

  const ticketExpiresAtMs = ticket?.expiresAt ? new Date(ticket.expiresAt).getTime() : null;
  const ticketTtlRemaining = ticketExpiresAtMs ? Math.max(0, Math.ceil((ticketExpiresAtMs - nowTick) / 1000)) : 0;
  const isTicketExpired = !!ticket && ticketTtlRemaining <= 0;

  useEffect(() => {
    if (isTicketExpired && isOpen && currentLesson && currentUser) {
      // Auto-renew: production player re-authenticates on signed URL expiry.
      // BUG-62: mounted flag + .catch → the failed overlay replaces the silent
      // permanent-ticketless state.
      let mounted = true;
      // BUG-62: defense-in-depth — pass the access verdict as 3rd arg.
      const access = courseService.canAccessLesson(currentUser, currentLesson);
      videoSecurityService
        .requestPlaybackTicket(currentLesson, currentUser, access.canAccess)
        .then((t) => {
          if (mounted) setTicket(t);
        })
        .catch(() => {
          if (mounted) setIsTicketFailed(true);
        });
      return () => {
        mounted = false;
      };
    }
  }, [isTicketExpired, isOpen, currentLesson, currentUser]);

  if (!isOpen) return null;

  // Flatten lessons for navigation
  const allLessons: Lesson[] = course.sections.flatMap((s) => s.lessons);
  const currentIndex = allLessons.findIndex((l) => l.id === currentLesson.id);
  const prevLesson = currentIndex > 0 ? allLessons[currentIndex - 1] : null;
  const nextLesson = currentIndex < allLessons.length - 1 ? allLessons[currentIndex + 1] : null;

  // BUG-59: ✓ / filled-bookmark state — parent list first, then local fallback records.
  const isCurrentCompleted =
    (currentUser?.completedLessonIds ?? []).includes(currentLesson.id) ||
    localCompletedIds.includes(currentLesson.id);
  const isCurrentBookmarked =
    (currentUser?.bookmarkedLessonIds ?? []).includes(currentLesson.id) ||
    localBookmarkedIds.includes(currentLesson.id);

  const formatSeconds = (sec: number) => {
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  const handleAddNote = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newNoteText.trim()) return;
    try {
      // BUG-61: use the frozen timestamp so the note lands where the user focused.
      courseService.addNote(currentLesson.id, noteTs ?? currentTime, newNoteText.trim());
      // BUG-61: refresh the list from getNotes (per-user) instead of manual append.
      setNotes(courseService.getNotes(currentLesson.id));
      setNewNoteText('');
      setNoteError(null);
      setNoteTs(null);
      // BUG-61: restore the playing state after a successful add.
      setIsPlaying(true);
    } catch (err: any) {
      // BUG-61: addNote throws Persian validation errors — surface them inline.
      setNoteError(err?.message || 'خطا در ذخیره یادداشت');
    }
  };

  // BUG-61: delete a note via the service, then refresh the per-user list.
  const handleDeleteNote = (noteId: string) => {
    courseService.deleteNote(noteId);
    setNotes(courseService.getNotes(currentLesson.id));
  };

  // BUG-60: replay from the beginning and dismiss the ended panel.
  const handleReplay = () => {
    setCurrentTime(0);
    setIsEnded(false);
    setIsPlaying(true);
  };

  const watermarkString = ticket?.watermarkText || (currentUser ? currentUser.maskedPhone : '۰۹۱۲***۶۷۸۹');

  return (
    <div className="fixed inset-0 z-50 bg-black/95 text-white flex flex-col animate-in fade-in duration-200">
      {/* Top Navbar */}
      <div className="bg-stone-900 border-b border-stone-800 px-4 py-2.5 flex items-center justify-between text-xs shrink-0">
        <div className="flex items-center gap-2 sm:gap-3 overflow-hidden">
          <button
            onClick={onClose}
            className="flex items-center gap-1 bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded-lg transition"
          >
            <ChevronRight className="w-4 h-4" />
            <span>بازگشت به دوره</span>
          </button>
          {/* BUG-59: bookmark toggle in the player header (next to close). */}
          <button
            onClick={() => persistToggleBookmark(currentLesson.id)}
            className={`flex items-center justify-center min-w-[44px] min-h-[44px] rounded-lg transition ${
              isCurrentBookmarked
                ? 'bg-amber-800/90 text-amber-300 hover:bg-amber-700'
                : 'bg-stone-800 text-stone-400 hover:text-stone-100'
            }`}
            aria-label={isCurrentBookmarked ? 'حذف از بوکمارک‌ها' : 'افزودن به بوکمارک‌ها'}
            aria-pressed={isCurrentBookmarked}
            title={isCurrentBookmarked ? 'حذف از بوکمارک‌ها' : 'افزودن به بوکمارک‌ها'}
          >
            <Bookmark className={`w-4 h-4 ${isCurrentBookmarked ? 'fill-amber-300' : ''}`} />
          </button>
          <div className="truncate">
            <h2 className="font-bold text-stone-100 truncate">{currentLesson.title}</h2>
            <p className="text-[10px] text-amber-400 truncate">{course.title}</p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          {/* Mock integration badge */}
          {/* Ticket TTL countdown (BUG-12 companion fix) */}
          {ticket && (
            <span
              className="hidden sm:inline-flex items-center gap-1 bg-emerald-950/80 text-emerald-300 border border-emerald-800/60 px-2 py-0.5 rounded-md text-[10px] font-mono"
              dir="ltr"
              title="زمان اعتبار باقی‌مانده بلیط استریم"
            >
              <Shield className="w-3 h-3" />
              TTL {ticketTtlRemaining}s
            </span>
          )}
          <span className="hidden md:inline-flex items-center gap-1 bg-amber-950/80 text-amber-300 border border-amber-800/60 px-2 py-0.5 rounded-md text-[10px]">
            <Shield className="w-3 h-3" />
            پلیر آزمایشی با واترمارک پویا
          </span>
          {/* BUG-59: completion button now persists (parent handler or authService
              fallback) and shows a ✓ state for already-completed lessons. */}
          <button
            onClick={() => persistComplete(currentLesson.id)}
            aria-pressed={isCurrentCompleted}
            aria-label={isCurrentCompleted ? 'جلسه تکمیل شد' : 'علامت به عنوان تکمیل'}
            title={isCurrentCompleted ? 'جلسه تکمیل شد' : 'علامت به عنوان تکمیل'}
            className={`flex items-center gap-1 px-2.5 py-1.5 rounded-lg transition text-[11px] ${
              isCurrentCompleted
                ? 'bg-emerald-800/90 text-emerald-200 hover:bg-emerald-700'
                : 'bg-emerald-900/80 hover:bg-emerald-800 text-emerald-200'
            }`}
          >
            <CheckCircle className="w-3.5 h-3.5" />
            {/* BUG-59: `hidden xs:inline` → `hidden sm:inline` (no xs breakpoint in Tailwind). */}
            <span className="hidden sm:inline">
              {isCurrentCompleted ? 'جلسه تکمیل شد' : 'علامت به عنوان تکمیل'}
            </span>
          </button>
        </div>
      </div>

      {/* Main View Area: Video Canvas + Sidebar */}
      <div className="flex-1 flex flex-col lg:flex-row overflow-hidden">
        {/* Video Player Display Container (BUG-58: fullscreen stage) */}
        <div ref={stageRef} className="flex-1 flex flex-col justify-center bg-black relative select-none">
          {/* The Video Mock Screen — v1.1.0 Real-API: وقتی بلیط videoUrl واقعی دارد، خود <video> رندر می‌شود */}
          <div className="relative w-full h-[52vw] max-h-[65vh] lg:max-h-full lg:h-full bg-gradient-to-b from-stone-900 via-stone-950 to-black flex items-center justify-center overflow-hidden">
            {/* Background Handcraft Visual Motif Mockup */}
            {!ticket?.videoUrl && <div className="absolute inset-0 opacity-25 bg-[radial-gradient(#d97706_1px,transparent_1px)] [background-size:16px_16px]" />}

            {/* v1.1.0 Real-API: منبع واقعی پخش — تنها وقتی که زنجیرهٔ ticket→playback موفق بوده
                و سرور videoUrl داده است. تمام اورلی‌ها (واترمارک/بافرینگ/انقضا/تمام جلسه) روی آن می‌مانند. */}
            {ticket?.videoUrl && (
              <video
                ref={videoRef}
                key={ticket.videoUrl}
                src={ticket.videoUrl}
                className="absolute inset-0 z-10 h-full w-full bg-black object-contain"
                autoPlay
                playsInline
                preload="metadata"
                onLoadedMetadata={(e) => {
                  const video = e.currentTarget;
                  setRealVideoDuration(video.duration || 0);
                  const resume = resumeTimeRef.current;
                  if (resume !== null && Number.isFinite(resume) && resume > 1) {
                    try {
                      video.currentTime = resume; // ادامهٔ پخش پس از تمدید بلیط
                    } catch {
                      // ignore
                    }
                  }
                }}
                onTimeUpdate={(e) => {
                  const t = e.currentTarget.currentTime;
                  resumeTimeRef.current = t;
                  setCurrentTime(t);
                }}
                onEnded={(e) => {
                  const duration = e.currentTarget.duration;
                  // پنل «جلسه تمام شد» از اثر موجود (currentTime >= totalDuration) فعال می‌شود.
                  if (Number.isFinite(duration)) setCurrentTime(duration);
                  setIsPlaying(false);
                }}
                onError={() => {
                  // خطای منبع ویدیو در حالت Real — اورلی تمدید دسترسی (BUG-62) مسیر تازه‌سازی را می‌دهد.
                  if (ticket?.videoUrl) setIsTicketFailed(true);
                }}
              />
            )}

            {/* Simulated Live Frame Graphic (فقط دمو) */}
            {!ticket?.videoUrl && (
            <div className="text-center p-6 z-10 max-w-md">
              <div className="w-16 h-16 rounded-full bg-amber-900/60 border border-amber-500/30 flex items-center justify-center mx-auto mb-3 shadow-lg">
                <Play className="w-8 h-8 text-amber-200 fill-amber-300 ml-0.5" />
              </div>
              <p className="text-amber-100 font-bold text-sm sm:text-base">
                نمایشگر ویدیو با پروتکل HLS امن
              </p>
              <p className="text-[11px] text-stone-400 mt-1">
                استریم محافظت‌شده بر بستر CDN با مجوز موقت ۹۰۰ ثانیه‌ای
              </p>

              {/* Network State Simulator Toggles for Testing */}
              <div className="mt-4 flex items-center justify-center gap-1 text-[10px]">
                <span className="text-stone-500">تست وضعیت شبکه:</span>
                <button
                  onClick={() => setSimulatedNetworkState('good')}
                  className={`px-2 py-0.5 rounded ${
                    simulatedNetworkState === 'good' ? 'bg-emerald-800 text-white' : 'bg-stone-800 text-stone-400'
                  }`}
                >
                  عادی
                </button>
                <button
                  onClick={() => setSimulatedNetworkState('buffering')}
                  className={`px-2 py-0.5 rounded ${
                    simulatedNetworkState === 'buffering' ? 'bg-amber-800 text-white' : 'bg-stone-800 text-stone-400'
                  }`}
                >
                  بافرینگ
                </button>
                <button
                  onClick={() => setSimulatedNetworkState('error')}
                  className={`px-2 py-0.5 rounded ${
                    simulatedNetworkState === 'error' ? 'bg-red-800 text-white' : 'bg-stone-800 text-stone-400'
                  }`}
                >
                  خطای اتصال
                </button>
              </div>
            </div>
            )}

            {/* DYNAMIC MOVING WATERMARK OVERLAY */}
            <div className="absolute inset-0 pointer-events-none z-20 overflow-hidden">
              <div className="animate-watermark absolute top-1/4 right-1/4 opacity-30 select-none">
                <div className="bg-black/40 backdrop-blur-xs border border-white/20 text-white px-3 py-1 rounded-md text-xs font-mono font-bold tracking-wider shadow">
                  {watermarkString}
                </div>
              </div>
              <div className="absolute bottom-6 left-6 opacity-25 select-none text-[10px] font-mono text-stone-300">
                mrdastbaaf.com // ID: {ticket?.watermarkUserId || 'std'}
              </div>
            </div>

            {/* BUFFERING OVERLAY */}
            {simulatedNetworkState === 'buffering' && (
              <div className="absolute inset-0 bg-black/60 flex items-center justify-center z-30">
                <div className="text-center">
                  <div className="w-10 h-10 border-3 border-amber-600 border-t-transparent rounded-full animate-spin mx-auto mb-2" />
                  <p className="text-xs text-amber-200">در حال دریافت داده‌های ویدیو...</p>
                </div>
              </div>
            )}

            {/* ERROR OVERLAY */}
            {simulatedNetworkState === 'error' && (
              <div className="absolute inset-0 bg-black/85 flex items-center justify-center z-30 p-4">
                <div className="text-center max-w-xs">
                  <AlertCircle className="w-10 h-10 text-red-500 mx-auto mb-2" />
                  <p className="text-sm font-bold text-stone-100">خطا در بارگذاری قطعات استریم</p>
                  <p className="text-xs text-stone-400 mt-1">
                    اتصال اینترنت را بررسی کنید یا کیفیت را به 480p کاهش دهید.
                  </p>
                  <button
                    onClick={() => setSimulatedNetworkState('good')}
                    className="mt-3 bg-amber-800 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg text-xs"
                  >
                    تلاش مجدد
                  </button>
                </div>
              </div>
            )}

            {/* BUG-60: end-of-lesson panel — no more silent loop back to 0 */}
            {isEnded && !isTicketFailed && (
              <div className="absolute inset-0 bg-black/85 flex items-center justify-center z-40 p-4">
                <div className="text-center max-w-xs w-full space-y-3">
                  <CheckCircle className="w-10 h-10 text-emerald-400 mx-auto" />
                  <p className="text-base font-bold text-white">جلسه تمام شد</p>
                  <p className="text-xs text-stone-400">
                    می‌توانید دوباره تماشا کنید یا به سراغ جلسه بعدی بروید.
                  </p>
                  <div className="flex items-center justify-center gap-2">
                    <button
                      onClick={handleReplay}
                      className="min-h-[44px] px-4 bg-stone-800 hover:bg-stone-700 text-stone-100 rounded-xl text-xs transition"
                    >
                      تماشای مجدد
                    </button>
                    {nextLesson && (
                      <button
                        onClick={() => onSelectLesson(nextLesson)}
                        className="min-h-[44px] px-4 bg-amber-700 hover:bg-amber-600 text-white rounded-xl text-xs transition"
                      >
                        جلسه بعدی
                      </button>
                    )}
                  </div>
                </div>
              </div>
            )}

            {/* BUG-62: expired/failed ticket overlay with manual renewal */}
            {isTicketFailed && (
              <div className="absolute inset-0 bg-black/90 flex items-center justify-center z-50 p-4">
                <div className="text-center max-w-xs w-full space-y-3">
                  <AlertCircle className="w-10 h-10 text-amber-500 mx-auto" />
                  <p className="text-sm font-bold text-white">اعتبار پخش منقضی شد</p>
                  <p className="text-xs text-stone-400 leading-relaxed">
                    بلیط استریم این درس منقضی شده یا تمدید خودکار آن ناموفق بود. برای ادامه تماشا، دسترسی را دوباره دریافت کنید.
                  </p>
                  <button
                    onClick={handleRenewTicket}
                    disabled={isRenewing}
                    className="w-full min-h-[44px] bg-amber-700 hover:bg-amber-600 disabled:opacity-60 text-white rounded-xl text-xs font-medium transition"
                  >
                    {isRenewing ? 'در حال تمدید...' : 'تمدید دسترسی'}
                  </button>
                </div>
              </div>
            )}
          </div>

          {/* Video Control Bar */}
          <div className="bg-stone-900/95 border-t border-stone-800 px-3 sm:px-4 py-2.5 z-30">
            {/* Scrubber progress slider: LTR layout ensures time advances naturally from left to right */}
            <div className="flex items-center gap-2 sm:gap-3 text-[11px] text-stone-400 mb-2" dir="ltr">
              <span className="font-mono text-stone-300 shrink-0">{formatSeconds(currentTime)}</span>
              <div
                // BUG-58: slider semantics + keyboard seek (±5s) on the scrubber.
                role="slider"
                tabIndex={0}
                aria-label="نوار پیشرفت پخش"
                aria-valuemin={0}
                aria-valuemax={totalDuration}
                aria-valuenow={Math.round(currentTime)}
                aria-valuetext={formatSeconds(currentTime)}
                onKeyDown={(e) => {
                  if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
                    // stopPropagation avoids double-handling with the global shortcuts.
                    e.preventDefault();
                    e.stopPropagation();
                    seekBy(e.key === 'ArrowLeft' ? -5 : 5);
                  }
                }}
                className="flex-1 h-1.5 bg-stone-700 rounded-full relative cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500"
                onClick={(e) => {
                  const rect = e.currentTarget.getBoundingClientRect();
                  const clickX = e.clientX - rect.left;
                  const ratio = Math.max(0, Math.min(1, clickX / rect.width));
                  const next = Math.floor(ratio * totalDuration);
                  setCurrentTime(next);
                  // v1.1.0 Real-API: seek واقعی روی خود <video>.
                  const video = videoRef.current;
                  if (video) {
                    try {
                      video.currentTime = next;
                    } catch {
                      // ignore
                    }
                  }
                }}
              >
                <div
                  className="h-full bg-amber-600 rounded-full relative"
                  style={{ width: `${totalDuration > 0 ? (currentTime / totalDuration) * 100 : 0}%` }}
                >
                  <div className="w-3 h-3 bg-amber-300 rounded-full absolute -right-1 -top-0.5 shadow-sm" />
                </div>
              </div>
              <span className="font-mono text-stone-400 shrink-0">{formatSeconds(totalDuration)}</span>
            </div>

            {/* Bottom Controls Row: responsive flex-wrap for narrow mobile screens */}
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs">
              <div className="flex items-center gap-2 sm:gap-3 flex-wrap">
                <button
                  onClick={() => setIsPlaying(!isPlaying)}
                  className="p-1.5 rounded-lg bg-amber-800 hover:bg-amber-700 text-white transition"
                  title={isPlaying ? 'توقف' : 'پخش'}
                  aria-label={isPlaying ? 'توقف' : 'پخش'}
                >
                  {isPlaying ? <Pause className="w-4 h-4" /> : <Play className="w-4 h-4 fill-white" />}
                </button>

                <button
                  onClick={() => setCurrentTime((t) => Math.max(0, t - 10))}
                  className="p-1 rounded-lg text-stone-400 hover:text-stone-100 transition"
                  title="۱۰ ثانیه به عقب"
                  aria-label="۱۰ ثانیه به عقب"
                >
                  <RotateCcw className="w-4 h-4" />
                </button>

                <button
                  onClick={() => setIsMuted(!isMuted)}
                  className="p-1 rounded-lg text-stone-400 hover:text-stone-100 transition"
                  title={isMuted ? 'صدادار' : 'بی‌صدا'}
                  aria-label={isMuted ? 'صدادار' : 'بی‌صدا'}
                >
                  {isMuted ? <VolumeX className="w-4 h-4 text-red-400" /> : <Volume2 className="w-4 h-4" />}
                </button>

                {/* Speed selector */}
                <div className="flex items-center gap-1 bg-stone-800 px-2 py-0.5 rounded-md text-[11px]">
                  <span className="text-stone-400">سرعت:</span>
                  {(['0.75', '1', '1.25', '1.5'] as const).map((s) => (
                    <button
                      key={s}
                      onClick={() => setPlaybackSpeed(s)}
                      className={`px-1 rounded ${
                        playbackSpeed === s ? 'text-amber-400 font-bold' : 'text-stone-400 hover:text-stone-200'
                      }`}
                    >
                      {s}x
                    </button>
                  ))}
                </div>
              </div>

              {/* Quality & Next/Prev */}
              <div className="flex items-center gap-2">
                <select
                  value={selectedQuality}
                  onChange={(e) => setSelectedQuality(e.target.value)}
                  // BUG-58: accessible name for the quality selector.
                  aria-label="انتخاب کیفیت پخش"
                  className="bg-stone-800 text-stone-300 text-[11px] rounded px-1.5 py-1 border border-stone-700 focus:outline-none"
                >
                  <option value="1080p">1080p FHD</option>
                  <option value="720p">720p HD</option>
                  <option value="480p">480p SD</option>
                </select>

                <div className="flex items-center gap-1">
                  <button
                    disabled={!prevLesson}
                    onClick={() => prevLesson && onSelectLesson(prevLesson)}
                    className={`p-1 rounded bg-stone-800 ${
                      prevLesson ? 'text-stone-300 hover:text-white' : 'text-stone-600 cursor-not-allowed'
                    }`}
                    title="درس قبلی"
                    aria-label="درس قبلی"
                  >
                    <ChevronRight className="w-4 h-4" />
                  </button>
                  <button
                    disabled={!nextLesson}
                    onClick={() => nextLesson && onSelectLesson(nextLesson)}
                    className={`p-1 rounded bg-stone-800 ${
                      nextLesson ? 'text-stone-300 hover:text-white' : 'text-stone-600 cursor-not-allowed'
                    }`}
                    title="درس بعدی"
                    aria-label="درس بعدی"
                  >
                    <ChevronLeft className="w-4 h-4" />
                  </button>
                  {/* BUG-58: fullscreen toggle via the Fullscreen API. */}
                  <button
                    onClick={toggleFullscreen}
                    className="p-1 rounded bg-stone-800 text-stone-300 hover:text-white transition"
                    title={isFullscreen ? 'خروج از حالت تمام‌صفحه' : 'حالت تمام‌صفحه'}
                    aria-label={isFullscreen ? 'خروج از حالت تمام‌صفحه' : 'حالت تمام‌صفحه'}
                  >
                    {isFullscreen ? <Minimize className="w-4 h-4" /> : <Maximize className="w-4 h-4" />}
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Sidebar Drawer: Syllabus, Notes, Security Info */}
        <div className="w-full lg:w-96 bg-stone-900 border-t lg:border-t-0 lg:border-r border-stone-800 flex flex-col max-h-72 lg:max-h-full">
          {/* Drawer Tab Headers */}
          <div className="flex border-b border-stone-800 bg-stone-950 text-xs shrink-0">
            <button
              onClick={() => setActiveTab('syllabus')}
              className={`flex-1 py-3 text-center font-medium border-b-2 transition flex items-center justify-center gap-1.5 ${
                activeTab === 'syllabus'
                  ? 'border-amber-600 text-amber-400 bg-stone-900'
                  : 'border-transparent text-stone-400 hover:text-stone-200'
              }`}
            >
              <ListVideo className="w-3.5 h-3.5" />
              سرفصل‌ها ({allLessons.length})
            </button>
            <button
              onClick={() => setActiveTab('notes')}
              className={`flex-1 py-3 text-center font-medium border-b-2 transition flex items-center justify-center gap-1.5 ${
                activeTab === 'notes'
                  ? 'border-amber-600 text-amber-400 bg-stone-900'
                  : 'border-transparent text-stone-400 hover:text-stone-200'
              }`}
            >
              <FileText className="w-3.5 h-3.5" />
              یادداشت‌ها ({notes.length})
            </button>
            <button
              onClick={() => setActiveTab('security')}
              className={`flex-1 py-3 text-center font-medium border-b-2 transition flex items-center justify-center gap-1.5 ${
                activeTab === 'security'
                  ? 'border-amber-600 text-amber-400 bg-stone-900'
                  : 'border-transparent text-stone-400 hover:text-stone-200'
              }`}
            >
              <Shield className="w-3.5 h-3.5" />
              امنیت ویدیو
            </button>
          </div>

          {/* Drawer Content */}
          <div className="flex-1 overflow-y-auto p-3 text-xs space-y-3">
            {/* 1. SYLLABUS LIST */}
            {activeTab === 'syllabus' && (
              <div className="space-y-4">
                {course.sections.map((sec) => (
                  <div key={sec.id}>
                    <p className="text-[11px] font-bold text-amber-300/90 mb-2">
                      {sec.title}
                    </p>
                    <div className="space-y-1">
                      {sec.lessons.map((lsn) => {
                        const isCurrent = lsn.id === currentLesson.id;
                        const access = courseService.canAccessLesson(currentUser, lsn);
                        const isDone = (currentUser?.completedLessonIds ?? []).includes(lsn.id) ||
                          localCompletedIds.includes(lsn.id);
                        return (
                          <button
                            key={lsn.id}
                            disabled={!access.canAccess}
                            onClick={() => access.canAccess && onSelectLesson(lsn)}
                            className={`w-full text-right p-2.5 rounded-xl transition flex items-center justify-between gap-2 ${
                              isCurrent
                                ? 'bg-amber-900/60 border border-amber-600/50 text-white font-semibold'
                                : access.canAccess
                                ? 'hover:bg-stone-800 text-stone-300'
                                : 'opacity-50 cursor-not-allowed bg-stone-950/40 text-stone-500'
                            }`}
                          >
                            <div className="flex items-center gap-2 truncate">
                              <span className="w-5 h-5 rounded-full bg-stone-800 text-stone-400 text-[10px] flex items-center justify-center shrink-0">
                                {isDone ? '✓' : lsn.order}
                              </span>
                              <span className="truncate">{lsn.title}</span>
                            </div>
                            <div className="flex items-center gap-1 text-[10px] text-stone-400 shrink-0">
                              <Clock className="w-3 h-3" />
                              <span>{lsn.durationMinutes} دقیقه</span>
                            </div>
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>
            )}

            {/* 2. NOTES LIST & ADD NOTE */}
            {activeTab === 'notes' && (
              <div className="space-y-3">
                <form onSubmit={handleAddNote} className="space-y-2">
                  <div className="flex items-center justify-between text-[11px] text-stone-400">
                    <span>ثبت یادداشت روی ثانیه:</span>
                    {/* BUG-61: label shows the frozen timestamp captured on focus. */}
                    <span className="font-mono text-amber-400">
                      {formatSeconds(noteTs ?? currentTime)}
                    </span>
                  </div>
                  <textarea
                    rows={2}
                    value={newNoteText}
                    maxLength={1000}
                    onFocus={() => {
                      // BUG-61: freeze the timestamp and pause playback while typing.
                      if (noteTs === null) setNoteTs(currentTime);
                      setIsPlaying(false);
                    }}
                    onChange={(e) => setNewNoteText(e.target.value)}
                    placeholder="نکته مهم این بخش از آموزش را بنویسید..."
                    className="w-full bg-stone-800 border border-stone-700 rounded-xl p-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-amber-600"
                  />
                  {/* BUG-61: inline Persian error for addNote validation failures */}
                  {noteError && (
                    <p className="text-[11px] text-red-300 bg-red-950/50 border border-red-900/60 rounded-lg px-2.5 py-1.5">
                      {noteError}
                    </p>
                  )}
                  <button
                    type="submit"
                    className="w-full min-h-[44px] bg-amber-800 hover:bg-amber-700 text-white font-medium rounded-lg text-xs transition"
                  >
                    افزودن یادداشت در این زمان
                  </button>
                </form>

                <div className="space-y-2 pt-2 border-t border-stone-800">
                  {notes.length === 0 ? (
                    <p className="text-center text-stone-500 py-4 text-xs">
                      هنوز یادداشتی برای این درس ثبت نکرده‌اید.
                    </p>
                  ) : (
                    notes.map((n) => (
                      <div
                        key={n.id}
                        className="p-2.5 rounded-xl bg-stone-800/80 border border-stone-700/60 space-y-1"
                      >
                        <div className="flex items-center justify-between text-[10px] text-amber-400">
                          <button
                            onClick={() => setCurrentTime(n.timestampSeconds)}
                            className="font-mono underline hover:text-amber-200"
                          >
                            زمان: {formatSeconds(n.timestampSeconds)}
                          </button>
                          {/* BUG-61: per-note delete wired to courseService.deleteNote */}
                          <button
                            onClick={() => handleDeleteNote(n.id)}
                            className="flex items-center justify-center min-w-[44px] min-h-[44px] -my-3 -mx-1 rounded-lg text-stone-500 hover:text-red-400 transition"
                            aria-label={`حذف یادداشت در ${formatSeconds(n.timestampSeconds)}`}
                            title="حذف یادداشت"
                          >
                            <Trash2 className="w-3.5 h-3.5" />
                          </button>
                        </div>
                        <p className="text-stone-200 text-xs leading-relaxed">{n.content}</p>
                      </div>
                    ))
                  )}
                </div>
              </div>
            )}

            {/* 3. SECURITY ARCHITECTURE DETAILS */}
            {activeTab === 'security' && (
              <div className="space-y-3 text-xs leading-relaxed text-stone-300">
                <div className="p-3 rounded-xl bg-stone-800/60 border border-stone-700/80 space-y-2">
                  <h4 className="font-bold text-amber-300 flex items-center gap-1.5">
                    <Shield className="w-4 h-4 text-amber-400" />
                    معماری امنیت ویدیو در مستر دستباف
                  </h4>
                  <p className="text-[11px] text-stone-400">
                    این پلیر نسخه پروتوتایپ است و تعمداً از هیچ لینک مستقیم فایل MP4 استفاده نمی‌کند:
                  </p>
                  <ul className="list-disc list-inside space-y-1 text-[11px] text-stone-300">
                    <li>عدم ارائه لینک خام ویدیو در کدهای کلاینت</li>
                    <li>واترمارک پویا با شماره تلفن ماسک‌شده و شناسه کاربر</li>
                    <li>طراحی شده برای پروتکل استریم چندکیفیته HLS در نسخه نهایی</li>
                    <li>نیاز به توکن کوتاه‌مدت (Tokenized Signature) از سمت سرور وردپرس</li>
                  </ul>
                </div>

                <div className="p-2.5 rounded-xl bg-amber-950/40 border border-amber-900/60 text-[11px] text-amber-200/90">
                  <strong>هشدار فنی مهم:</strong>
                  <p className="mt-1 text-stone-300">
                    هیچ پلیر تحت وب HTML5 به تنهایی قادر به جلوگیری کامل از دانلود یا ضبط صفحه نیست. حفاظت در مقیاس عملیاتی نیازمند سرویس‌های اختصاصی مانند ابرآروان یا سیستم‌های DRM نظیر Widevine / FairPlay است.
                  </p>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>

      {/* BUG-59: in-player toast for the fallback persistence feedback */}
      {localToast && (
        <div
          role="status"
          className="fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] bg-stone-900/95 border border-amber-700/60 text-amber-100 text-xs px-4 py-2.5 rounded-xl shadow-lg animate-in fade-in slide-in-from-bottom-2"
        >
          {localToast}
        </div>
      )}
    </div>
  );
};
