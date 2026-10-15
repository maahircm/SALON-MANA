<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\BookingSettingsController;
use App\Http\Controllers\CashDrawerController;
use App\Http\Controllers\CommissionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\EmployeeScheduleController;
use App\Http\Controllers\ExpenseCategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GeneralSettingsController;
use App\Http\Controllers\HrSettingsController;
use App\Http\Controllers\InventorySettingsController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\LoyaltyController;
use App\Http\Controllers\MembershipController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationLogController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\PosSettingsController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\ServiceAddonController;
use App\Http\Controllers\ServiceCategoryController;
use App\Http\Controllers\ServiceConsumableController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServicePackageController;
use App\Http\Controllers\SkillController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierReturnController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// ---- Guests ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->middleware('throttle:5,1')->name('password.update');
});
Route::get('/account-recovery', [PasswordResetController::class, 'recoveryHelp'])->name('recovery.help');
Route::get('/two-factor-challenge', [LoginController::class, 'challenge'])->middleware('guest')->name('two-factor.challenge');
Route::post('/two-factor-challenge', [LoginController::class, 'verifyChallenge'])->middleware(['guest', 'throttle:10,1']);
Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

// ---- Authenticated ----
Route::middleware(['auth', 'active', 'password.fresh'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('/profile/password', [ProfileController::class, 'password'])->name('profile.password');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:sensitive')->name('profile.password.update');
    Route::post('/profile/two-factor', [ProfileController::class, 'enableTwoFactor'])->middleware('throttle:sensitive')->name('profile.two-factor.enable');
    Route::post('/profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->middleware('throttle:sensitive')->name('profile.two-factor.confirm');
    Route::delete('/profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->middleware('throttle:sensitive')->name('profile.two-factor.disable');

    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
    Route::resource('roles', RoleController::class)->except(['show']);

    Route::get('/audit-log', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('/audit-log/{audit}', [AuditLogController::class, 'show'])->name('audit.show');
});

// ---- Phase 2: core salon operations ----
Route::middleware(['auth', 'active', 'password.fresh'])->group(function () {
    // Customers
    Route::get('customers/search', [CustomerController::class, 'search'])->name('customers.search');
    Route::get('customers/export', [CustomerController::class, 'export'])->middleware('throttle:sensitive')->name('customers.export');
    Route::post('customers/{id}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
    Route::post('customers/{id}/anonymize', [CustomerController::class, 'anonymize'])->middleware('throttle:sensitive')->name('customers.anonymize');
    Route::post('customers/{customer}/preferences', [CustomerController::class, 'storePreference'])->name('customers.preferences.store');
    Route::delete('customers/{customer}/preferences/{preference}', [CustomerController::class, 'destroyPreference'])->name('customers.preferences.destroy');
    Route::resource('customers', CustomerController::class)->withTrashed(['show']);

    // Services & pricing
    Route::get('services/categories', [ServiceCategoryController::class, 'index'])->name('categories.index');
    Route::post('services/categories', [ServiceCategoryController::class, 'store'])->name('categories.store');
    Route::put('services/categories/{category}', [ServiceCategoryController::class, 'update'])->name('categories.update');
    Route::delete('services/categories/{category}', [ServiceCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::resource('services/packages', ServicePackageController::class)->except(['show'])->parameters(['packages' => 'package'])->names('packages');
    Route::post('services/{service}/addons', [ServiceAddonController::class, 'store'])->name('services.addons.store');
    Route::delete('services/{service}/addons/{addon}', [ServiceAddonController::class, 'destroy'])->name('services.addons.destroy');
    Route::resource('services', ServiceController::class);

    // Employees, skills, scheduling
    Route::get('employees/skills', [SkillController::class, 'index'])->name('skills.index');
    Route::post('employees/skills', [SkillController::class, 'store'])->name('skills.store');
    Route::delete('employees/skills/{skill}', [SkillController::class, 'destroy'])->name('skills.destroy');
    Route::get('schedules', [EmployeeScheduleController::class, 'overview'])->name('schedules.index');
    Route::get('employees/{employee}/schedule', [EmployeeScheduleController::class, 'edit'])->name('employees.schedule');
    Route::put('employees/{employee}/schedule', [EmployeeScheduleController::class, 'update'])->name('employees.schedule.update');
    Route::post('employees/{employee}/shifts', [EmployeeScheduleController::class, 'storeShift'])->name('employees.shifts.store');
    Route::delete('employees/{employee}/shifts/{shift}', [EmployeeScheduleController::class, 'destroyShift'])->name('employees.shifts.destroy');
    Route::resource('employees', EmployeeController::class)->except(['destroy']);

    // Appointments
    Route::get('appointments/calendar', [AppointmentController::class, 'calendar'])->name('appointments.calendar');
    Route::get('appointments/list', [AppointmentController::class, 'list'])->name('appointments.list');
    Route::get('appointments/events', [AppointmentController::class, 'events'])->name('appointments.events');
    Route::get('appointments/availability', [AppointmentController::class, 'availability'])->name('appointments.availability');
    Route::post('appointments/{appointment}/status', [AppointmentController::class, 'transition'])->name('appointments.transition');
    Route::resource('appointments', AppointmentController::class)->except(['index', 'destroy']);
    Route::get('rooms', [RoomController::class, 'index'])->name('rooms.index');
    Route::post('rooms', [RoomController::class, 'store'])->name('rooms.store');
    Route::put('rooms/{room}', [RoomController::class, 'update'])->name('rooms.update');
    Route::delete('rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');

    // Settings (booking policy & hours)
    Route::get('settings/booking', [BookingSettingsController::class, 'edit'])->name('settings.booking');
    Route::put('settings/booking', [BookingSettingsController::class, 'update'])->name('settings.booking.update');
});

// ---- Phase 3: POS and finance ----
Route::middleware(['auth', 'active', 'password.fresh'])->group(function () {
    Route::get('pos', [PosController::class, 'index'])->name('pos.index');
    Route::post('pos', [PosController::class, 'store'])->middleware('throttle:60,1')->name('pos.store');
    Route::post('pos/preview', [PosController::class, 'preview'])->middleware('throttle:240,1')->name('pos.preview');
    Route::delete('pos/drafts/{invoice}', [PosController::class, 'destroyDraft'])->name('pos.drafts.destroy');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/receipt', [InvoiceController::class, 'receipt'])->name('invoices.receipt');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'storePayment'])->middleware('throttle:60,1')->name('invoices.payments.store');
    Route::post('invoices/{invoice}/refunds', [InvoiceController::class, 'storeRefund'])->middleware('throttle:sensitive')->name('invoices.refunds.store');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('throttle:sensitive')->name('invoices.void');
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

    Route::get('cash', [CashDrawerController::class, 'index'])->name('cash.index');
    Route::post('cash/open', [CashDrawerController::class, 'open'])->name('cash.open');
    Route::get('cash/daily', [CashDrawerController::class, 'daily'])->name('cash.daily');
    Route::get('cash/drawers', [CashDrawerController::class, 'drawers'])->name('cash.drawers');
    Route::post('cash/drawers', [CashDrawerController::class, 'storeDrawer'])->name('cash.drawers.store');
    Route::post('cash/drawers/{drawer}/toggle', [CashDrawerController::class, 'toggleDrawer'])->name('cash.drawers.toggle');
    Route::get('cash/sessions/{session}', [CashDrawerController::class, 'show'])->name('cash.show');
    Route::post('cash/sessions/{session}/movements', [CashDrawerController::class, 'movement'])->name('cash.movement');
    Route::post('cash/sessions/{session}/close', [CashDrawerController::class, 'close'])->middleware('throttle:sensitive')->name('cash.close');

    Route::get('settings/pos', [PosSettingsController::class, 'edit'])->name('settings.pos');
    Route::put('settings/pos', [PosSettingsController::class, 'update'])->name('settings.pos.update');
});

// ---- Phase 4: inventory and procurement ----
Route::middleware(['auth', 'active', 'password.fresh'])->group(function () {
    Route::get('inventory/categories', [ProductCategoryController::class, 'index'])->name('product-categories.index');
    Route::post('inventory/categories', [ProductCategoryController::class, 'store'])->name('product-categories.store');
    Route::put('inventory/categories/{productCategory}', [ProductCategoryController::class, 'update'])->name('product-categories.update');
    Route::delete('inventory/categories/{productCategory}', [ProductCategoryController::class, 'destroy'])->name('product-categories.destroy');
    Route::resource('inventory/products', ProductController::class)->names('products');
    Route::resource('inventory/suppliers', SupplierController::class)->except(['destroy']);

    Route::post('inventory/purchase-orders/{order}/place', [PurchaseOrderController::class, 'place'])->name('purchase-orders.place');
    Route::post('inventory/purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->name('purchase-orders.cancel');
    Route::post('inventory/purchase-orders/{order}/close', [PurchaseOrderController::class, 'close'])->name('purchase-orders.close');
    Route::post('inventory/purchase-orders/{order}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
    Route::resource('inventory/purchase-orders', PurchaseOrderController::class)->except(['destroy'])->parameters(['purchase-orders' => 'order'])->names('purchase-orders');

    Route::get('inventory/movements', [StockMovementController::class, 'index'])->name('movements.index');
    Route::get('inventory/valuation', [StockMovementController::class, 'valuation'])->name('inventory.valuation');
    Route::get('inventory/adjustments', [StockAdjustmentController::class, 'index'])->name('adjustments.index');
    Route::post('inventory/adjustments', [StockAdjustmentController::class, 'store'])->middleware('throttle:sensitive')->name('adjustments.store');
    Route::get('inventory/returns', [SupplierReturnController::class, 'index'])->name('supplier-returns.index');
    Route::post('inventory/returns', [SupplierReturnController::class, 'store'])->middleware('throttle:sensitive')->name('supplier-returns.store');

    Route::post('services/{service}/consumables', [ServiceConsumableController::class, 'store'])->name('services.consumables.store');
    Route::delete('services/{service}/consumables/{consumable}', [ServiceConsumableController::class, 'destroy'])->name('services.consumables.destroy');

    Route::get('settings/inventory', [InventorySettingsController::class, 'edit'])->name('settings.inventory');
    Route::put('settings/inventory', [InventorySettingsController::class, 'update'])->name('settings.inventory.update');
});

// ---- Phase 5: HR and CRM extensions ----
Route::middleware(['auth', 'active', 'password.fresh'])->group(function () {
    Route::get('attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('attendance.summary');
    Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn'])->middleware('throttle:sensitive')->name('attendance.clock-in');
    Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut'])->middleware('throttle:sensitive')->name('attendance.clock-out');
    Route::post('attendance', [AttendanceController::class, 'store'])->name('attendance.store');

    Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveRequestController::class, 'store'])->name('leave.store');
    Route::post('leave/{leave}/approve', [LeaveRequestController::class, 'approve'])->name('leave.approve');
    Route::post('leave/{leave}/reject', [LeaveRequestController::class, 'reject'])->name('leave.reject');
    Route::post('leave/{leave}/cancel', [LeaveRequestController::class, 'cancel'])->name('leave.cancel');

    Route::get('commissions', [CommissionController::class, 'index'])->name('commissions.index');
    Route::get('commissions/export', [CommissionController::class, 'export'])->middleware('throttle:sensitive')->name('commissions.export');
    Route::post('commissions/adjustments', [CommissionController::class, 'adjust'])->middleware('throttle:sensitive')->name('commissions.adjust');
    Route::get('my-commissions', [CommissionController::class, 'mine'])->name('commissions.mine');

    Route::get('loyalty', [LoyaltyController::class, 'index'])->name('loyalty.index');
    Route::get('loyalty/settings', [LoyaltyController::class, 'settings'])->name('loyalty.settings');
    Route::put('loyalty/settings', [LoyaltyController::class, 'updateSettings'])->name('loyalty.settings.update');
    Route::get('loyalty/customers/{customer}', [LoyaltyController::class, 'show'])->name('loyalty.show');
    Route::post('loyalty/customers/{customer}/adjust', [LoyaltyController::class, 'adjust'])->middleware('throttle:sensitive')->name('loyalty.adjust');

    Route::get('memberships', [MembershipController::class, 'index'])->name('memberships.index');
    Route::post('memberships/plans', [MembershipController::class, 'storePlan'])->name('memberships.plans.store');
    Route::put('memberships/plans/{plan}', [MembershipController::class, 'updatePlan'])->name('memberships.plans.update');
    Route::post('memberships/{membership}/cancel', [MembershipController::class, 'cancel'])->name('memberships.cancel');

    Route::post('promotions/{promotion}/coupons', [PromotionController::class, 'storeCoupon'])->name('promotions.coupons.store');
    Route::post('coupons/{coupon}/toggle', [PromotionController::class, 'toggleCoupon'])->name('coupons.toggle');
    Route::resource('promotions', PromotionController::class)->except(['destroy']);

    Route::get('settings/hr', [HrSettingsController::class, 'edit'])->name('settings.hr');
    Route::put('settings/hr', [HrSettingsController::class, 'update'])->name('settings.hr.update');

    Route::get('expenses/categories', [ExpenseCategoryController::class, 'index'])->name('expense-categories.index');
    Route::post('expenses/categories', [ExpenseCategoryController::class, 'store'])->name('expense-categories.store');
    Route::put('expenses/categories/{category}', [ExpenseCategoryController::class, 'update'])->name('expense-categories.update');
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->middleware('throttle:sensitive')->name('expenses.receipt');
    Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
    Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
    Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');
    Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
    Route::resource('expenses', ExpenseController::class)->except(['destroy']);

    Route::get('finance', [FinanceController::class, 'summary'])->name('finance.summary');
    Route::get('finance/export', [FinanceController::class, 'export'])->middleware('throttle:sensitive')->name('finance.export');

    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/{report}/export', [ReportController::class, 'export'])->middleware('throttle:sensitive')->name('reports.export');
    Route::get('reports/{report}', [ReportController::class, 'show'])->name('reports.show');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    Route::get('notification-logs', [NotificationLogController::class, 'index'])->name('notification-logs.index');
    Route::post('notification-logs/{log}/retry', [NotificationLogController::class, 'retry'])->middleware('throttle:sensitive')->name('notification-logs.retry');
    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('notification-settings.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('notification-settings.update');
    Route::get('settings/general', [GeneralSettingsController::class, 'edit'])->name('settings.general');
    Route::put('settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');
    Route::delete('settings/general/logo', [GeneralSettingsController::class, 'removeLogo'])->name('settings.general.logo.remove');
});
