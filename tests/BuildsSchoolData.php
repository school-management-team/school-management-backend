<?php

namespace Tests;

use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Stage;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Supervisor;
use App\Models\Teacher;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * بيبني الحد الأدنى من بيانات المدرسة اللازمة للاختبارات.
 * منبنيها يدوياً مش بالـ factories لأن بعض الـ factories فيها أعمدة قديمة.
 */
trait BuildsSchoolData
{
    protected function makeUser(string $role, string $name = 'User'): User
    {
        static $counter = 0;
        $counter++;

        return User::create([
            'user_name' => $name,
            'email' => "{$role}{$counter}@test.local",
            'password' => Hash::make('password123'),
            'role' => $role,
            'phone' => '09'.str_pad((string) $counter, 8, '0', STR_PAD_LEFT),
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    protected function makeSupervisor(): Supervisor
    {
        return Supervisor::create([
            'educational_qualification' => 'master',
            'specialization' => 'الإشراف',
            'bio' => 'موجّه',
            'cv_file' => 'cv.pdf',
            'user_id' => $this->makeUser('supervisor', 'Supervisor')->id,
        ]);
    }

    protected function makeTeacher(Subject $subject, Stage $stage, string $name = 'Teacher'): Teacher
    {
        return Teacher::create([
            'subject_id' => $subject->id,
            'stage_id' => $stage->id,
            'cv' => 'cv.pdf',
            'legal_document_path' => 'doc.pdf',
            'user_id' => $this->makeUser('teacher', $name)->id,
        ]);
    }

    protected function makeStudent(Section $section, string $number): Student
    {
        return Student::create([
            'student_number' => $number,
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'enrollment_date' => now()->toDateString(),
            'class_id' => $section->class_id,
            'section_id' => $section->id,
            'user_id' => $this->makeUser('student', 'Student '.$number)->id,
        ]);
    }

    protected function makeSection(string $name = 'A'): Section
    {
        $stage = $this->makeStage();
        $class = SchoolClass::firstOrCreate(
            ['name' => 'الصف الأول', 'stage_id' => $stage->id],
            ['grade_order' => 1]
        );

        return Section::create(['name' => $name, 'class_id' => $class->id, 'capacity' => 30]);
    }

    /**
     * اسم المرحلة enum بالداتابيز، فمحصور بهالقيم.
     *
     * منربطها بكل المواد الموجودة، لأن النظام بيمنع تكليف معلم بمادة
     * غير مقررة بمرحلة الشعبة (جدول stage_subject).
     */
    protected function makeStage(string $name = 'primary'): Stage
    {
        $stage = Stage::firstOrCreate(['name' => $name]);
        $stage->subjects()->syncWithoutDetaching(Subject::pluck('id'));

        return $stage;
    }

    /** المادة بتنربط بكل المراحل الموجودة — شوف makeStage للسبب */
    protected function makeSubject(string $name): Subject
    {
        $subject = Subject::create(['name' => $name, 'passing_grade' => 50, 'description' => $name]);
        $subject->stages()->syncWithoutDetaching(Stage::pluck('id'));

        return $subject;
    }

    /** ربط صريح لمادة بمرحلة — للاختبارات يلي بدها تتحكّم بالربط بنفسها */
    protected function linkSubjectToStage(Subject $subject, Stage $stage): Subject
    {
        $subject->stages()->syncWithoutDetaching([$stage->id]);

        return $subject;
    }

    protected function makeAssignment(Teacher $teacher, Subject $subject, Section $section): TeacherAssignment
    {
        return TeacherAssignment::create([
            'teacher_id' => $teacher->id,
            'subject_id' => $subject->id,
            'section_id' => $section->id,
        ]);
    }
}
