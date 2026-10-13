/**
 * @file views/AdminDashboardView.tsx
 * @description Administrative control center for Mr. Dastbaaf platform
 * 
 * CRITICAL ARCHITECTURAL NOTE:
 * In production, administrative capabilities MUST be authorized by the WordPress
 * backend via capabilities checks (e.g. current_user_can('manage_options')).
 * Client-side role gating is merely a UI convenience and must NEVER be relied upon
 * as the security perimeter. All API requests in production will be validated via
 * HTTP Bearer auth tokens.
 */

import React, { useState, useEffect } from 'react';
import {
  ShieldCheck,
  BarChart3,
  CreditCard,
  Crown,
  Layout,
  FileText,
  Users,
  CheckCircle,
  XCircle,
  AlertTriangle,
  ArrowUp,
  ArrowDown,
  Eye,
  EyeOff,
  Edit,
  Save,
  Trash2,
  RefreshCw,
  Search,
} from 'lucide-react';
import {
  EditorialPage,
  HomepageSectionConfig,
  MembershipPlan,
  OrderStatus,
  PaymentGatewayType,
  PaymentOrder,
  UserProfile,
} from '../types';
import { adminService } from '../services/adminService';
import { paymentService } from '../services/paymentService';
import { transferService } from '../services/transferService';
import { membershipService } from '../services/membershipService';
import { ConfirmDialog } from '../components/common/ConfirmDialog';
import { Modal } from '../components/common/Modal';
import { toEnglishDigits, formatToman } from '../utils/format';

interface AdminDashboardViewProps {
  currentUser: UserProfile;
  onNavigate: (view: string, params?: any) => void;
}

export const AdminDashboardView: React.FC<AdminDashboardViewProps> = ({
  currentUser,
  onNavigate,
}) => {
  const [activeTab, setActiveTab] = useState<
    'analytics' | 'receipts' | 'plans' | 'sections' | 'editorial' | 'orders'
  >('receipts');

  const [analytics, setAnalytics] = useState<{
    totalRevenueToman: number;
    pendingReceiptsCount: number;
    completedOrdersCount: number;
    activeMembersCount: number;
  }>({ totalRevenueToman: 0, pendingReceiptsCount: 0, completedOrdersCount: 0, activeMembersCount: 0 });

  const [orders, setOrders] = useState<PaymentOrder[]>([]);
  const [plans, setPlans] = useState<MembershipPlan[]>([]);
  const [sections, setSections] = useState<HomepageSectionConfig[]>([]);
  const [editorialPages, setEditorialPages] = useState<EditorialPage[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  // BUG-43e: loadData had no catch — one rejected service call left the whole
  // admin panel silently empty. An explicit error banner + retry closes that.
  const [loadError, setLoadError] = useState(false);

  // DEV-16: orders/receipts filters — status, gateway and free-text search over
  // buyer name / order number / item title (client-side, on the loaded orders).
  const [orderStatusFilter, setOrderStatusFilter] = useState<'all' | OrderStatus>('all');
  const [orderGatewayFilter, setOrderGatewayFilter] = useState<'all' | PaymentGatewayType>('all');
  const [orderSearchQuery, setOrderSearchQuery] = useState('');

  // Modal / Confirm Dialog States
  const [confirmDialog, setConfirmDialog] = useState<{
    isOpen: boolean;
    title: string;
    description: string;
    variant: 'danger' | 'warning' | 'primary';
    action: () => Promise<void>;
  }>({
    isOpen: false,
    title: '',
    description: '',
    variant: 'warning',
    action: async () => {},
  });

  // Rejection modal with reason input
  const [rejectModalOrder, setRejectModalOrder] = useState<PaymentOrder | null>(null);
  const [rejectReason, setRejectReason] = useState('مغایرت مبلغ فیش با سفارش یا عدم واریز به حساب');

  // Edit Plan Modal
  const [editingPlan, setEditingPlan] = useState<MembershipPlan | null>(null);

  // Edit Hero Section Modal
  const [editingSection, setEditingSection] = useState<HomepageSectionConfig | null>(null);

  // Edit Editorial Page Modal
  const [editingPage, setEditingPage] = useState<EditorialPage | null>(null);

  const loadData = async () => {
    setIsLoading(true);
    setLoadError(false);
    // BUG-43e: try/catch — previously an unhandled rejection inside Promise.all
    // killed loadData mid-way (spinner state stuck, stale tables) with no
    // recovery path. Now: error banner with a retry button.
    try {
      const [ana, ords, pls, secs, pgs] = await Promise.all([
        adminService.getAnalyticsSummary(),
        paymentService.getAllOrders(),
        membershipService.getAllPlans(),
        adminService.getHomepageSections(),
        adminService.getEditorialPages(),
      ]);
      setAnalytics(ana);
      setOrders(ords);
      setPlans(pls);
      setSections(secs);
      setEditorialPages(pgs);
    } catch (e) {
      setLoadError(true);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const pendingReceipts = orders.filter((o) => o.gateway === 'card_to_card' && o.status === 'pending');

  // DEV-16: Persian labels for every OrderStatus (the old table only knew
  // completed/pending/«رد شده» and collapsed the new statuses together).
  const ORDER_STATUS_LABELS: Record<OrderStatus, string> = {
    pending: 'در انتظار بررسی',
    completed: 'تایید شده',
    failed: 'ناموفق',
    cancelled: 'لغو شده',
    verifying: 'در حال بررسی',
    processing: 'در حال پردازش',
    rejected: 'رد شده',
  };

  const orderStatusBadgeClass = (status: OrderStatus): string => {
    if (status === 'completed') return 'bg-emerald-100 text-emerald-800';
    if (status === 'pending') return 'bg-amber-100 text-amber-800';
    if (status === 'verifying' || status === 'processing') return 'bg-stone-100 text-stone-700';
    return 'bg-red-100 text-red-800';
  };

  // DEV-16: client-side filtering over the loaded orders
  const filteredOrders = orders.filter((o) => {
    if (orderStatusFilter !== 'all' && o.status !== orderStatusFilter) return false;
    if (orderGatewayFilter !== 'all' && o.gateway !== orderGatewayFilter) return false;
    if (orderSearchQuery.trim() !== '') {
      const q = orderSearchQuery.trim().toLowerCase();
      const haystack = `${o.userFullName} ${o.orderNumber} ${o.itemTitle}`.toLowerCase();
      if (!haystack.includes(q)) return false;
    }
    return true;
  });

  const handleApproveReceipt = (order: PaymentOrder) => {
    setConfirmDialog({
      isOpen: true,
      title: 'تایید فیش کارت به کارت و فعال‌سازی اشتراک',
      description: `آیا واریز مبلغ ${order.amountToman.toLocaleString(
        'fa-IR'
      )} تومان توسط ${order.userFullName} (کد پیگیری: ${
        order.manualTransferDetails?.trackingCode
      }) به حساب بانک ملت تایید شده است؟ پس از تایید، اشتراک کاربر بلافاصله فعال می‌شود.`,
      variant: 'primary',
      action: async () => {
        await transferService.approveTransferOrder(order.id, 'تایید شده توسط مدیریت ارشد');
        setConfirmDialog((prev) => ({ ...prev, isOpen: false }));
        loadData();
      },
    });
  };

  const handleConfirmRejectReceipt = async () => {
    if (!rejectModalOrder) return;
    await transferService.rejectTransferOrder(rejectModalOrder.id, rejectReason);
    setRejectModalOrder(null);
    loadData();
  };

  const handleMoveSection = async (index: number, direction: 'up' | 'down') => {
    const newSections = [...sections];
    const targetIndex = direction === 'up' ? index - 1 : index + 1;
    if (targetIndex < 0 || targetIndex >= newSections.length) return;

    const temp = newSections[index];
    newSections[index] = newSections[targetIndex];
    newSections[targetIndex] = temp;

    // re-assign sort order
    newSections.forEach((s, idx) => (s.sortOrder = idx + 1));
    setSections(newSections);
    await adminService.updateHomepageSections(newSections);
  };

  const handleToggleSection = async (sectionId: string, currentEnabled: boolean) => {
    const updated = sections.map((s) => (s.id === sectionId ? { ...s, isEnabled: !currentEnabled } : s));
    setSections(updated);
    await adminService.updateHomepageSections(updated);
  };

  const handleSavePlan = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingPlan) return;
    // PRICE VALIDATION FIX (BUG-24): previously NaN/negative prices passed straight
    // through to updatePlan (mirrors the server-side BUG-13 fix).
    if (!Number.isFinite(editingPlan.priceToman) || editingPlan.priceToman < 0) {
      setConfirmDialog({
        isOpen: true,
        title: 'قیمت نامعتبر است',
        description: 'شهریه پلن باید عددی نامنفی باشد. لطفاً مقدار صحیح وارد کنید.',
        variant: 'warning',
        action: async () => setConfirmDialog((prev) => ({ ...prev, isOpen: false })),
      });
      return;
    }
    await membershipService.updatePlan(editingPlan.id, editingPlan);
    setEditingPlan(null);
    loadData();
  };

  const handleSaveEditorialPage = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingPage) return;
    await adminService.updateEditorialPage(editingPage.slug, editingPage);
    setEditingPage(null);
    loadData();
  };

  const handleSaveSection = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!editingSection) return;
    await adminService.updateSection(editingSection.id, editingSection);
    setEditingSection(null);
    loadData();
  };

  return (
    <div className="max-w-6xl mx-auto px-4 py-6 space-y-6 pb-28 text-xs text-stone-800">
      {/* Top Banner & Security Disclosure */}
      <div className="bg-stone-900 text-stone-100 p-4 sm:p-5 rounded-3xl border border-stone-800 shadow-lg flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div>
          <div className="flex items-center gap-2 text-amber-400 font-bold text-sm">
            <ShieldCheck className="w-5 h-5 text-amber-400" />
            <span>مرکز کنترل مدیریت مستر دستباف (Admin Control Center)</span>
          </div>
          <p className="text-[11px] text-stone-400 mt-1 leading-relaxed">
            مدیریت محتوا، اعتبارسنجی فیش‌های کارت به کارت، تنظیم پلن‌های عضویت و چیدمان صفحه نخست
          </p>
        </div>

        {/* Server Auth Reality Callout */}
        <div className="bg-amber-950/80 border border-amber-800/80 p-2.5 rounded-xl text-[10px] text-amber-200/90 max-w-sm">
          <strong>الزام معماری امنیت سرور:</strong> دسترسی به این بخش در پروتوتایپ باز است. در نسخه نهایی وردپرس، تمامی فراخوانی‌ها باید با مجوزهای سطح ادمین (<code className="font-mono text-white">manage_options</code>) در بک‌اند اعتبارسنجی شوند.
        </div>
      </div>

      {/* BUG-43e: load-error banner with retry */}
      {loadError && (
        <div className="bg-red-50 border border-red-200 rounded-2xl p-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            <AlertTriangle className="w-5 h-5 text-red-600 shrink-0" />
            <div>
              <p className="font-bold text-xs text-red-900">بارگذاری داده‌های مدیریت با خطا مواجه شد</p>
              <p className="text-[11px] text-red-700 mt-0.5">
                سفارش‌ها، پلن‌ها یا سکشن‌ها به‌روز نشدند؛ لطفاً دوباره تلاش کنید.
              </p>
            </div>
          </div>
          <button
            onClick={loadData}
            className="bg-red-700 hover:bg-red-800 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition flex items-center gap-1.5 min-h-11"
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>تلاش مجدد</span>
          </button>
        </div>
      )}

      {/* Analytics Metric Cards */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div className="bg-white p-4 rounded-2xl border border-stone-200 shadow-xs space-y-1">
          <span className="text-[11px] text-stone-500 font-medium">فیش‌های در انتظار بررسی:</span>
          <p className="text-xl sm:text-2xl font-black font-mono text-amber-800">
            {pendingReceipts.length}
          </p>
          <span className="text-[10px] text-stone-400">کارت به کارت نیازمند بازبینی</span>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-stone-200 shadow-xs space-y-1">
          <span className="text-[11px] text-stone-500 font-medium">مجموع فروش تایید شده:</span>
          <p className="text-xl sm:text-2xl font-black font-mono text-emerald-800">
            {analytics.totalRevenueToman.toLocaleString('fa-IR')} <span className="text-xs font-normal">تومان</span>
          </p>
          <span className="text-[10px] text-stone-400">درگاه زیبال + فیش‌های تایید شده</span>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-stone-200 shadow-xs space-y-1">
          <span className="text-[11px] text-stone-500 font-medium">سفارش‌های موفق:</span>
          <p className="text-xl sm:text-2xl font-black font-mono text-stone-800">
            {analytics.completedOrdersCount}
          </p>
          <span className="text-[10px] text-stone-400">تراکنش کامل</span>
        </div>

        <div className="bg-white p-4 rounded-2xl border border-stone-200 shadow-xs space-y-1">
          <span className="text-[11px] text-stone-500 font-medium">هنرجویان فعال:</span>
          <p className="text-xl sm:text-2xl font-black font-mono text-stone-800">
            {analytics.activeMembersCount}
          </p>
          <span className="text-[10px] text-stone-400">دارای اشتراک فعال</span>
        </div>
      </div>

      {/* Navigation Tabs (Mobile scrollable & desktop bar) */}
      <div className="flex items-center gap-1.5 overflow-x-auto pb-1 bg-white p-1.5 rounded-2xl border border-stone-200 shadow-xs no-scrollbar">
        <button
          onClick={() => setActiveTab('receipts')}
          className={`px-3 py-2 rounded-xl font-bold shrink-0 transition flex items-center gap-1.5 ${
            activeTab === 'receipts'
              ? 'bg-amber-800 text-white shadow-xs'
              : 'text-stone-600 hover:bg-stone-100'
          }`}
        >
          <CreditCard className="w-4 h-4" />
          <span>بررسی فیش‌های واریزی ({pendingReceipts.length})</span>
        </button>

        <button
          onClick={() => setActiveTab('plans')}
          className={`px-3 py-2 rounded-xl font-bold shrink-0 transition flex items-center gap-1.5 ${
            activeTab === 'plans'
              ? 'bg-amber-800 text-white shadow-xs'
              : 'text-stone-600 hover:bg-stone-100'
          }`}
        >
          <Crown className="w-4 h-4" />
          <span>پلن‌های عضویت</span>
        </button>

        <button
          onClick={() => setActiveTab('sections')}
          className={`px-3 py-2 rounded-xl font-bold shrink-0 transition flex items-center gap-1.5 ${
            activeTab === 'sections'
              ? 'bg-amber-800 text-white shadow-xs'
              : 'text-stone-600 hover:bg-stone-100'
          }`}
        >
          <Layout className="w-4 h-4" />
          <span>چیدمان صفحه اصلی</span>
        </button>

        <button
          onClick={() => setActiveTab('editorial')}
          className={`px-3 py-2 rounded-xl font-bold shrink-0 transition flex items-center gap-1.5 ${
            activeTab === 'editorial'
              ? 'bg-amber-800 text-white shadow-xs'
              : 'text-stone-600 hover:bg-stone-100'
          }`}
        >
          <FileText className="w-4 h-4" />
          <span>صفحات متنی وردپرس</span>
        </button>

        <button
          onClick={() => setActiveTab('orders')}
          className={`px-3 py-2 rounded-xl font-bold shrink-0 transition flex items-center gap-1.5 ${
            activeTab === 'orders'
              ? 'bg-amber-800 text-white shadow-xs'
              : 'text-stone-600 hover:bg-stone-100'
          }`}
        >
          <BarChart3 className="w-4 h-4" />
          <span>تمام سفارش‌ها</span>
        </button>
      </div>

      {/* TAB 1: MANUAL TRANSFER RECEIPT REVIEW QUEUE */}
      {activeTab === 'receipts' && (
        <div className="bg-white rounded-3xl p-5 border border-stone-200/90 shadow-xs space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-sm sm:text-base font-bold text-amber-950">
                صف بررسی و تایید فیش‌های کارت به کارت
              </h2>
              <p className="text-stone-500 text-[11px] mt-0.5">
                تطبیق واریزی حساب بانک ملت با شماره پیگیری ثبت شده توسط خریدار
              </p>
            </div>
            <button
              onClick={loadData}
              className="p-1.5 rounded-lg border text-stone-500 hover:text-stone-800 flex items-center gap-1 text-[11px]"
            >
              <RefreshCw className="w-3.5 h-3.5" />
              <span>به‌روزرسانی</span>
            </button>
          </div>

          {pendingReceipts.length === 0 ? (
            <div className="py-12 text-center text-stone-400 space-y-2">
              <CheckCircle className="w-10 h-10 text-emerald-500 mx-auto" />
              <p className="font-bold text-stone-700">هیچ فیش معلقی در صف بررسی وجود ندارد.</p>
              <p className="text-[11px]">تمامی واریزی‌ها بررسی و تعیین وضعیت شده‌اند.</p>
            </div>
          ) : (
            <div className="space-y-3">
              {pendingReceipts.map((order) => (
                <div
                  key={order.id}
                  className="bg-amber-50/40 border border-amber-200 rounded-2xl p-4 flex flex-col md:flex-row items-start md:items-center justify-between gap-4"
                >
                  <div className="space-y-1.5">
                    <div className="flex items-center gap-2">
                      <span className="font-bold text-stone-900 text-sm">{order.userFullName}</span>
                      <span className="font-mono text-stone-600 text-[11px]" dir="ltr">
                        {order.userPhone}
                      </span>
                      <span className="bg-amber-100 text-amber-900 px-2 py-0.5 rounded text-[10px] font-bold">
                        {order.itemTitle}
                      </span>
                    </div>

                    <div className="grid grid-cols-2 sm:grid-cols-4 gap-2 text-[11px] text-stone-600 pt-1">
                      <div>
                        <span className="text-stone-400 block text-[10px]">کد پیگیری:</span>
                        <strong className="font-mono text-stone-800">
                          {order.manualTransferDetails?.trackingCode}
                        </strong>
                      </div>
                      <div>
                        <span className="text-stone-400 block text-[10px]">۴ رقم کارت:</span>
                        <strong className="font-mono text-stone-800">
                          {order.manualTransferDetails?.sourceCardLastFour}
                        </strong>
                      </div>
                      <div>
                        <span className="text-stone-400 block text-[10px]">تاریخ واریز:</span>
                        <span className="text-stone-800">
                          {order.manualTransferDetails?.depositDateJalali}
                        </span>
                      </div>
                      <div>
                        <span className="text-stone-400 block text-[10px]">مبلغ:</span>
                        <strong className="text-amber-900 font-mono">
                          {order.amountToman.toLocaleString('fa-IR')} تومان
                        </strong>
                      </div>
                    </div>

                    {order.manualTransferDetails?.senderNotes && (
                      <p className="text-[11px] text-stone-500 italic mt-1">
                        یادداشت کاربر: {order.manualTransferDetails.senderNotes}
                      </p>
                    )}
                  </div>

                  {/* Actions */}
                  <div className="flex items-center gap-2 w-full md:w-auto justify-end pt-3 md:pt-0 border-t md:border-t-0 border-amber-200/80">
                    <button
                      onClick={() => handleApproveReceipt(order)}
                      className="bg-emerald-700 hover:bg-emerald-800 text-white font-bold px-3 py-2 rounded-xl transition flex items-center gap-1.5 shadow-xs"
                    >
                      <CheckCircle className="w-4 h-4" />
                      <span>تایید و فعال‌سازی</span>
                    </button>

                    <button
                      onClick={() => setRejectModalOrder(order)}
                      className="bg-stone-200 hover:bg-red-100 text-red-800 font-semibold px-3 py-2 rounded-xl transition flex items-center gap-1"
                    >
                      <XCircle className="w-4 h-4" />
                      <span>رد فیش</span>
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      )}

      {/* TAB 2: MEMBERSHIP PLANS CONFIGURATION */}
      {activeTab === 'plans' && (
        <div className="bg-white rounded-3xl p-5 border border-stone-200/90 shadow-xs space-y-4">
          <div className="flex items-center justify-between">
            <div>
              <h2 className="text-sm sm:text-base font-bold text-amber-950">مدیریت پلن‌های عضویت</h2>
              <p className="text-stone-500 text-[11px] mt-0.5">
                تغییر قیمت، عنوان، دسترسی و فعال/غیرفعال‌سازی پلن‌ها
              </p>
            </div>
          </div>

          <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
            {plans.map((p) => (
              <div
                key={p.id}
                className={`p-4 rounded-2xl border transition flex flex-col justify-between ${
                  p.isActive ? 'bg-white border-stone-200' : 'bg-stone-50 border-stone-200 opacity-60'
                }`}
              >
                <div className="space-y-2">
                  <div className="flex items-center justify-between">
                    <h3 className="font-bold text-sm text-stone-900">{p.title}</h3>
                    <span className="text-[10px] font-mono bg-stone-100 px-2 py-0.5 rounded">
                      سطح: {p.tier}
                    </span>
                  </div>

                  <p className="text-stone-500 text-[11px]">{p.description}</p>

                  <div className="flex items-center justify-between py-2 border-y border-stone-100 font-mono">
                    <span className="text-stone-400">شهریه:</span>
                    <strong className="text-amber-900 text-sm">
                      {p.priceToman.toLocaleString('fa-IR')} تومان ({p.durationLabel})
                    </strong>
                  </div>

                  <div className="space-y-1">
                    <p className="text-[10px] font-semibold text-stone-400">ویژگی‌ها:</p>
                    <ul className="list-disc list-inside text-[11px] text-stone-600 space-y-0.5">
                      {p.features.map((f, i) => (
                        <li key={i}>{f}</li>
                      ))}
                    </ul>
                  </div>
                </div>

                <div className="pt-4 flex items-center justify-between border-t border-stone-100 mt-3">
                  <button
                    onClick={() =>
                      membershipService
                        .updatePlan(p.id, { isActive: !p.isActive })
                        .then(() => loadData())
                    }
                    className={`text-[11px] font-medium px-2.5 py-1 rounded-lg ${
                      p.isActive ? 'text-amber-800 bg-amber-50' : 'text-stone-600 bg-stone-200'
                    }`}
                  >
                    {p.isActive ? 'فعال (کلیک برای غیرفعال‌سازی)' : 'غیرفعال'}
                  </button>

                  <button
                    onClick={() => setEditingPlan(p)}
                    className="flex items-center gap-1 bg-stone-900 hover:bg-amber-900 text-white px-3 py-1.5 rounded-xl font-semibold transition"
                  >
                    <Edit className="w-3.5 h-3.5" />
                    <span>ویرایش پلن</span>
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* TAB 3: HOMEPAGE SECTIONS CONFIG & REORDER */}
      {activeTab === 'sections' && (
        <div className="bg-white rounded-3xl p-5 border border-stone-200/90 shadow-xs space-y-4">
          <div>
            <h2 className="text-sm sm:text-base font-bold text-amber-950">چیدمان و سکشن‌های صفحه اصلی</h2>
            <p className="text-stone-500 text-[11px] mt-0.5">
              ترتیب نمایش بخش‌ها را با دکمه‌های بالا/پایین تغییر دهید یا بخش‌ها را فعال/غیرفعال کنید.
            </p>
          </div>

          <div className="space-y-2.5">
            {sections.map((sec, idx) => (
              <div
                key={sec.id}
                className="p-3.5 rounded-2xl border border-stone-200 bg-stone-50/60 flex items-center justify-between gap-3"
              >
                <div className="flex items-center gap-3">
                  <span className="w-6 h-6 rounded-lg bg-stone-200 font-mono font-bold text-stone-700 flex items-center justify-center text-xs">
                    {idx + 1}
                  </span>
                  <div>
                    <h3 className="font-bold text-stone-900">{sec.title}</h3>
                    <p className="text-[11px] text-stone-500 font-mono">{sec.key}</p>
                  </div>
                </div>

                <div className="flex items-center gap-2">
                  {/* Reorder Buttons */}
                  <div className="flex items-center gap-1 bg-white border border-stone-200 rounded-xl p-1">
                    <button
                      disabled={idx === 0}
                      onClick={() => handleMoveSection(idx, 'up')}
                      className="p-1 rounded text-stone-500 hover:text-stone-900 disabled:opacity-30"
                      title="انتقال به بالا"
                    >
                      <ArrowUp className="w-3.5 h-3.5" />
                    </button>
                    <button
                      disabled={idx === sections.length - 1}
                      onClick={() => handleMoveSection(idx, 'down')}
                      className="p-1 rounded text-stone-500 hover:text-stone-900 disabled:opacity-30"
                      title="انتقال به پایین"
                    >
                      <ArrowDown className="w-3.5 h-3.5" />
                    </button>
                  </div>

                  {/* Toggle Visibility */}
                  <button
                    onClick={() => handleToggleSection(sec.id, sec.isEnabled)}
                    className={`p-2 rounded-xl border transition ${
                      sec.isEnabled
                        ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                        : 'bg-stone-200 text-stone-500 border-stone-300'
                    }`}
                    title={sec.isEnabled ? 'فعال (کلیک برای مخفی‌سازی)' : 'مخفی'}
                  >
                    {sec.isEnabled ? <Eye className="w-4 h-4" /> : <EyeOff className="w-4 h-4" />}
                  </button>

                  {/* Edit Config */}
                  <button
                    onClick={() => setEditingSection(sec)}
                    className="p-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-stone-700 transition"
                    title="ویرایش متن‌های این بخش"
                  >
                    <Edit className="w-4 h-4" />
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* TAB 4: EDITORIAL WORDPRESS PAGES */}
      {activeTab === 'editorial' && (
        <div className="bg-white rounded-3xl p-5 border border-stone-200/90 shadow-xs space-y-4">
          <div>
            <h2 className="text-sm sm:text-base font-bold text-amber-950">صفحات متنی و اسناد وردپرس</h2>
            <p className="text-stone-500 text-[11px] mt-0.5">
              مدیریت صفحاتی نظیر «درباره ما»، «راهنمای عضویت و تمدید دستی»، و «قوانین»
            </p>
          </div>

          <div className="space-y-3">
            {editorialPages.map((page) => (
              <div
                key={page.slug}
                className="p-4 rounded-2xl border border-stone-200 bg-white flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 shadow-xs"
              >
                <div>
                  <h3 className="font-bold text-stone-900 text-sm">{page.title}</h3>
                  <p className="text-stone-500 text-[11px] mt-1">{page.excerpt}</p>
                  <span className="text-[10px] text-stone-400 mt-1 block">
                    آخرین به‌روزرسانی: {page.lastUpdatedJalali} · پیوند: #/{page.slug}
                  </span>
                </div>

                <div className="flex items-center gap-2">
                  <button
                    onClick={() => onNavigate('editorial', { slug: page.slug })}
                    className="px-3 py-1.5 rounded-xl border border-stone-300 text-stone-700 hover:bg-stone-100 transition"
                  >
                    مشاهده در سایت
                  </button>
                  <button
                    onClick={() => setEditingPage(page)}
                    className="bg-amber-800 hover:bg-amber-900 text-white font-semibold px-3 py-1.5 rounded-xl transition flex items-center gap-1"
                  >
                    <Edit className="w-3.5 h-3.5" />
                    <span>ویرایش محتوا</span>
                  </button>
                </div>
              </div>
            ))}
          </div>
        </div>
      )}

      {/* TAB 5: ALL ORDERS AUDIT LIST */}
      {activeTab === 'orders' && (
        <div className="bg-white rounded-3xl p-5 border border-stone-200/90 shadow-xs space-y-4">
          <h2 className="text-sm sm:text-base font-bold text-amber-950">دفتر کل سفارش‌ها و تراکنش‌ها</h2>

          {/* DEV-16: status / gateway filters + free-text search (buyer name,
              order number, item title) — client-side over the loaded orders. */}
          <div className="flex flex-col md:flex-row items-stretch md:items-center gap-2">
            <div className="relative flex-1">
              <input
                type="text"
                value={orderSearchQuery}
                onChange={(e) => setOrderSearchQuery(e.target.value)}
                placeholder="جستجوی خریدار، شماره سفارش یا عنوان..."
                className="w-full pr-9 pl-3 py-3 bg-stone-50 border border-stone-200 rounded-xl text-xs text-stone-800 placeholder-stone-400 focus:outline-none focus:ring-2 focus:ring-amber-700/30 focus:border-amber-700 transition"
              />
              <Search className="w-4 h-4 text-stone-400 absolute right-3 top-3.5" />
            </div>

            <select
              value={orderStatusFilter}
              onChange={(e) => setOrderStatusFilter(e.target.value as 'all' | OrderStatus)}
              className="bg-stone-50 border border-stone-200 rounded-xl text-xs px-3 py-3 text-stone-800 focus:outline-none focus:border-amber-700"
            >
              <option value="all">همه وضعیت‌ها</option>
              <option value="pending">در انتظار بررسی</option>
              <option value="completed">تایید شده</option>
              <option value="failed">ناموفق</option>
              <option value="cancelled">لغو شده</option>
              <option value="verifying">در حال بررسی</option>
              <option value="processing">در حال پردازش</option>
            </select>

            <select
              value={orderGatewayFilter}
              onChange={(e) => setOrderGatewayFilter(e.target.value as 'all' | PaymentGatewayType)}
              className="bg-stone-50 border border-stone-200 rounded-xl text-xs px-3 py-3 text-stone-800 focus:outline-none focus:border-amber-700"
            >
              <option value="all">همه درگاه‌ها</option>
              <option value="zibal">درگاه زیبال</option>
              <option value="card_to_card">کارت به کارت</option>
            </select>
          </div>

          <p className="text-[11px] text-stone-500">
            نمایش {filteredOrders.length.toLocaleString('fa-IR')} سفارش از {orders.length.toLocaleString('fa-IR')} سفارش ثبت‌شده
          </p>

          {filteredOrders.length === 0 ? (
            <div className="py-10 text-center text-stone-400 space-y-1">
              <Search className="w-8 h-8 mx-auto" />
              <p className="font-bold text-stone-700 text-xs">سفارشی با این فیلترها یافت نشد.</p>
              <p className="text-[11px]">عبارت جستجو یا فیلترها را تغییر دهید.</p>
            </div>
          ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-xs text-right">
              <thead>
                <tr className="border-b border-stone-200 text-stone-500">
                  <th className="py-2.5 font-semibold">شماره سفارش</th>
                  <th className="py-2.5 font-semibold">خریدار</th>
                  <th className="py-2.5 font-semibold">عنوان آیتم</th>
                  <th className="py-2.5 font-semibold">مبلغ (تومان)</th>
                  <th className="py-2.5 font-semibold">درگاه</th>
                  <th className="py-2.5 font-semibold">وضعیت</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-stone-100 text-stone-700">
                {filteredOrders.map((ord) => (
                  <tr key={ord.id}>
                    <td className="py-2.5 font-mono text-stone-900 font-bold">{ord.orderNumber}</td>
                    <td className="py-2.5">
                      {ord.userFullName} <span className="font-mono text-stone-400">({ord.userPhone})</span>
                    </td>
                    <td className="py-2.5">{ord.itemTitle}</td>
                    <td className="py-2.5 font-mono text-amber-900 font-bold">
                      {ord.amountToman.toLocaleString('fa-IR')}
                    </td>
                    <td className="py-2.5">
                      {ord.gateway === 'zibal' ? 'درگاه زیبال' : 'کارت به کارت'}
                    </td>
                    <td className="py-2.5">
                      <span
                        className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${orderStatusBadgeClass(ord.status)}`}
                      >
                        {/* DEV-16: full Persian label map (includes cancelled/
                            verifying/processing instead of a blanket «رد شده») */}
                        {ORDER_STATUS_LABELS[ord.status]}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          )}
        </div>
      )}

      {/* CONFIRM DIALOG */}
      <ConfirmDialog
        isOpen={confirmDialog.isOpen}
        onClose={() => setConfirmDialog((p) => ({ ...p, isOpen: false }))}
        onConfirm={confirmDialog.action}
        title={confirmDialog.title}
        description={confirmDialog.description}
        variant={confirmDialog.variant}
      />

      {/* REJECT RECEIPT MODAL */}
      {rejectModalOrder && (
        <Modal
          isOpen={true}
          onClose={() => setRejectModalOrder(null)}
          title="رد فیش واریزی کارت به کارت"
          subtitle={`سفارش ${rejectModalOrder.orderNumber} - ${rejectModalOrder.userFullName}`}
          maxWidth="sm"
        >
          <div className="space-y-4 text-xs">
            <div>
              <label className="block font-semibold text-stone-800 mb-1">
                علت رد فیش (به کاربر نمایش داده می‌شود) *:
              </label>
              <textarea
                rows={3}
                value={rejectReason}
                onChange={(e) => setRejectReason(e.target.value)}
                className="w-full p-2.5 bg-stone-50 border border-stone-200 rounded-xl focus:outline-none focus:border-red-600"
                required
              />
            </div>

            <div className="flex items-center justify-end gap-2 pt-2 border-t border-stone-100">
              <button
                type="button"
                onClick={() => setRejectModalOrder(null)}
                className="px-3 py-2 text-stone-600 hover:bg-stone-100 rounded-xl"
              >
                انصراف
              </button>
              <button
                type="button"
                onClick={handleConfirmRejectReceipt}
                className="bg-red-700 hover:bg-red-800 text-white font-bold px-4 py-2 rounded-xl transition"
              >
                ثبت رد فیش
              </button>
            </div>
          </div>
        </Modal>
      )}

      {/* EDIT PLAN MODAL */}
      {editingPlan && (
        <Modal
          isOpen={true}
          onClose={() => setEditingPlan(null)}
          title={`ویرایش پلن: ${editingPlan.title}`}
          maxWidth="md"
        >
          <form onSubmit={handleSavePlan} className="space-y-3 text-xs">
            <div>
              <label className="block font-semibold text-stone-700 mb-1">عنوان پلن:</label>
              <input
                type="text"
                value={editingPlan.title}
                onChange={(e) => setEditingPlan({ ...editingPlan, title: e.target.value })}
                className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
                required
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="block font-semibold text-stone-700 mb-1">شهریه (تومان):</label>
                <input
                  type="number"
                  min={0}
                  step={1000}
                  value={Number.isFinite(editingPlan.priceToman) ? editingPlan.priceToman : ''}
                  onChange={(e) => {
                    // BUG-24: normalize Persian digits and reject non-numeric input
                    const normalized = toEnglishDigits(e.target.value);
                    const parsed = Number(normalized);
                    setEditingPlan({ ...editingPlan, priceToman: normalized === '' || isNaN(parsed) ? NaN : parsed });
                  }}
                  className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl font-mono"
                  required
                />
                {(!Number.isFinite(editingPlan.priceToman) || editingPlan.priceToman < 0) && (
                  <p className="text-[10px] text-red-600 mt-1">شهریه باید عددی نامنفی باشد.</p>
                )}
                {Number.isFinite(editingPlan.priceToman) && editingPlan.priceToman >= 0 && (
                  <p className="text-[10px] text-stone-400 mt-1">
                    نمایشی: {formatToman(editingPlan.priceToman)} تومان
                  </p>
                )}
              </div>
              <div>
                <label className="block font-semibold text-stone-700 mb-1">برچسب مدت:</label>
                <input
                  type="text"
                  value={editingPlan.durationLabel}
                  onChange={(e) => setEditingPlan({ ...editingPlan, durationLabel: e.target.value })}
                  className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
                  required
                />
              </div>
            </div>

            <div>
              <label className="block font-semibold text-stone-700 mb-1">توضیحات کوتاه:</label>
              <textarea
                rows={2}
                value={editingPlan.description}
                onChange={(e) => setEditingPlan({ ...editingPlan, description: e.target.value })}
                className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
              />
            </div>

            <button
              type="submit"
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-bold py-2.5 rounded-xl transition"
            >
              ذخیره تغییرات پلن
            </button>
          </form>
        </Modal>
      )}

      {/* EDIT EDITORIAL PAGE MODAL */}
      {editingPage && (
        <Modal
          isOpen={true}
          onClose={() => setEditingPage(null)}
          title={`ویرایش صفحه: ${editingPage.title}`}
          maxWidth="lg"
        >
          <form onSubmit={handleSaveEditorialPage} className="space-y-3 text-xs">
            <div>
              <label className="block font-semibold text-stone-700 mb-1">عنوان صفحه:</label>
              <input
                type="text"
                value={editingPage.title}
                onChange={(e) => setEditingPage({ ...editingPage, title: e.target.value })}
                className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
                required
              />
            </div>

            <div>
              <label className="block font-semibold text-stone-700 mb-1">خلاصه متن:</label>
              <input
                type="text"
                value={editingPage.excerpt}
                onChange={(e) => setEditingPage({ ...editingPage, excerpt: e.target.value })}
                className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
              />
            </div>

            <div>
              <label className="block font-semibold text-stone-700 mb-1">متن کامل صفحه:</label>
              <textarea
                rows={8}
                value={editingPage.content}
                onChange={(e) => setEditingPage({ ...editingPage, content: e.target.value })}
                className="w-full p-2.5 bg-stone-50 border border-stone-200 rounded-xl font-sans leading-relaxed"
                required
              />
            </div>

            <button
              type="submit"
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-bold py-2.5 rounded-xl transition"
            >
              ذخیره صفحه وردپرس
            </button>
          </form>
        </Modal>
      )}

      {/* EDIT HOMEPAGE SECTION CONFIG MODAL */}
      {editingSection && (
        <Modal
          isOpen={true}
          onClose={() => setEditingSection(null)}
          title={`ویرایش بخش: ${editingSection.title}`}
          maxWidth="md"
        >
          <form onSubmit={handleSaveSection} className="space-y-3 text-xs">
            <div>
              <label className="block font-semibold text-stone-700 mb-1">عنوان نمایشی:</label>
              <input
                type="text"
                value={editingSection.title}
                onChange={(e) => setEditingSection({ ...editingSection, title: e.target.value })}
                className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
                required
              />
            </div>

            <div>
              <label className="block font-semibold text-stone-700 mb-1">زیرعنوان:</label>
              <input
                type="text"
                value={editingSection.subtitle || ''}
                onChange={(e) => setEditingSection({ ...editingSection, subtitle: e.target.value })}
                className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
              />
            </div>

            {editingSection.key === 'hero' && (
              <div className="space-y-2 pt-2 border-t border-stone-100">
                <p className="font-bold text-amber-900">تنظیمات متن بنر هیرو:</p>
                <div>
                  <label className="block text-[11px] text-stone-500 mb-0.5">تیتر اصلی (Headline):</label>
                  <input
                    type="text"
                    value={editingSection.configData?.headline || ''}
                    onChange={(e) =>
                      setEditingSection({
                        ...editingSection,
                        configData: { ...editingSection.configData, headline: e.target.value },
                      })
                    }
                    className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
                  />
                </div>
                <div>
                  <label className="block text-[11px] text-stone-500 mb-0.5">توضیح زیرین (Subheadline):</label>
                  <textarea
                    rows={2}
                    value={editingSection.configData?.subheadline || ''}
                    onChange={(e) =>
                      setEditingSection({
                        ...editingSection,
                        configData: { ...editingSection.configData, subheadline: e.target.value },
                      })
                    }
                    className="w-full p-2 bg-stone-50 border border-stone-200 rounded-xl"
                  />
                </div>
              </div>
            )}

            <button
              type="submit"
              className="w-full bg-amber-800 hover:bg-amber-900 text-white font-bold py-2.5 rounded-xl transition"
            >
              ذخیره تغییرات بخش
            </button>
          </form>
        </Modal>
      )}
    </div>
  );
};
