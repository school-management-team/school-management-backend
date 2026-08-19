<?php

namespace App\Services\Notifications;

use App\Models\Guardian;
use App\Models\Section;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * مين بياخد الإشعار؟
 *
 * كل منطق تحديد المستلمين بمكان واحد. الكنترولرات بتقول "ابعت لطلاب هالشعبة"
 * وما بتهتم كيف بينجابوا. ولو تغيّرت علاقة بالداتابيز، التعديل هون بس.
 *
 * كل الدوال بترجّع Collection من User — لأن الإشعار بينبعت للمستخدم
 * مش للملف (طالب/معلم/ولي أمر).
 */
class RecipientResolver
{
    /** طلاب شعبة معيّنة */
    public function studentsOfSection(int $sectionId): Collection
    {
        $userIds = Student::where('section_id', $sectionId)->pluck('user_id');

        return $this->activeUsers($userIds);
    }

    /** أولياء أمور طلاب شعبة معيّنة */
    public function guardiansOfSection(int $sectionId): Collection
    {
        $studentIds = Student::where('section_id', $sectionId)->pluck('id');

        return $this->guardiansOfStudents($studentIds->all());
    }

    /** أولياء أمور طالب واحد */
    public function guardiansOfStudent(int $studentId): Collection
    {
        return $this->guardiansOfStudents([$studentId]);
    }

    /** أولياء أمور مجموعة طلاب */
    public function guardiansOfStudents(array $studentIds): Collection
    {
        $guardianIds = Guardian::whereHas('students', function ($student) use ($studentIds) {
            $student->whereIn('students.id', $studentIds);
        })->pluck('id');

        $userIds = Guardian::whereIn('id', $guardianIds)->pluck('user_id');

        return $this->activeUsers($userIds);
    }

    /** المعلمون اللي بيدرّسوا طالباً معيّناً (عبر شعبته) */
    public function teachersOfStudent(int $studentId): Collection
    {
        $student = Student::find($studentId);

        if (!$student || !$student->section_id) {
            return collect();
        }

        return $this->teachersOfSection($student->section_id);
    }

    /** المعلمون المكلّفون بشعبة معيّنة */
    public function teachersOfSection(int $sectionId): Collection
    {
        $teacherIds = Teacher::whereHas('assignments', function ($assignment) use ($sectionId) {
            $assignment->where('section_id', $sectionId);
        })->pluck('id');

        $userIds = Teacher::whereIn('id', $teacherIds)->pluck('user_id');

        return $this->activeUsers($userIds);
    }

    /** كل من يحمل دوراً معيّناً: teacher / supervisor / student / guardian / admin */
    public function byRole(string $role): Collection
    {
        return User::where('role', $role)->where('status', 'active')->get();
    }

    /** مستخدمون محددون بمعرّفاتهم */
    public function users(array $userIds): Collection
    {
        return $this->activeUsers(collect($userIds));
    }

    /** كل الشعب اللي فيها طلاب — للإعلانات المدرسية العامة */
    public function allStudents(): Collection
    {
        return $this->byRole('student');
    }

    /**
     * المستخدمون النشطون فقط — الموقوف أو المحذوف ما إله معنى نبعتله،
     * وبيضل يكدّس صفوف بجدول الإشعارات.
     */
    private function activeUsers(Collection $userIds): Collection
    {
        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::whereIn('id', $userIds->filter()->unique())
            ->where('status', 'active')
            ->get();
    }

    /** اسم قناة البث الخاصة بمستخدم */
    public static function userChannel(int $userId): string
    {
        return str_replace('{id}', $userId, config('notifications.channels.user'));
    }

    public static function sectionChannel(int $sectionId): string
    {
        return str_replace('{id}', $sectionId, config('notifications.channels.section'));
    }

    public static function roleChannel(string $role): string
    {
        return str_replace('{role}', $role, config('notifications.channels.role'));
    }
}
