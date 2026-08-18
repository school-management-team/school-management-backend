<?php

use App\Http\Controllers\TeacherController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminAcademicController;
use App\Http\Controllers\ClassController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StudentSectionController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentFeeController;
use App\Http\Controllers\StudentReportController;
use App\Http\Controllers\SupervisorAnnouncementController;
use App\Http\Controllers\SubstitutionController;
use App\Http\Controllers\SupervisorAttendanceController;
use App\Http\Controllers\TeacherAssignmentController;
use App\Http\Controllers\WeeklyScheduleController;
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
    Route::get('/teacher/announcements/calendar-dots', [TeacherController::class, 'announcementCalendarDots']);
    Route::get('/teacher/announcements/{id}', [TeacherController::class, 'showAnnouncement']);

    Route::post('/questions', [TeacherController::class, 'storeQuestion']);
    Route::get('/questions/list', [TeacherController::class, 'listQuestions']);
    Route::get('/questions/recent', [TeacherController::class, 'recentQuestions']);
    Route::get('/questions/stats', [TeacherController::class, 'questionStats']);
    Route::get('/questions/{question}', [TeacherController::class, 'showQuestion']);
    Route::put('/questions/{question}', [TeacherController::class, 'updateQuestion']);
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
    Route::patch('/teacher/tasks/{task}/submit', [TeacherController::class, 'submitTask']);
    Route::delete('/teacher/tasks/{task}', [TeacherController::class, 'destroyTask']);

    Route::get('/teacher/schedule/daily', [TeacherController::class, 'dailySchedule']);
    Route::get('/teacher/schedule/weekly', [TeacherController::class, 'weeklySchedule']);
    Route::put('/teacher/schedule/{schedule}/lesson-plan', [TeacherController::class, 'saveLessonPlan']);

    Route::get('/teacher/dashboard', [TeacherController::class, 'dashboard']);

    Route::get('/teacher/sections', [TeacherController::class, 'mySections']);
    Route::post('/teacher/grades', [TeacherController::class, 'storeGrade']);
    Route::get('/teacher/sections/{teacherAssignmentId}/students', [TeacherController::class, 'studentsForGrading']);
    Route::post('/teacher/sections/{teacherAssignmentId}/grades', [TeacherController::class, 'storeBulkGrades']);
    Route::get('/teacher/sections/{teacherAssignmentId}/grade-status', [TeacherController::class, 'sectionGradeStatus']);

    });
//=================superivsor=========================

Route::middleware(['auth:sanctum', 'active', 'role:supervisor,admin'])->prefix('supervisor')->group(function () {

    Route::get('/classes', [ClassController::class, 'index']);
    Route::post('/classes', [ClassController::class, 'store']);
    Route::put('/classes/{schoolClass}', [ClassController::class, 'update']);

    Route::get('/sections', [SectionController::class, 'index']);
    Route::post('/sections', [SectionController::class, 'store']);
    Route::put('/sections/{section}', [SectionController::class, 'update']);

    Route::post('/students/assign-sections', [StudentSectionController::class, 'assign']);
    Route::get('/students/sections-overview', [StudentSectionController::class, 'sectionsOverview']);
    Route::post('/students/transfer-section', [StudentSectionController::class, 'transfer']);

    Route::get('/subjects', [SubjectController::class, 'index']);
    Route::post('/subjects', [SubjectController::class, 'store']);
    Route::put('/subjects/{subject}', [SubjectController::class, 'update']);
    Route::delete('/subjects/{subject}', [SubjectController::class, 'destroy']);

    Route::get('/teacher-assignments', [TeacherAssignmentController::class, 'index']);
    Route::post('/teacher-assignments', [TeacherAssignmentController::class, 'store']);
    Route::put('/teacher-assignments/{teacherAssignment}', [TeacherAssignmentController::class, 'update']);
    Route::delete('/teacher-assignments/{teacherAssignment}', [TeacherAssignmentController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'active', 'role:supervisor'])->prefix('supervisor')->group(function () {

    Route::get('/schedule/periods', [WeeklyScheduleController::class, 'periods']);

    Route::get('/schedule/free-teachers', [WeeklyScheduleController::class, 'freeTeachers']);
    Route::get('/schedule/section/{section}', [WeeklyScheduleController::class, 'sectionSchedule']);

    Route::get('/schedule/teacher/{teacher}', [WeeklyScheduleController::class, 'teacherSchedule']);
    Route::post('/schedule', [WeeklyScheduleController::class, 'store']);
    Route::post('/schedule/bulk', [WeeklyScheduleController::class, 'storeBulk']); Route::put('/schedule/{schedule}', [WeeklyScheduleController::class, 'update']);
    Route::delete('/schedule/{schedule}', [WeeklyScheduleController::class, 'destroy']);

    
    Route::get('/attendance/students', [SupervisorAttendanceController::class, 'studentSheet']);

    Route::post('/attendance/students', [SupervisorAttendanceController::class, 'storeStudentAttendance']);

    Route::get('/attendance/teachers', [SupervisorAttendanceController::class, 'teacherSheet']);

    Route::post('/attendance/teachers', [SupervisorAttendanceController::class, 'storeTeacherAttendance']);
    Route::get('/announcements', [SupervisorAnnouncementController::class, 'index']);
    Route::get('/announcements/holidays', [SupervisorAnnouncementController::class, 'holidays']);
    Route::get('/announcements/day-status', [SupervisorAnnouncementController::class, 'dayStatus']);

    Route::get('/announcements/{announcement}', [SupervisorAnnouncementController::class, 'show']);


    Route::post('/announcements', [SupervisorAnnouncementController::class, 'store']);

    Route::post('/announcements/{announcement}', [SupervisorAnnouncementController::class, 'update']);

    Route::delete('/announcements/{announcement}', [SupervisorAnnouncementController::class, 'destroy']);


    Route::get('/fees', [StudentFeeController::class, 'index']);

    Route::post('/fees', [StudentFeeController::class, 'store']);

    Route::get('/fees/{fee}', [StudentFeeController::class, 'show']);

    Route::put('/fees/{fee}', [StudentFeeController::class, 'update']);

    Route::post('/fees/{fee}/payments', [StudentFeeController::class, 'storePayment']);

    Route::delete('/fee-payments/{payment}', [StudentFeeController::class, 'destroyPayment']);


    Route::get('/students/search', [StudentReportController::class, 'search']);

    Route::get('/students/{student}/report-card', [StudentReportController::class, 'reportCard']);

    Route::get('/substitutions/absent-lessons', [SubstitutionController::class, 'absentLessons']);

    // المرشحين لتغطية حصة، مرتبين: نفس المادة أولاً ثم الأقل عبئاً هالأسبوع
    // ?weekly_schedule_id= &date=
    Route::get('/substitutions/available-teachers', [SubstitutionController::class, 'availableTeachers']);

    // ?date= &status=assigned|completed|cancelled
    Route::get('/substitutions', [SubstitutionController::class, 'index']);

    // تعيين بديل — بيفحص إنو موجود بالمدرسة وفاضي ومش مكلّف بتعويض تاني
    Route::post('/substitutions', [SubstitutionController::class, 'store']);

    // تحديث الحالة: تم التنفيذ أو إلغاء (الإلغاء بيحرّر البديل)
    Route::patch('/substitutions/{substitution}/status', [SubstitutionController::class, 'updateStatus']);
});

// ==================== Guardian ====================

Route::middleware(['auth:sanctum', 'active', 'role:guardian'])->prefix('guardian')->group(function () {

    
    Route::get('/children', [GuardianController::class, 'children']);
    Route::get('/children/{student}/attendance', [GuardianController::class, 'attendance']);

    Route::get('/children/{student}/attendance/summary', [GuardianController::class, 'attendanceSummary']);

    
    Route::get('/children/{student}/grades', [GuardianController::class, 'grades']);
    Route::get('/children/{student}/fees', [GuardianController::class, 'fees']);

    // الجدول الأسبوعي — ?date= بترجّع حصص ذاك اليوم فقط مع البديل إن وُجد
    Route::get('/children/{student}/schedule', [GuardianController::class, 'schedule']);
});
