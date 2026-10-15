<?php

use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
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
