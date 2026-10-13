/**
 * @license
 * SPDX-License-Identifier: Apache-2.0
 */

/**
 * @file App.tsx
 * @description Main application shell for Mr. Dastbaaf Handicraft Learning Platform PWA
 */

import React, { useState, useEffect } from 'react';
import { Course, Lesson, MembershipPlan, PaymentGatewayType, PaymentOrder, UserProfile } from './types';
import { authService } from './services/authService';
import { courseService } from './services/courseService';
import { paymentService } from './services/paymentService';

// Common Components
import { Header } from './components/common/Header';
import { BottomNav } from './components/common/BottomNav';
import { OfflineIndicator } from './components/common/OfflineIndicator';

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

export default function App() {
  const [currentUser, setCurrentUser] = useState<UserProfile | null>(authService.getCurrentUser());
  const [activeView, setActiveView] = useState<string>('home');
  const [viewParams, setViewParams] = useState<any>({});

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
  const [toastMessage, setToastMessage] = useState<string | null>(null);

  const showToast = (msg: string) => {
    setToastMessage(msg);
    setTimeout(() => setToastMessage(null), 4000);
  };

  const handleNavigate = (view: string, params: any = {}) => {
    setActiveView(view);
    setViewParams(params);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  const handleSwitchRole = (role: 'student' | 'admin') => {
    const switched = authService.switchRole(role);
    setCurrentUser(switched);
    showToast(`نقش به عنوان «${role === 'admin' ? 'مدیر ارشد' : 'هنرجو (سارا احمدی)'}» تغییر یافت.`);
    if (role === 'admin' && activeView !== 'admin') {
      handleNavigate('admin');
    } else if (role === 'student' && activeView === 'admin') {
      handleNavigate('home');
    }
  };

  const handleLogout = () => {
    authService.logout();
    setCurrentUser(null);
    showToast('با موفقیت از حساب کاربری خارج شدید.');
    handleNavigate('home');
  };

  const handleOpenLessonPreview = (course: Course) => {
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
        setIsLoginModalOpen(true);
        return;
      }
      showToast('اشتراک رایگان شما فعال است و درس‌های پیش‌نمایش در دسترس هستند.');
      handleNavigate('catalogue');
      return;
    }

    setSelectedPlanForCheckout(plan);
    setIsCheckoutModalOpen(true);
  };

  const handleSelectGateway = async (gateway: PaymentGatewayType, plan: MembershipPlan) => {
    setIsCheckoutModalOpen(false);

    if (!currentUser) {
      setIsLoginModalOpen(true);
      return;
    }

    if (gateway === 'zibal') {
      try {
        const { order } = await paymentService.initiateZibalPayment(currentUser, plan);
        setCurrentZibalOrder(order);
        setIsZibalModalOpen(true);
      } catch (e: any) {
        showToast(e.message || 'خطا در اتصال به درگاه زیبال');
      }
    } else {
      setIsManualTransferModalOpen(true);
    }
  };

  const handleZibalPaymentComplete = (order: PaymentOrder, success: boolean) => {
    setCurrentUser(authService.getCurrentUser());
    if (success) {
      showToast('پرداخت زیبال تایید شد و اشتراک با موفقیت فعال گردید.');
      handleNavigate('dashboard');
    } else {
      showToast('تراکنش ناموفق بود یا لغو گردید.');
    }
  };

  const handleManualTransferSubmitted = (order: PaymentOrder) => {
    showToast('فیش واریزی ثبت شد و پس از تایید مدیر، اشتراک فعال خواهد شد.');
    handleNavigate('dashboard');
  };

  return (
    <div className="min-h-screen flex flex-col bg-[#fdfbf7] text-stone-800 font-sans antialiased">
      {/* Toast Notification */}
      {toastMessage && (
        <div className="fixed top-14 left-4 right-4 md:left-auto md:right-4 z-50 bg-stone-900 text-stone-100 text-xs px-4 py-2.5 rounded-xl shadow-xl border border-stone-800 animate-in fade-in slide-in-from-top-2">
          {toastMessage}
        </div>
      )}

      {/* Offline Connectivity Status */}
      <OfflineIndicator />

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
          showToast(`خوش آمدید، ${user.fullName}`);
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
      <ZibalSimulatorModal
        isOpen={isZibalModalOpen}
        onClose={() => setIsZibalModalOpen(false)}
        order={currentZibalOrder}
        onPaymentComplete={handleZibalPaymentComplete}
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
      {isPlayerOpen && activePlayerCourse && activePlayerLesson && (
        <LessonPlayerModal
          isOpen={isPlayerOpen}
          onClose={() => setIsPlayerOpen(false)}
          course={activePlayerCourse}
          currentLesson={activePlayerLesson}
          onSelectLesson={(l) => setActivePlayerLesson(l)}
          currentUser={currentUser}
          onLessonCompleted={(lessonId) => {
            showToast('این جلسه با موفقیت تکمیل شد!');
          }}
        />
      )}
    </div>
  );
}
