/**
 * @file components/player/LessonPlayerModal.tsx
 * @description Branded video lesson player modal with dynamic forensic watermark, syllabus, and notes
 */

import React, { useState, useEffect } from 'react';
import {
  Play,
  Pause,
  RotateCcw,
  Volume2,
  VolumeX,
  Maximize,
  Shield,
  Bookmark,
  CheckCircle,
  ChevronRight,
  ChevronLeft,
  ListVideo,
  FileText,
  Clock,
  Sparkles,
  AlertCircle,
  WifiOff,
  Settings,
} from 'lucide-react';
import { Course, Lesson, LessonNote, UserProfile, VideoStreamingTicket } from '../../types';
import { videoSecurityService } from '../../services/videoSecurityService';
import { courseService } from '../../services/courseService';

interface LessonPlayerModalProps {
  isOpen: boolean;
  onClose: () => void;
  course: Course;
  currentLesson: Lesson;
  onSelectLesson: (lesson: Lesson) => void;
  currentUser: UserProfile | null;
  onLessonCompleted?: (lessonId: string) => void;
}

export const LessonPlayerModal: React.FC<LessonPlayerModalProps> = ({
  isOpen,
  onClose,
  course,
  currentLesson,
  onSelectLesson,
  currentUser,
  onLessonCompleted,
}) => {
  const [isPlaying, setIsPlaying] = useState(true);
  const [currentTime, setCurrentTime] = useState(145); // simulated 2:25 into lesson
  const totalDuration = currentLesson.durationMinutes * 60;
  const [playbackSpeed, setPlaybackSpeed] = useState<'0.75' | '1' | '1.25' | '1.5'>('1');
  const [selectedQuality, setSelectedQuality] = useState('720p');
  const [isMuted, setIsMuted] = useState(false);
  const [ticket, setTicket] = useState<VideoStreamingTicket | null>(null);
  const [activeTab, setActiveTab] = useState<'syllabus' | 'notes' | 'security'>('syllabus');
  const [newNoteText, setNewNoteText] = useState('');
  const [notes, setNotes] = useState<LessonNote[]>([]);
  const [simulatedNetworkState, setSimulatedNetworkState] = useState<'good' | 'buffering' | 'error'>('good');

  // Fetch ticket and check security
  useEffect(() => {
    if (!isOpen) return;

    let mounted = true;
    // PLAYER FIX (BUG-11c): drop the previous lesson's ticket immediately so the
    // watermark/ticket UI can never show a stale ticket for a different lesson.
    setTicket(null);
    videoSecurityService.requestPlaybackTicket(currentLesson, currentUser).then((t) => {
      if (mounted) setTicket(t);
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
  }, [currentLesson.id]);

  // Simulated playback time advancement
  useEffect(() => {
    let timer: any;
    if (isPlaying && simulatedNetworkState === 'good') {
      // PLAYER FIX (BUG-11b): the speed selector was dead UI. The interval now
      // advances the playhead according to the selected playback speed.
      const speed = parseFloat(playbackSpeed) || 1;
      const stepMs = Math.round(1000 / speed);
      timer = setInterval(() => {
        setCurrentTime((prev) => (prev >= totalDuration ? 0 : prev + 1));
      }, stepMs);
    }
    return () => clearInterval(timer);
  }, [isPlaying, totalDuration, simulatedNetworkState, playbackSpeed]);

  if (!isOpen) return null;

  // Flatten lessons for navigation
  const allLessons: Lesson[] = course.sections.flatMap((s) => s.lessons);
  const currentIndex = allLessons.findIndex((l) => l.id === currentLesson.id);
  const prevLesson = currentIndex > 0 ? allLessons[currentIndex - 1] : null;
  const nextLesson = currentIndex < allLessons.length - 1 ? allLessons[currentIndex + 1] : null;

  const formatSeconds = (sec: number) => {
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
  };

  const handleAddNote = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newNoteText.trim()) return;
    const added = courseService.addNote(currentLesson.id, currentTime, newNoteText.trim());
    setNotes((prev) => [...prev, added]);
    setNewNoteText('');
  };

  const watermarkString = ticket?.watermarkText || (currentUser ? currentUser.maskedPhone : '۰۹۱۲***۶۷۸۹');

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
      videoSecurityService.requestPlaybackTicket(currentLesson, currentUser).then((t) => setTicket(t));
    }
  }, [isTicketExpired, isOpen, currentLesson, currentUser]);

  return (
    <div className="fixed inset-0 z-50 bg-black/95 text-white flex flex-col animate-in fade-in duration-200">
      {/* Top Navbar */}
      <div className="bg-stone-900 border-b border-stone-800 px-4 py-2.5 flex items-center justify-between text-xs shrink-0">
        <div className="flex items-center gap-3 overflow-hidden">
          <button
            onClick={onClose}
            className="flex items-center gap-1 bg-stone-800 hover:bg-stone-700 text-stone-200 px-2.5 py-1.5 rounded-lg transition"
          >
            <ChevronRight className="w-4 h-4" />
            <span>بازگشت به دوره</span>
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
          <button
            onClick={() => {
              if (onLessonCompleted) onLessonCompleted(currentLesson.id);
            }}
            className="flex items-center gap-1 bg-emerald-900/80 hover:bg-emerald-800 text-emerald-200 px-2.5 py-1.5 rounded-lg transition text-[11px]"
          >
            <CheckCircle className="w-3.5 h-3.5" />
            <span className="hidden xs:inline">علامت به عنوان تکمیل</span>
          </button>
        </div>
      </div>

      {/* Main View Area: Video Canvas + Sidebar */}
      <div className="flex-1 flex flex-col lg:flex-row overflow-hidden">
        {/* Video Player Display Container */}
        <div className="flex-1 flex flex-col justify-center bg-black relative select-none">
          {/* The Video Mock Screen */}
          <div className="relative w-full h-[52vw] max-h-[65vh] lg:max-h-full lg:h-full bg-gradient-to-b from-stone-900 via-stone-950 to-black flex items-center justify-center overflow-hidden">
            {/* Background Handcraft Visual Motif Mockup */}
            <div className="absolute inset-0 opacity-25 bg-[radial-gradient(#d97706_1px,transparent_1px)] [background-size:16px_16px]" />

            {/* Simulated Live Frame Graphic */}
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
          </div>

          {/* Video Control Bar */}
          <div className="bg-stone-900/95 border-t border-stone-800 px-3 sm:px-4 py-2.5 z-30">
            {/* Scrubber progress slider: LTR layout ensures time advances naturally from left to right */}
            <div className="flex items-center gap-2 sm:gap-3 text-[11px] text-stone-400 mb-2" dir="ltr">
              <span className="font-mono text-stone-300 shrink-0">{formatSeconds(currentTime)}</span>
              <div
                className="flex-1 h-1.5 bg-stone-700 rounded-full relative cursor-pointer"
                onClick={(e) => {
                  const rect = e.currentTarget.getBoundingClientRect();
                  const clickX = e.clientX - rect.left;
                  const ratio = Math.max(0, Math.min(1, clickX / rect.width));
                  setCurrentTime(Math.floor(ratio * totalDuration));
                }}
              >
                <div
                  className="h-full bg-amber-600 rounded-full relative"
                  style={{ width: `${(currentTime / totalDuration) * 100}%` }}
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
                >
                  {isPlaying ? <Pause className="w-4 h-4" /> : <Play className="w-4 h-4 fill-white" />}
                </button>

                <button
                  onClick={() => setCurrentTime((t) => Math.max(0, t - 10))}
                  className="p-1 rounded-lg text-stone-400 hover:text-stone-100 transition"
                  title="۱۰ ثانیه به عقب"
                >
                  <RotateCcw className="w-4 h-4" />
                </button>

                <button
                  onClick={() => setIsMuted(!isMuted)}
                  className="p-1 rounded-lg text-stone-400 hover:text-stone-100 transition"
                  title={isMuted ? 'صدادار' : 'بی‌صدا'}
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
                  >
                    <ChevronLeft className="w-4 h-4" />
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
                {course.sections.map((sec, secIdx) => (
                  <div key={sec.id}>
                    <p className="text-[11px] font-bold text-amber-300/90 mb-2">
                      {sec.title}
                    </p>
                    <div className="space-y-1">
                      {sec.lessons.map((lsn) => {
                        const isCurrent = lsn.id === currentLesson.id;
                        const access = courseService.canAccessLesson(currentUser, lsn);
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
                                {lsn.order}
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
                    <span className="font-mono text-amber-400">{formatSeconds(currentTime)}</span>
                  </div>
                  <textarea
                    rows={2}
                    value={newNoteText}
                    onChange={(e) => setNewNoteText(e.target.value)}
                    placeholder="نکته مهم این بخش از آموزش را بنویسید..."
                    className="w-full bg-stone-800 border border-stone-700 rounded-xl p-2.5 text-xs text-white placeholder-stone-500 focus:outline-none focus:border-amber-600"
                  />
                  <button
                    type="submit"
                    className="w-full bg-amber-800 hover:bg-amber-700 text-white font-medium py-1.5 rounded-lg text-xs transition"
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
                          <span className="text-stone-500">ذخیره شد</span>
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
    </div>
  );
};
