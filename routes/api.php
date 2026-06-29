<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;


// Public Routes


Route::prefix('auth')->group(function () {

    // Login
    Route::post('/login', [AuthController::class, 'login']);

    // Register
    Route::post('/register/student', [AuthController::class, 'registerStudent']);
    Route::post('/register/teacher', [AuthController::class, 'registerTeacher']);
    Route::post('/register/guardian', [AuthController::class, 'registerGuardian']);
    Route::post('/register/supervisor', [AuthController::class, 'registerSupervisor']);

    // Verification
    Route::post('/verify-account', [AuthController::class, 'verifyAccount']);
    Route::post('/resend-code', [AuthController::class, 'resendVerificationCode']);

    // Password
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [AuthController::class, 'resetPassword']);
});

// Authenticated Users

Route::middleware([
    'auth:sanctum' ,
    'active'
])->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/change-password', [AuthController::class, 'changePassword']);

    Route::get('/profile', [AuthController::class, 'profile']);
});



//admin
Route::middleware([
    'auth:sanctum',
    'active',
    'role:admin'
])->prefix('admin')->group(function () {


    Route::get('/pending/students', [AdminController::class, 'pendingStudents']);
    Route::get('/pending/teachers', [AdminController::class, 'pendingTeachers']);
    Route::get('/pending/supervisors', [AdminController::class, 'pendingSupervisors']);
    Route::get('/pending/guardians', [AdminController::class, 'pendingGuardians']);


    Route::get('/users/{userId}', [AdminController::class, 'userDetails']);

    Route::post('/users/{userId}/approve', [AdminController::class, 'approveUser']);

    Route::post('/users/{userId}/reject', [AdminController::class, 'rejectUser']);


    Route::get('/dashboard', [AdminController::class, 'dashboardStats']);


    Route::get('/teachers', [AdminController::class, 'listTeachers']);

    Route::get('/students', [AdminController::class, 'listStudents']);

});
