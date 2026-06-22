<?php

use App\Http\Controllers\PageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\InstallmentController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\TestimonialController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\FacilityController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Admin\VisitScheduleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\InquiryController;
use App\Http\Controllers\Admin\ChatController as AdminChatController;
use App\Http\Controllers\Admin\TestimonialController as AdminTestimonialController;
use App\Http\Controllers\Admin\AdminReportController;
use App\Http\Controllers\GoogleAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Pages
|--------------------------------------------------------------------------
*/
Route::get('/', [PageController::class, 'home'])->name('home');
Route::get('/project', [PageController::class, 'project'])->name('project');
Route::get('/project/{id}', [PageController::class, 'projectDetail'])->name('project.show');
Route::get('/testimoni', [TestimonialController::class, 'index'])->name('testimoni');
Route::post('/testimoni', [TestimonialController::class, 'store'])->name('testimoni.store');
Route::get('/contact', [PageController::class, 'contact'])->name('contact');
Route::post('/contact', [PageController::class, 'contactSubmit'])->name('contact.submit');

/*
|--------------------------------------------------------------------------
| User Transaction & Auth (Harus Login)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('forgot-password');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:3,60');
});

Route::middleware('auth')->group(function () {
    // User Profile & Dashboard
    Route::get('/dashboard', [AuthController::class, 'dashboard'])->name('dashboard');
    Route::get('/profile', [AuthController::class, 'profile'])->name('profile');
    Route::put('/profile', [AuthController::class, 'updateProfile'])->name('profile.update');
    Route::put('/password', [AuthController::class, 'updatePassword'])->name('password.update');
    Route::delete('/profile', [AuthController::class, 'deleteAccount'])->name('profile.destroy');
    Route::match(['get', 'post'], '/logout', [AuthController::class, 'logout'])->name('logout');

    // Saved / Favorite Properties
    Route::post('/favorite/{propertyId}', [AuthController::class, 'toggleFavorite'])->name('favorite.toggle');
    Route::get('/saved-properties', [AuthController::class, 'savedProperties'])->name('saved.properties');

    // Alur Transaksi Sisi User
    Route::get('/transaction/checkout/{id}', [TransactionController::class, 'checkout'])->name('transaction.checkout');
    Route::get('/transaction/invoice/{id}', [TransactionController::class, 'invoice'])->name('transaction.invoice');
    Route::get('/transaction/download/{id}', [TransactionController::class, 'downloadInvoice'])->name('transaction.download');
    Route::post('/transaction/set-success/{id}', [TransactionController::class, 'setSuccessInstantly'])->name('transaction.set-success');

    // Cicilan (Installment)
    Route::get('/installment/pay/{installmentId}', [InstallmentController::class, 'payInstallment'])->name('installment.pay');
    Route::post('/installment/set-success/{installmentId}', [InstallmentController::class, 'setSuccessInstantly'])->name('installment.set-success');

    // Visit Schedule (User)
    Route::get('/visit-schedule', [AuthController::class, 'visitSchedules'])->name('visit-schedule.index');
    Route::post('/visit-schedule', [AuthController::class, 'storeVisitSchedule'])->name('visit-schedule.store');

    // Chat (User)
    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{id}', [ChatController::class, 'show'])->name('chat.show');
    Route::post('/chat', [ChatController::class, 'store'])->name('chat.store');
    Route::post('/chat/{id}/send', [ChatController::class, 'sendMessage'])->name('chat.send');
    Route::get('/chat/{id}/fetch', [ChatController::class, 'fetchMessages'])->name('chat.fetch');
});

// Midtrans Webhook Callback (tidak pakai CSRF/auth karena dari eksternal)
Route::post('/transaction/callback', [TransactionController::class, 'callback'])->name('transaction.callback');

/*
|--------------------------------------------------------------------------
| Google OAuth
|--------------------------------------------------------------------------
*/
Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('google.redirect');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('google.callback');

/*
|--------------------------------------------------------------------------
| Admin Panel (Prefix: /admin)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    // Dashboard Utama
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Manajemen Properti
    Route::resource('properties', PropertyController::class);
    Route::resource('categories', CategoryController::class);
    Route::resource('facilities', FacilityController::class); // Submenu Fasilitas

    // Tipe Properti (Custom Methods)
    Route::get('/types', [CategoryController::class, 'index'])->name('types.index');
    Route::post('/types', [CategoryController::class, 'storeType'])->name('types.store');
    Route::delete('/types/{id}', [CategoryController::class, 'destroyType'])->name('types.destroy');

    // Di dalam Route::middleware(['auth'])->prefix('admin')...
    Route::get('/visit-schedules', [VisitScheduleController::class, 'index'])->name('visit-schedules.index');
    Route::patch('/visit-schedules/{id}/status', [VisitScheduleController::class, 'updateStatus'])->name('visit-schedules.update');

    // Di dalam Route::middleware(['auth'])->prefix('admin')...
    Route::get('/manajemen-users', [UserController::class, 'index'])->name('manajemenuser.index');
    Route::delete('/manajemen-users/{id}', [UserController::class, 'destroy'])->name('manajemenuser.destroy');

    // Testimonials
    Route::get('/testimonials', [AdminTestimonialController::class, 'index'])->name('testimonials.index');
    Route::get('/testimonials/{id}/edit', [AdminTestimonialController::class, 'edit'])->name('testimonials.edit');
    Route::put('/testimonials/{id}', [AdminTestimonialController::class, 'update'])->name('testimonials.update');
    Route::post('/testimonials/{id}/reply', [AdminTestimonialController::class, 'reply'])->name('testimonials.reply');
    Route::delete('/testimonials/{id}', [AdminTestimonialController::class, 'destroy'])->name('testimonials.destroy');

    // Inquiries
    Route::get('/inquiries', [InquiryController::class, 'index'])->name('inquiries.index');
    Route::get('/inquiries/{id}', [InquiryController::class, 'show'])->name('inquiries.show');
    Route::post('/inquiries/{id}/reply', [InquiryController::class, 'reply'])->name('inquiries.reply');
    Route::delete('/inquiries/{id}', [InquiryController::class, 'destroy'])->name('inquiries.destroy');

    // Chat (Admin)
    Route::get('/chat', [AdminChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{id}', [AdminChatController::class, 'show'])->name('chat.show');
    Route::post('/chat/{id}/send', [AdminChatController::class, 'sendMessage'])->name('chat.send');
    Route::post('/chat/{id}/close', [AdminChatController::class, 'close'])->name('chat.close');
    Route::get('/chat/{id}/fetch', [AdminChatController::class, 'fetchMessages'])->name('chat.fetch');
    Route::get('/chat/unread/count', [AdminChatController::class, 'unreadCount'])->name('chat.unread');

    // Sales & Users (Menu Transaksi Admin dengan Tab & Analitik)
    // 1. Halaman Utama Tabel Transaksi Admin
    Route::get('/transactions', [AdminTransactionController::class, 'index'])->name('transaction.index');

    // 2. Halaman Detail Transaksi Admin
    Route::get('/transactions/{id}', [AdminTransactionController::class, 'show'])->name('transaction.show');

    // 3. Proses Update Status & Tipe Pembayaran (PUT)
    Route::put('/transactions/{id}', [AdminTransactionController::class, 'updateStatus'])->name('transaction.update');

    // 4. Proses Hapus Transaksi (DELETE)
    Route::delete('/transactions/{id}', [AdminTransactionController::class, 'destroy'])->name('transaction.destroy');

    // Laporan Admin
    Route::get('/laporan-admin', [AdminReportController::class, 'index'])->name('laporan-admin.index');
    Route::get('/laporan-admin/pdf', [AdminReportController::class, 'exportPdf'])->name('laporan-admin.pdf');
    Route::post('/laporan-admin/{id}/jenis-kelamin', [AdminReportController::class, 'updateJenisKelamin'])->name('laporan-admin.update-jenis-kelamin');
    Route::post('/laporan-admin/{id}/regenerate', [AdminReportController::class, 'regenerate'])->name('laporan-admin.regenerate');
});
