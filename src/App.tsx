/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

/**
 * @file App.tsx
 * @description Main application shell for Mr. Dastbaaf Handicraft Learning Platform PWA
 */

import React, { useState, useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { Course, CourseCategory, Lesson, MembershipPlan, PaymentGatewayType, PaymentOrder, UserProfile } from './types';
import { authService } from './services/authService';
import { courseService } from './services/courseService';
import { paymentService } from './services/paymentService';
import { isRealApiMode } from './services/apiClient';
import { detectZibalCallback, clearZibalCallbackParams } from './services/zibalCallback';
import { mutateOrder, orderStore } from './services/orderStore';

// Common Components
import { Header } from './components/common/Header';
import { BottomNav } from './components/common/BottomNav';
import { OfflineIndicator } from './components/common/OfflineIndicator';
import { PWAUpdatePrompt } from './components/common/PWAUpdatePrompt';

// Modals
import { PhoneLoginModal } from './components/auth/PhoneLoginModal';
import { LessonPlayerModal } from './components/player/LessonPlayerModal';
import { CheckoutModal } from './components/payment/CheckoutModal';
import { ZibalSimulatorModal } from './components/payment/ZibalSimulatorModal';
import { ManualTransferModal } from './components/payment/ManualTransferModal';

// Views
import { HomeView } from './views/HomeView';
import { CatalogueView } from './views/CatalogueView';
import { CourseDetailView } from './views/CourseDetailView';
import { MembershipView } from './views/MembershipView';
import { UserDashboardView } from './views/UserDashboardView';
import { AdminDashboardView } from './views/AdminDashboardView';
import { EditorialPageView } from './views/EditorialPageView';

// BUG-52: typed navigation params instead of `any`
interface AppViewParams {
  category?: CourseCategory | 'all';
  courseId?: string;
  slug?: string;
}

// DEV-2: lightweight hash router (no new deps) — view ⇄ URL mapping.
// Route map: #/ → home · #/catalogue[?cat={category}] → catalogue ·
//   #/course/{courseId} → course_detail · #/membership → membership ·
//   #/dashboard → dashboard · #/admin → admin ·
//   #/page/{slug} → editorial page · #/editorial → editorial (about-us).
// Unknown/invalid routes fall back to home. Modals (player/checkout/gateway)
// are intentionally NOT routed — future work.
const serializeRoute = (view: string, params: AppViewParams): string => {
  switch (view) {
    case 'catalogue': {
      const cat =
        params.category && params.category !== 'all'
          ? `?cat=${encodeURIComponent(params.category)}`
          : '';
      return `#/catalogue${cat}`;
    }
    case 'course_detail':
      return params.courseId ? `#/course/${encodeURIComponent(params.courseId)}` : '#/catalogue';
    case 'membership':
      return '#/membership';
    case 'dashboard':
      return '#/dashboard';
    case 'admin':
      return '#/admin';
    case 'editorial':
      return params.slug ? `#/page/${encodeURIComponent(params.slug)}` : '#/editorial';
    default:
      return '#/';
  }
};

const parseRoute = (hash: string): { view: string; params: AppViewParams } => {
  const [path, query = ''] = hash.replace(/^#/, '').split('?');
  const segments = path.split('/').filter(Boolean);

  switch (segments[0]) {
    case 'catalogue': {
      const cat = new URLSearchParams(query).get('cat');
      return { view: 'catalogue', params: cat ? { category: cat as CourseCategory } : {} };
    }
    case 'course':
      return segments[1]
        ? { view: 'course_detail', params: { courseId: decodeURIComponent(segments[1]) } }
        : { view: 'catalogue', params: {} };
    case 'membership':
      return { view: 'membership', params: {} };
    case 'dashboard':
      return { view: 'dashboard', params: {} };
    case 'admin':
      return { view: 'admin', params: {} };
    case 'page':
      return segments[1]
        ? { view: 'editorial', params: { slug: decodeURIComponent(segments[1]) } }
        : { view: 'editorial', params: {} };
    case 'editorial':
      return { view: 'editorial', params: {} };
    default:
      // DEV-2: empty/unknown hash → home.
      return { view: 'home', params: {} };
  }
};

// BUG-44: toast variants with distinct, palette-safe colors
// (success=emerald, error=red, info=amber — no blue/indigo in this project).
type ToastVariant = 'success' | 'error' | 'info';

const TOAST_STYLES: Record<ToastVariant, string> = {
  success: 'bg-emerald-800 text-emerald-50 border-emerald-700',
  error: 'bg-red-800 text-red-50 border-red-700',
  info: 'bg-stone-900 text-amber-100 border-stone-800',
};

interface ToastState {
  id: number;
  message: string;
  variant: ToastVariant;
}

export default function App() {
  const [currentUser, setCurrentUser] = useState<UserProfile | null>(authService.getCurrentUser());
  const [activeView, setActiveView] = useState<string>('home');
  const [viewParams, setViewParams] = useState<AppViewParams>({});
  // DEV-2: last applied route key — dedupes the popstate/hashchange echo of a
  // pushState-driven navigation (and redundant renders of the same route).
  const routeKeyRef = useRef<string>('');

  // Modals state
  const [isLoginModalOpen, setIsLoginModalOpen] = useState(false);
  const [isCheckoutModalOpen, setIsCheckoutModalOpen] = useState(false);
  const [selectedPlanForCheckout, setSelectedPlanForCheckout] = useState<MembershipPlan | null>(null);

  const [isZibalModalOpen, setIsZibalModalOpen] = useState(false);
  const [currentZibalOrder, setCurrentZibalOrder] = useState<PaymentOrder | null>(null);

  const [isManualTransferModalOpen, setIsManualTransferModalOpen] = useState(false);

  // Lesson Player State
  const [isPlayerOpen, setIsPlayerOpen] = useState(false);
  const [activePlayerCourse, setActivePlayerCourse] = useState<Course | null>(null);
  const [activePlayerLesson, setActivePlayerLesson] = useState<Lesson | null>(null);

  // Toast feedback message
  const [toast, setToast] = useState<ToastState | null>(null);
  const toastTimerRef = useRef<number | null>(null);

  // BUG-44: the old setTimeout was never cleared, so toast B was killed early
  // by toast A's pending timer. Timer now lives in a ref and is reset on every
  // call; container is a polite live region and toasts are dismissible.
  const showToast = (msg: string, variant: ToastVariant = 'info') => {
    if (toastTimerRef.current !== null) {
      window.clearTimeout(toastTimerRef.current);
    }
    setToast({ id: Date.now(), message: msg, variant });
    toastTimerRef.current = window.setTimeout(() => {
      setToast(null);
      toastTimerRef.current = null;
    }, 4000);
  };

  const dismissToast = () => {
    if (toastTimerRef.current !== null) {
      window.clearTimeout(toastTimerRef.current);
      toastTimerRef.current = null;
    }
    setToast(null);
  };

  // DEV-2: parse a hash into view+params and apply it — used by the mount boot
  // (deep links like #/course/carpetry) and by popstate/hashchange (browser
  // back/forward). Redundant applications of the same route are skipped.
  const applyRoute = (hash: string) => {
    const route = parseRoute(hash);
    // DEV-2: protected views need a live session — deep links to #/dashboard
    // or #/admin while logged out fall back to home (same guard the UI had).
    if ((route.view === 'dashboard' || route.view === 'admin') && !authService.getCurrentUser()) {
      route.view = 'home';
      route.params = {};
    }
    const key = serializeRoute(route.view, route.params);
    if (routeKeyRef.current === key) return;
    routeKeyRef.current = key;
    setActiveView(route.view);
    setViewParams(route.params);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  // DEV-2: navigate now mirrors into the URL as a shareable deep link —
  // history.pushState + manual render (pushState does not fire hashchange, so
  // there is no double render); back/forward is handled by the listeners in
  // the mount effect below.
  const handleNavigate = (view: string, params: AppViewParams = {}) => {
    const hash = serializeRoute(view, params);
    if (window.location.hash !== hash) {
      window.history.pushState(null, '', hash);
    }
    applyRoute(hash);
  };

  // DEV-2: boot from the current hash, then re-parse on back/forward. Both
  // listeners are kept (history traversal fires both); the route-key guard in
  // applyRoute makes the double fire a no-op.
  useEffect(() => {
    applyRoute(window.location.hash);
    const onHashRouteChange = () => applyRoute(window.location.hash);
    window.addEventListener('popstate', onHashRouteChange);
    window.addEventListener('hashchange', onHashRouteChange);
    return () => {
      window.removeEventListener('popstate', onHashRouteChange);
      window.removeEventListener('hashchange', onHashRouteChange);
    };
  }, []);

  const handleSwitchRole = (role: 'student' | 'admin') => {
    const switched = authService.switchRole(role);
    setCurrentUser(switched);
    showToast(`نقش به عنوان «${role === 'admin' ? 'مدیر ارشد' : 'هنرجو (سارا احمدی)'}» تغییر یافت.`, 'info');
    if (role === 'admin' && activeView !== 'admin') {
      handleNavigate('admin');
    } else if (role === 'student' && activeView === 'admin') {
      handleNavigate('home');
    }
  };

  const handleLogout = () => {
    authService.logout();
    setCurrentUser(null);
    showToast('با موفقیت از حساب کاربری خارج شدید.', 'info');
    handleNavigate('home');
  };

  const handleOpenLessonPreview = (course: Course) => {
    // v1.1.0 Real-API: فهرست دوره‌ها lessons ندارد — برای پخش پیش‌نمایش، جزئیات کامل از سرور گرفته می‌شود.
    if (isRealApiMode && course.sections.length === 0) {
      void courseService
        .getCourseById(course.id)
        .then((full) => {
          const effectiveCourse = full || course;
          const allLessons = effectiveCourse.sections.flatMap((s) => s.lessons);
          const firstPreview = allLessons.find((l) => l.isFreePreview) || allLessons[0];
          if (!firstPreview) return;
          setActivePlayerCourse(effectiveCourse);
          setActivePlayerLesson(firstPreview);
          setIsPlayerOpen(true);
        })
        .catch(() => {
          // بدون جزئیات، پخش باز نمی‌شود (فهرست خالی درس‌ها) — رفتار امن.
        });
      return;
    }

    // Find first preview lesson or first lesson
    const allLessons = course.sections.flatMap((s) => s.lessons);
    const firstPreview = allLessons.find((l) => l.isFreePreview) || allLessons[0];
    setActivePlayerCourse(course);
    setActivePlayerLesson(firstPreview);
    setIsPlayerOpen(true);
  };

  const handlePlaySpecificLesson = (course: Course, lesson: Lesson) => {
    setActivePlayerCourse(course);
    setActivePlayerLesson(lesson);
    setIsPlayerOpen(true);
  };

  const handleSelectPlan = (plan: MembershipPlan) => {
    if (plan.priceToman === 0) {
      // Free plan instant activation
      if (!currentUser) {
        // BUG-46: keep the plan in state so a successful login resumes this
        // flow (see PhoneLoginModal.onSuccess) instead of silently dropping it.
        setSelectedPlanForCheckout(plan);
        setIsLoginModalOpen(true);
        return;
      }
      showToast('اشتراک رایگان شما فعال است و درس‌های پیش‌نمایش در دسترس هستند.', 'success');
      handleNavigate('catalogue');
      return;
    }

    setSelectedPlanForCheckout(plan);
    setIsCheckoutModalOpen(true);
  };

  const handleSelectGateway = async (gateway: PaymentGatewayType, plan: MembershipPlan) => {
    setIsCheckoutModalOpen(false);

    if (!currentUser) {
      // BUG-46: previously the checkout context was lost here — the modal
      // closed, login opened and nothing resumed. selectedPlanForCheckout now
      // stays set and PhoneLoginModal.onSuccess re-opens checkout automatically.
      setIsLoginModalOpen(true);
      return;
    }

    if (gateway === 'zibal') {
      try {
        const { order, gatewayUrl } = await paymentService.initiateZibalPayment(currentUser, plan);
        // v1.1.0 Real-API: خروج واقعی به درگاه زیبال — شبیه‌ساز فقط در دمو اجرا می‌شود؛
        // تایید پس از بازگشت، از طریق boot callback (verifyZibalCallback) انجام می‌شود.
        if (isRealApiMode) {
          window.location.href = gatewayUrl;
          return;
        }
        // BUG-45: the active zibal order is tracked in App state so an outcome-
        // less close of the gateway can be swept to 'cancelled'.
        setCurrentZibalOrder(order);
        setIsZibalModalOpen(true);
      } catch (e: any) {
        showToast(e.message || 'خطا در اتصال به درگاه زیبال', 'error');
      }
    } else {
      setIsManualTransferModalOpen(true);
    }
  };

  // BUG-45: closing the Zibal gateway without any outcome no longer leaves the
  // order pending forever — the order is cancelled with an admin note and the
  // user is informed. Handler for the optional onAbandon prop of
  // ZibalSimulatorModal (the prop itself landed via agent 3-b / BUG-65 and is
  // wired at the JSX call site).
  const handleZibalAbandon = () => {
    const orderId = currentZibalOrder?.id;
    if (!orderId) return;
    try {
      const order = orderStore.findById(orderId);
      // only an outcome-less PENDING order may be swept — a completed/failed
      // order already has its final state.
      if (order && order.status === 'pending') {
        mutateOrder(orderId, (draft) => {
          draft.status = 'cancelled';
          draft.adminNote = 'درگاه بدون نتیجه بسته شد';
        });
        showToast('سفارش لغو شد', 'info');
      }
    } catch (e: any) {
      showToast(e?.message || 'لغو سفارش با خطا مواجه شد', 'error');
    }
  };

  // DEV-13: on mount, sweep pending zibal orders older than 24h → 'cancelled'
  // (client-side mirror of the server-side daily reconciliation cron).
  useEffect(() => {
    try {
      const dayAgoMs = Date.now() - 24 * 60 * 60 * 1000;
      orderStore
        .getAll()
        .filter(
          (o) =>
            o.gateway === 'zibal' &&
            o.status === 'pending' &&
            new Date(o.createdAt).getTime() < dayAgoMs
        )
        .forEach((o) => {
          mutateOrder(o.id, (draft) => {
            draft.status = 'cancelled';
            draft.adminNote = 'سفارش پرداخت‌نشده پس از ۲۴ ساعت به‌صورت خودکار لغو شد';
          });
        });
    } catch (e) {
      // DEV-13: mutateOrder throws on storage failure — the boot sweep is
      // best-effort and must never block the app from rendering.
    }
  }, []);

  // ---------------------------------------------------------------------------
  // v1.1.0 — Real-API boot (تنها تغییر boot اپ؛ در دمو بدون env این اثر no-op است):
  //   ۱) کال‌بک زیبال: پس از بازگشت از درگاه، پارامترها detect→clear و verify می‌شود؛
  //      نتیجه با toast موجود اعلام و به dashboard (موفق) یا catalogue (ناموفق) هدایت می‌شود.
  //   ۲) همگام‌سازی سشن: اگر توکن Real موجود باشد، عضویت/هویت از /auth/me تازه می‌شود.
  // ---------------------------------------------------------------------------
  useEffect(() => {
    if (!isRealApiMode) return;
    let cancelled = false;

    const runBoot = async () => {
      const callback = detectZibalCallback();
      if (callback) {
        // پارامترها فوراً پاک می‌شوند تا refresh صفحه هرگز دوباره verify نزند.
        clearZibalCallbackParams(callback.source);
        try {
          const result = await paymentService.verifyZibalCallback(callback);
          if (cancelled) return;
          if (result.success) {
            // اشتراک سمت سرور فعال شده — سشن از سرور تازه می‌شود تا داشبورد آن را نشان دهد.
            const synced = await authService.syncSessionFromServer();
            if (cancelled) return;
            if (synced) setCurrentUser(synced);
            showToast(result.message || 'پرداخت با موفقیت تایید شد و اشتراک فعال گردید.', 'success');
            handleNavigate('dashboard');
          } else {
            showToast(result.message || 'پرداخت ناموفق بود یا لغو گردید.', 'error');
            handleNavigate('catalogue');
          }
        } catch (err) {
          if (cancelled) return;
          showToast(err instanceof Error ? err.message : 'تایید پرداخت با خطا مواجه شد؛ در صورت کسر وجه با پشتیبانی تماس بگیرید.', 'error');
          handleNavigate('catalogue');
        }
        return;
      }

      try {
        const synced = await authService.syncSessionFromServer();
        if (cancelled) return;
        if (synced) {
          setCurrentUser(synced);
        } else if (!authService.getCurrentUser()) {
          // 401 در boot → سشن محلی هم بسته شده — state اپ هم باید خارج‌شده را نشان دهد.
          setCurrentUser(null);
        }
      } catch (err) {
        // خطای شبکه در boot — سشن محلی حفظ می‌شود (تحمل آفلاین).
      }
    };

    void runBoot();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const handleZibalPaymentComplete = (order: PaymentOrder, success: boolean) => {
    setCurrentZibalOrder(null);
    setCurrentUser(authService.getCurrentUser());
    if (success) {
      // BUG-46 hygiene: the purchase finished — clear the pending plan so a
      // later login cannot spuriously resume a completed checkout.
      setSelectedPlanForCheckout(null);
      showToast('پرداخت زیبال تایید شد و اشتراک با موفقیت فعال گردید.', 'success');
      handleNavigate('dashboard');
    } else {
      showToast('تراکنش ناموفق بود یا لغو گردید.', 'error');
    }
  };

  const handleManualTransferSubmitted = (order: PaymentOrder) => {
    // BUG-46 hygiene: receipt registered — nothing left to resume after login.
    setSelectedPlanForCheckout(null);
    showToast('فیش واریزی ثبت شد و پس از تایید مدیر، اشتراک فعال خواهد شد.', 'success');
    handleNavigate('dashboard');
  };

  // DEV-4/3-b: parent-owned completion handler — persists through the
  // whitelisted updater AND refreshes App state (setUser) so the dashboard /
  // CourseDetailView re-render live (player icon state derives from props).
  const handleCompleteLesson = (lessonId: string) => {
    const user = authService.getCurrentUser();
    if (!user) return;
    const next = user.completedLessonIds.includes(lessonId)
      ? user.completedLessonIds
      : [...user.completedLessonIds, lessonId];
    setCurrentUser(authService.updateCurrentUser({ completedLessonIds: next }));
    showToast('این جلسه با موفقیت تکمیل شد!', 'success');
  };

  // DEV-5/3-b: bookmark toggle — same parent-owned pattern (updateCurrentUser +
  // setUser) so the dashboard «نشان‌شده‌ها» section updates live.
  const handleToggleBookmark = (lessonId: string) => {
    const user = authService.getCurrentUser();
    if (!user) return;
    const next = user.bookmarkedLessonIds.includes(lessonId)
      ? user.bookmarkedLessonIds.filter((id) => id !== lessonId)
      : [...user.bookmarkedLessonIds, lessonId];
    setCurrentUser(authService.updateCurrentUser({ bookmarkedLessonIds: next }));
  };

  return (
    <div className="min-h-screen flex flex-col bg-[#fdfbf7] text-stone-800 font-sans antialiased">
      {/* Toast Notification — BUG-44: polite live region + variant colors + dismiss */}
      {toast && (
        <div
          key={toast.id}
          role="status"
          aria-live="polite"
          className={`fixed top-14 left-4 right-4 md:left-auto md:right-4 z-50 text-xs px-4 py-2.5 rounded-xl shadow-xl border flex items-center justify-between gap-2 animate-in fade-in slide-in-from-top-2 ${TOAST_STYLES[toast.variant]}`}
        >
          <span className="leading-relaxed">{toast.message}</span>
          <button
            onClick={dismissToast}
            aria-label="بستن پیام"
            className="shrink-0 w-11 h-11 -m-2 flex items-center justify-center rounded-lg hover:bg-white/10 transition"
          >
            <X className="w-3.5 h-3.5" />
          </button>
        </div>
      )}

      {/* Offline Connectivity Status */}
      <OfflineIndicator />

      {/* DEV-7: PWA update prompt — fixed bottom card when a new service
          worker is waiting in prompt mode (no scroll lock, dismissible). */}
      <PWAUpdatePrompt />

      {/* Top Header */}
      <Header
        currentUser={currentUser}
        onOpenLogin={() => setIsLoginModalOpen(true)}
        onSwitchRole={handleSwitchRole}
        onNavigate={handleNavigate}
        activeView={activeView}
      />

      {/* Main Content Area */}
      <main className="flex-1">
        {activeView === 'home' && (
          <HomeView
            onNavigate={handleNavigate}
            onOpenLessonPreview={handleOpenLessonPreview}
            onSelectPlan={handleSelectPlan}
          />
        )}

        {activeView === 'catalogue' && (
          <CatalogueView
            initialCategory={viewParams.category || 'all'}
            onNavigate={handleNavigate}
            onOpenLessonPreview={handleOpenLessonPreview}
            currentUser={currentUser}
          />
        )}

        {activeView === 'course_detail' && (
          <CourseDetailView
            courseId={viewParams.courseId || 'course-carpet-tabriz'}
            onNavigate={handleNavigate}
            onPlayLesson={handlePlaySpecificLesson}
            currentUser={currentUser}
            onOpenMembership={() => handleNavigate('membership')}
            onOpenLogin={() => setIsLoginModalOpen(true)}
          />
        )}

        {activeView === 'membership' && (
          <MembershipView
            onSelectPlan={handleSelectPlan}
            currentUser={currentUser}
            onNavigate={handleNavigate}
          />
        )}

        {activeView === 'dashboard' && currentUser && (
          <UserDashboardView
            currentUser={currentUser}
            onLogout={handleLogout}
            onNavigate={handleNavigate}
            onOpenMembership={() => handleNavigate('membership')}
            onPlayLesson={handlePlaySpecificLesson}
          />
        )}

        {activeView === 'admin' && currentUser && (
          <AdminDashboardView currentUser={currentUser} onNavigate={handleNavigate} />
        )}

        {activeView === 'editorial' && (
          <EditorialPageView
            slug={viewParams.slug || 'about-us'}
            onNavigate={handleNavigate}
          />
        )}
      </main>

      {/* Footer */}
      <footer className="bg-stone-900 text-stone-400 py-10 px-4 border-t border-stone-800 text-xs">
        <div className="max-w-5xl mx-auto space-y-6">
          <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div className="space-y-1">
              <div className="flex items-center gap-2">
                <span className="font-black text-amber-100 text-sm">مستر دستباف</span>
                <span className="text-[10px] text-amber-400 font-mono">mrdastbaaf.com</span>
              </div>
              <p className="text-[11px] text-stone-400">
                پلتفرم تخصصی آموزش آنلاین صنایع دستی، قالی‌بافی و هنرهای اصیل دستباف
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-4 text-[11px]">
              <button
                onClick={() => handleNavigate('editorial', { slug: 'about-us' })}
                className="hover:text-stone-200 transition"
              >
                درباره ما
              </button>
              <button
                onClick={() => handleNavigate('editorial', { slug: 'guide-membership' })}
                className="hover:text-stone-200 transition"
              >
                راهنمای عضویت و تمدید دستی
              </button>
              <button
                onClick={() => handleNavigate('editorial', { slug: 'faq' })}
                className="hover:text-stone-200 transition"
              >
                سوالات متداول
              </button>
              <button
                onClick={() => handleNavigate('membership')}
                className="hover:text-stone-200 transition"
              >
                تعرفه‌ها
              </button>
            </div>
          </div>

          <div className="pt-4 border-t border-stone-800/80 flex flex-col sm:flex-row items-center justify-between gap-2 text-[10px] text-stone-500">
            <p>© تمام حقوق مادی و معنوی آموزش‌ها متعلق به مجموعه مستر دستباف است.</p>
            <p className="font-mono">PWA Prototype v1.0.0 · WordPress & Otino Ready</p>
          </div>
        </div>
      </footer>

      {/* Mobile Ergonomic Bottom Navigation */}
      <BottomNav
        activeView={activeView}
        onNavigate={handleNavigate}
        userRole={currentUser?.role}
        isLoggedIn={!!currentUser}
        onOpenLogin={() => setIsLoginModalOpen(true)}
      />

      {/* MODALS */}
      {/* 1. Phone Otino SMS OTP Login Modal */}
      <PhoneLoginModal
        isOpen={isLoginModalOpen}
        onClose={() => setIsLoginModalOpen(false)}
        onSuccess={(user) => {
          setCurrentUser(user);
          showToast(`خوش آمدید، ${user.fullName}`, 'success');
          // BUG-46: resume the interrupted purchase flow after a successful
          // login — selectedPlanForCheckout survived the modal switch (both the
          // paid-checkout path and the free-plan path keep it in state).
          const pendingPlan = selectedPlanForCheckout;
          if (pendingPlan) {
            if (pendingPlan.priceToman === 0) {
              // free plan: instant-activation path (same as handleSelectPlan)
              showToast('اشتراک رایگان شما فعال است و درس‌های پیش‌نمایش در دسترس هستند.', 'success');
              handleNavigate('catalogue');
            } else {
              setIsCheckoutModalOpen(true);
              showToast('ادامه خرید…', 'info');
            }
          }
        }}
      />

      {/* 2. Membership Checkout Modal */}
      <CheckoutModal
        isOpen={isCheckoutModalOpen}
        onClose={() => setIsCheckoutModalOpen(false)}
        plan={selectedPlanForCheckout}
        currentUser={currentUser}
        onSelectGateway={handleSelectGateway}
        onOpenLogin={() => setIsLoginModalOpen(true)}
      />

      {/* 3. Zibal Gateway Simulator */}
      {/* BUG-45: onAbandon is wired (agent 3-b landed the prop as BUG-65) —
          closing the gateway without an outcome parks the pending order as
          'cancelled' + adminNote + info toast via handleZibalAbandon. */}
      <ZibalSimulatorModal
        isOpen={isZibalModalOpen}
        onClose={() => setIsZibalModalOpen(false)}
        order={currentZibalOrder}
        onPaymentComplete={handleZibalPaymentComplete}
        onAbandon={handleZibalAbandon}
      />

      {/* 4. Manual Bank Transfer (کارت به کارت) Modal */}
      <ManualTransferModal
        isOpen={isManualTransferModalOpen}
        onClose={() => setIsManualTransferModalOpen(false)}
        plan={selectedPlanForCheckout}
        currentUser={currentUser}
        onSubmitted={handleManualTransferSubmitted}
      />

      {/* 5. Custom Branded Lesson Video Player */}
      {/* DEV-4/DEV-5: onCompleteLesson + onToggleBookmark are wired (agent 3-b
          landed both props as BUG-59). Both handlers persist through
          authService.updateCurrentUser and refresh App's currentUser state so
          dashboard / CourseDetailView re-render live. The legacy
          onLessonCompleted prop is intentionally NOT passed — the player
          prefers onCompleteLesson and the legacy slot was toast-only. */}
      {isPlayerOpen && activePlayerCourse && activePlayerLesson && (
        <LessonPlayerModal
          isOpen={isPlayerOpen}
          onClose={() => setIsPlayerOpen(false)}
          course={activePlayerCourse}
          currentLesson={activePlayerLesson}
          onSelectLesson={(l) => setActivePlayerLesson(l)}
          currentUser={currentUser}
          onCompleteLesson={handleCompleteLesson}
          onToggleBookmark={handleToggleBookmark}
        />
      )}
    </div>
  );
}
