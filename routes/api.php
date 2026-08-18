<?php

use App\Http\Controllers\TeacherController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminAcademicController;
use App\Http\Controllers\StudentController;
use Illuminate\Support\Facades\Route;

// ==================== Public Routes ====================

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

// بيانات تعبئة فورم التسجيل (بدون مصادقة، تُستخدم قبل إنشاء الحساب)
Route::get('/stages', [AuthController::class, 'stages']);
Route::get('/stages/{stageId}/subjects', [AuthController::class, 'subjects']);
Route::get('/stages/{stageId}/classes', [AuthController::class, 'classesByStage']);

// ==================== Authenticated Users (كل الأدوار) ====================

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/change-password', [AuthController::class, 'changePassword']);
    Route::get('/profile', [AuthController::class, 'profile']);
    Route::post('/profile/photo', [AuthController::class, 'updateProfilePhoto']);

    Route::get('/teachers/{teacher}/document', [AdminUserController::class, 'downloadTeacherDocument']);
});

// ==================== Admin ====================

Route::middleware(['auth:sanctum', 'active', 'role:admin'])->prefix('admin')->group(function () {

    // إدارة المستخدمين
    Route::get('/pending/students', [AdminUserController::class, 'pendingStudents']);
    Route::get('/pending/teachers', [AdminUserController::class, 'pendingTeachers']);
    Route::get('/pending/supervisors', [AdminUserController::class, 'pendingSupervisors']);
    Route::get('/pending/guardians', [AdminUserController::class, 'pendingGuardians']);
    Route::get('/users/{userId}', [AdminUserController::class, 'userDetails']);

    Route::post('/users/{userId}/approve', [AdminUserController::class, 'approveUser']);
    Route::post('/users/{userId}/reject', [AdminUserController::class, 'rejectUser']);
    Route::get('/dashboard', [AdminUserController::class, 'dashboardStats']);
    Route::get('/teachers', [AdminUserController::class, 'listTeachers']);
    Route::get('/students', [AdminUserController::class, 'listStudents']);
    Route::post('/system/lock', [AdminUserController::class, 'lockRegistration']);
    Route::post('/system/unlock', [AdminUserController::class, 'unlockRegistration']);
    Route::get('/system/status', [AdminUserController::class, 'systemStatus']);

    // تقارير الحضور والعلامات
    Route::get('/students/without-section', [AdminAcademicController::class, 'studentsWithoutSection']);
    Route::get('/reports/students-distribution', [AdminAcademicController::class, 'studentsDistribution']);
    Route::get('/attendance/day', [AdminAcademicController::class, 'dailyAttendance']);
    Route::get('/attendance/student/{student}', [AdminAcademicController::class, 'studentAttendanceHistory']);
    Route::get('/reports/attendance-rate', [AdminAcademicController::class, 'attendanceRateReport']);
    Route::get('/reports/most-absent', [AdminAcademicController::class, 'mostAbsentStudents']);

    Route::get('/grades/statistics', [AdminAcademicController::class, 'gradeStatistics']);
    Route::get('/grades/pending', [AdminAcademicController::class, 'pendingGradeSubmissions']);
    Route::post('/grade-submissions/{id}/approve', [AdminAcademicController::class, 'approveGradeSubmission']);
    Route::post('/grade-submissions/{id}/reject', [AdminAcademicController::class, 'rejectGradeSubmission']);
    Route::get('/students/{student}/report-card', [AdminAcademicController::class, 'studentReportCard']);
});

// ==================== Teacher ====================

Route::middleware(['auth:sanctum', 'active', 'role:teacher'])->group(function () {

    Route::get('/teacher/profile', [TeacherController::class, 'profile']);

    Route::get('/teacher/announcements', [TeacherController::class, 'announcements']);
    Route::get('/teacher/announcements/upcoming', [TeacherController::class, 'upcomingAnnouncements']);
    Route::get('/teacher/announcements/important-dates', [TeacherController::class, 'importantAnnouncementDates']);
    Route::get('/teacher/announcements/calendar-dots', [TeacherController::class, 'announcementCalendarDots']);
    Route::get('/teacher/announcements/month', [TeacherController::class, 'announcementsForMonth']);
    Route::get('/teacher/announcements/day', [TeacherController::class, 'announcementsForDay']);
    Route::get('/teacher/announcements/{id}', [TeacherController::class, 'showAnnouncement']);

    Route::post('/questions', [TeacherController::class, 'storeQuestion']);
    Route::get('/questions/list', [TeacherController::class, 'listQuestions']);
    Route::get('/questions/recent', [TeacherController::class, 'recentQuestions']);
    Route::get('/questions/stats', [TeacherController::class, 'questionStats']);
    Route::get('/questions/{question}', [TeacherController::class, 'showQuestion']);
    Route::put('/questions/update/{question}', [TeacherController::class, 'updateQuestion']);
    Route::delete('/questions/delete/{question}', [TeacherController::class, 'destroyQuestion']);

    Route::get('/teacher/assignments/subjects', [TeacherController::class, 'assignmentSubjects']);
    Route::get('/teacher/assignments/sections/{subjectId}', [TeacherController::class, 'assignmentSections']);
    Route::post('/teacher/assignments/store', [TeacherController::class, 'storeAssignment']);
    Route::get('/teacher/assignments/list', [TeacherController::class, 'listAssignments']);

    Route::post('/teacher/tasks/store', [TeacherController::class, 'storeTask']);
    Route::get('/teacher/tasks/list', [TeacherController::class, 'listTasks']);
    Route::get('/teacher/tasks/upcoming', [TeacherController::class, 'upcomingTasks']);
    Route::get('/teacher/tasks/progress', [TeacherController::class, 'taskProgress']);
    Route::get('/teacher/tasks/by-status', [TeacherController::class, 'tasksByStatus']);
    Route::put('/teacher/tasks/update/{task}', [TeacherController::class, 'updateTask']);
    Route::patch('/teacher/tasks/{task}/status', [TeacherController::class, 'updateTaskStatus']);
    Route::post('/teacher/tasks/{task}/submit', [TeacherController::class, 'submitTask']);
    Route::delete('/teacher/tasks/delete/{task}', [TeacherController::class, 'destroyTask']);
    Route::get('/teacher/tasks/progress/details', [TeacherController::class, 'taskProgressDetails']);

    Route::get('/teacher/schedule/daily', [TeacherController::class, 'dailySchedule']);
    Route::get('/teacher/schedule/weekly', [TeacherController::class, 'weeklySchedule']);
    Route::put('/teacher/schedule/{schedule}/lesson-plan', [TeacherController::class, 'saveLessonPlan']);

    Route::get('/teacher/dashboard', [TeacherController::class, 'dashboard']);

    Route::get('/teacher/sections', [TeacherController::class, 'mySections']);
    Route::post('/teacher/grades', [TeacherController::class, 'storeGrade']);
    Route::get('/teacher/sections/{teacherAssignmentId}/students', [TeacherController::class, 'studentsForGrading']);
    Route::post('/teacher/sections/{teacherAssignmentId}/grades', [TeacherController::class, 'storeBulkGrades']);

    Route::get('/teacher/sections/{teacherAssignmentId}/grade-status', [TeacherController::class, 'sectionGradeStatus']);

    Route::get('/teacher/activity', [TeacherController::class, 'recentActivity']);


});

Route::middleware(['auth:sanctum', 'active', 'role:student'])->group(function () {
    Route::get('/student/dashboard', [StudentController::class, 'dashboard']);
    Route::get('/student/schedule/daily', [StudentController::class, 'dailySchedule']);
    Route::get('/student/schedule/weekly', [StudentController::class, 'weeklySchedule']);
    Route::get('/student/schedule/remainingHours', [StudentController::class, 'remainingHoursToday']);
    Route::get('/student/announcements', [StudentController::class, 'announcements']);
    Route::get('/student/announcements/upcoming', [StudentController::class, 'upcomingAnnouncements']);
    Route::get('/student/announcements/important-dates', [StudentController::class, 'importantAnnouncementDates']);
    Route::get('/student/announcements/calendar-dots', [StudentController::class, 'announcementCalendarDots']);
    Route::get('/student/announcements/month', [StudentController::class, 'announcementsForMonth']);
    Route::get('/student/announcements/day', [StudentController::class, 'announcementsForDay']);
    Route::get('/student/announcements/{id}', [StudentController::class, 'showAnnouncement']);
    Route::get('/student/assignments/with-status', [StudentController::class, 'assignmentsWithStatus']);
    Route::get('/student/assignments/progress', [StudentController::class, 'assignmentProgress']);
    Route::patch('/student/assignments/{assignmentId}/complete', [StudentController::class, 'completeAssignment']);

    Route::get('/student/grades', [StudentController::class, 'grades']);
    Route::get('/student/class-group', [StudentController::class, 'classGroup']);
    Route::get('/student/classmates', [StudentController::class, 'classmates']);

    Route::get('/student/profile-page', [StudentController::class, 'studentProfile']);
    Route::get('/student/attendance-summary', [StudentController::class, 'attendanceSummary']);
    Route::get('/student/guardian-info', [StudentController::class, 'guardianInfo']);
    Route::put('/student/personal-info', [StudentController::class, 'updatePersonalInfo']);
    Route::get('/student/activity', [StudentController::class, 'recentActivity']);

    Route::get('/student/assignments/progress/details', [StudentController::class, 'assignmentProgressDetails']);


});
