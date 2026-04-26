<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BiometricAuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - نظام إدارة المدرسة
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    
    // ==========================================
    // 1. المسارات العامة (PUBLIC ROUTES)
    // ==========================================
    
    // تسجيل الدخول
    Route::post('/login', [AuthController::class, 'login']);
    
    // تسجيل طالب جديد
    Route::post('/register/student', [AuthController::class, 'registerStudent']);
    
    // تسجيل معلم جديد
    Route::post('/register/teacher', [AuthController::class, 'registerTeacher']);
    
    // تفعيل البريد الإلكتروني
    Route::post('/verify-account', [AuthController::class, 'verifyAccount']);
    
    // إعادة إرسال كود التفعيل
    Route::post('/resend-verification', [AuthController::class, 'resendVerificationCode']);
    
    // نسيت كلمة المرور
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    
    // التحقق من رمز إعادة التعيين
    Route::get('/password/reset/{token}', [AuthController::class, 'verifyResetToken']);
    
    // إعادة تعيين كلمة المرور
    Route::post('/password/reset', [AuthController::class, 'resetPassword']);
    
    // فحص قوة كلمة المرور
    Route::post('/check-password-strength', [AuthController::class, 'checkPasswordStrength']);
    
    // ==========================================
    // 2. مسارات البصمة العامة (PUBLIC BIOMETRIC)
    // ==========================================
    Route::prefix('biometric')->group(function () {
        // طلب الدخول بالبصمة
        Route::post('/login-options', [BiometricAuthController::class, 'loginOptions']);
        
        // تأكيد الدخول بالبصمة
        Route::post('/login-confirm', [BiometricAuthController::class, 'loginConfirm']);
    });
     
    // ==========================================
    // 3. المسارات المحمية (PROTECTED ROUTES)
    // ==========================================
    Route::middleware(['auth:sanctum', 'force.logout'])->group(function () {
        
        // الملف الشخصي
        Route::get('/profile', [AuthController::class, 'profile']);
        
        // تغيير كلمة المرور
        Route::post('/change-password', [AuthController::class, 'changePassword']);
        
        // تسجيل الخروج
        Route::post('/logout', [AuthController::class, 'logout']);
        
        // الأجهزة النشطة
        Route::get('/active-devices', [AuthController::class, 'activeDevices']);
        
        // إلغاء جهاز محدد
        Route::delete('/devices/{tokenId}', [AuthController::class, 'revokeDevice']);
        
        // سجل محاولات الدخول
        Route::get('/login-history', [AuthController::class, 'loginHistory']);
        
        // ==========================================
        // 4. مسارات البصمة المحمية
        // ==========================================
        Route::prefix('biometric')->group(function () {
            // طلب تسجيل بصمة جديدة
            Route::post('/register-options', [BiometricAuthController::class, 'registerOptions']);
            
            // تأكيد تسجيل البصمة
            Route::post('/register-confirm', [BiometricAuthController::class, 'registerConfirm']);
            
            // عرض البصمات المسجلة
            Route::get('/credentials', [BiometricAuthController::class, 'credentials']);
            
            // حذف بصمة
            Route::delete('/credentials/{credentialId}', [BiometricAuthController::class, 'deleteCredential']);
        });
       
        // ==========================================
        // 5. مسارات المدير (ADMIN ROUTES)
        // ==========================================
        Route::middleware(['verified', 'role:admin'])->prefix('admin')->group(function () {
            // عرض الطلبات المعلقة
            Route::get('/pending-registrations', [AdminController::class, 'pendingRegistrations']);
            
            // الموافقة على مستخدم
            Route::post('/approve-user/{userId}', [AdminController::class, 'approveUser']);
            
            // رفض مستخدم
            Route::delete('/reject-user/{userId}', [AdminController::class, 'rejectUser']);
            
            // عرض تفاصيل مستخدم
            Route::get('/user/{userId}', [AdminController::class, 'userDetails']);
            
            // قائمة المعلمين
            Route::get('/teachers', [AdminController::class, 'listTeachers']);
            
            // قائمة الطلاب
            Route::get('/students', [AdminController::class, 'listStudents']);
            
            // إحصائيات لوحة التحكم
            Route::get('/dashboard-stats', [AdminController::class, 'dashboardStats']);
        });
    });
});

/*
|--------------------------------------------------------------------------
| Routes Summary
|--------------------------------------------------------------------------
|
| PUBLIC (بدون توثيق):
|   POST   /api/v1/login
|   POST   /api/v1/register/student
|   POST   /api/v1/register/teacher
|   POST   /api/v1/verify-account
|   POST   /api/v1/resend-verification
|   POST   /api/v1/forgot-password
|   GET    /api/v1/password/reset/{token}
|   POST   /api/v1/password/reset
|   POST   /api/v1/check-password-strength
|   POST   /api/v1/biometric/login-options
|   POST   /api/v1/biometric/login-confirm
|
| PROTECTED (يحتاج توثيق):
|   GET    /api/v1/profile
|   POST   /api/v1/change-password
|   POST   /api/v1/logout
|   GET    /api/v1/active-devices
|   DELETE /api/v1/devices/{tokenId}
|   GET    /api/v1/login-history
|   POST   /api/v1/biometric/register-options
|   POST   /api/v1/biometric/register-confirm
|   GET    /api/v1/biometric/credentials
|   DELETE /api/v1/biometric/credentials/{credentialId}
|
| ADMIN (يحتاج صلاحية admin):
|   GET    /api/v1/admin/pending-registrations
|   POST   /api/v1/admin/approve-user/{userId}
|   DELETE /api/v1/admin/reject-user/{userId}
|   GET    /api/v1/admin/user/{userId}
|   GET    /api/v1/admin/teachers
|   GET    /api/v1/admin/students
|   GET    /api/v1/admin/dashboard-stats
|
|--------------------------------------------------------------------------
*/