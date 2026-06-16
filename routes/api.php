<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\BiometricAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {


    // Public Routes

    // تسجيل الدخول
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1');

    // تسجيل طالب جديد
    Route::post('/register/student', [AuthController::class, 'registerStudent'])
        ->middleware('throttle:3,10');

    // تسجيل معلم جديد
    Route::post('/register/teacher', [AuthController::class, 'registerTeacher'])
        ->middleware('throttle:3,10');

    // تفعيل الحساب
    Route::post('/verify-account', [AuthController::class, 'verifyAccount'])
        ->middleware('throttle:10,1');

    // إعادة إرسال كود التفعيل
    Route::post('/resend-verification', [AuthController::class, 'resendVerificationCode'])
        ->middleware('throttle:3,10');

    // نسيت كلمة المرور
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:3,10');

    // إعادة تعيين كلمة المرور
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:5,10');


    // Biometric Login

    Route::prefix('biometric')->group(function () {

        // طلب الدخول بالبصمة
        Route::post('/login-options', [BiometricAuthController::class, 'loginOptions'])
            ->middleware('throttle:10,1');

        // تأكيد الدخول بالبصمة
        Route::post('/login-confirm', [BiometricAuthController::class, 'loginConfirm'])
            ->middleware('throttle:10,1');
    });


    // Authenticated Routes

    Route::middleware([
        'auth:sanctum',
        'verified',
        'active'
    ])->group(function () {

        // الملف الشخصي
        Route::get('/profile', [AuthController::class, 'profile']);

        // تغيير كلمة المرور
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // تسجيل الخروج
        Route::post('/logout', [AuthController::class, 'logout']);


        // Biometric Management

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


        // Admin Routes


        Route::middleware('role:admin')
            ->prefix('admin')
            ->group(function () {

                // الطلبات المعلقة
                Route::get('/pending-registrations', [AdminController::class, 'pendingRegistrations']);

                // الموافقة على مستخدم
                Route::post('/approve-user/{userId}', [AdminController::class, 'approveUser']);
                // رفض مستخدم
                Route::delete('/reject-user/{userId}', [AdminController::class, 'rejectUser']);

                // تفاصيل مستخدم
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
