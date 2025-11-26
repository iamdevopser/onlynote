<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\backend\AdminController;
use App\Http\Controllers\backend\InstructorController;
use App\Http\Controllers\backend\UserController;
use App\Http\Controllers\frontend\FrontendDashboardController;

// Public Routes
Route::get('/', [FrontendDashboardController::class, 'home'])->name('home');
Route::get('/course/{slug}', [FrontendDashboardController::class, 'view'])->name('course.view');

// Authentication Routes
Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
Route::post('/login', [AuthenticatedSessionController::class, 'store']);
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
Route::post('/register', [RegisteredUserController::class, 'store']);

// Admin Routes
Route::prefix('admin')->group(function () {
    Route::get('/login', [AdminController::class, 'login'])->name('admin.login');
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('admin.dashboard')->middleware('auth');
    Route::post('/logout', [AdminController::class, 'destroy'])->name('admin.logout');
});

// Instructor Routes
Route::prefix('instructor')->group(function () {
    Route::get('/login', [InstructorController::class, 'login'])->name('instructor.login');
    Route::get('/dashboard', [InstructorController::class, 'dashboard'])->name('instructor.dashboard')->middleware('auth');
    Route::post('/logout', [InstructorController::class, 'destroy'])->name('instructor.logout');
});

// User Routes
Route::prefix('user')->group(function () {
    Route::get('/dashboard', [UserController::class, 'dashboard'])->name('user.dashboard')->middleware('auth');
    Route::post('/logout', [UserController::class, 'destroy'])->name('user.logout');
});
