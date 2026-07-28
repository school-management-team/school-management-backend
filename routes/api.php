<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;


// Public Routes


// routes/api.php
Route::prefix('auth')->group(function () {

    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);

    });

    Route::middleware(['throttle:3,1', 'registration.open'])->group(function () {
        Route::post('/register/student', [AuthController::class, 'registerStudent']);
        Route::post('/register/teacher', [AuthController::class, 'registerTeacher']);
        Route::post('/register/guardian', [AuthController::class, 'registerGuardian']);
        Route::post('/register/supervisor', [AuthController::class, 'registerSupervisor']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
    });

    Route::middleware('throttle:5,1')->group(function () {
        Route::post('/verify-account', [AuthController::class, 'verifyAccount']);
        Route::post('/reset-password', [AuthController::class, 'resetPassword']);
        Route::post('/resend-code', [AuthController::class, 'resendVerificationCode']);
    });
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


Route::middleware('auth:sanctum')->group(function () {
    Route::get('/teachers/{teacher}/document', [AdminController::class, 'downloadTeacherDocument']);
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

    // عرض العلامات المعلقة لشعبة
    Route::get('/grades/pending-section', [AdminController::class, 'pendingSectionGrades']);

    // اعتماد علامات شعبة كاملة
    Route::post('/grades/section/approve', [AdminController::class, 'approveSectionGrades']);

    // رفض علامات شعبة كاملة
    Route::post('/grades/section/reject', [AdminController::class, 'rejectSectionGrades']);

    // اعتماد علامة فردية
    Route::post('/grades/{id}/approve', [AdminController::class, 'approveSingleGrade']);

    // رفض علامة فردية
    Route::post('/grades/{id}/reject', [AdminController::class, 'rejectSingleGrade']);

    // إعادة فتح علامة مرفوضة
    Route::post('/grades/{id}/reopen', [AdminController::class, 'reopenGrade']);

    // إحصائيات العلامات
    Route::get('/grades/statistics', [AdminController::class, 'gradeStatistics']);



    Route::get('/users/{userId}', [AdminController::class, 'userDetails']);

    Route::post('/users/{userId}/approve', [AdminController::class, 'approveUser']);

    Route::post('/users/{userId}/reject', [AdminController::class, 'rejectUser']);


    Route::get('/dashboard', [AdminController::class, 'dashboardStats']);


    Route::get('/teachers', [AdminController::class, 'listTeachers']);

    Route::get('/students', [AdminController::class, 'listStudents']);

    Route::post('/system/lock', [AdminController::class, 'lockRegistration']);
    Route::post('/system/unlock', [AdminController::class, 'unlockRegistration']);
    Route::get('/system/status', [AdminController::class, 'systemStatus']);

    Route::get('/students/without-section', [AdminController::class, 'studentsWithoutSection']);
    Route::get('/reports/students-distribution', [AdminController::class, 'studentsDistribution']);
    Route::get('/attendance/day', [AdminController::class, 'dailyAttendance']);

    Route::get('/attendance/student/{student}', [AdminController::class, 'studentAttendanceHistory']);
    Route::get('/reports/attendance-rate', [AdminController::class, 'attendanceRateReport']);
    Route::get('/reports/most-absent', [AdminController::class, 'mostAbsentStudents']);

    Route::get('/grades/pending', [AdminController::class, 'pendingSectionGrades']);
    Route::post('/grades/section/approve', [AdminController::class, 'approveSectionGrades']);
    Route::post('/grades/section/reject', [AdminController::class, 'rejectSectionGrades']);
    Route::get('/students/{student}/report-card', [AdminController::class, 'studentReportCard']);

});
