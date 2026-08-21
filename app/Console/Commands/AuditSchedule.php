<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * فحص تناسق الجدول والتكليفات — للقراءة فقط، ما بيعدّل ولا صف.
 *
 * بيلقط البيانات القديمة يلي انخلقت قبل ما تنضاف حراس المرحلة والمادة.
 * الكود هلق بيمنعها، بس الصفوف المحفوظة من قبل بتضل مكانها.
 */
class AuditSchedule extends Command
{
    protected $signature = 'schedule:audit';

    protected $description = 'يكشف التكليفات والحصص المخالفة لقاعدة المرحلة والمادة';

    public function handle(): int
    {
        $problems = 0;

        $problems += $this->reportOutOfStage();
        $problems += $this->reportSubjectNotInStage();
        $problems += $this->reportScheduleTeacherMismatch();
        $problems += $this->reportLessonsWithoutAssignment();

        $this->newLine();

        if ($problems === 0) {
            $this->info('البيانات متناسقة — ما في ولا مخالفة.');

            return self::SUCCESS;
        }

        $this->warn("المجموع: {$problems} صف مخالف.");
        $this->line('هذه بيانات قديمة — الكود الحالي بيمنع إنشاء مثلها.');
        $this->line('احذف التكليف المخالف من /supervisor/teacher-assignments وحصصه بتنحذف معه.');

        return self::SUCCESS;
    }

    /** معلم مكلّف بصف من مرحلة غير مرحلته */
    private function reportOutOfStage(): int
    {
        $rows = DB::table('teacher_assignments as a')
            ->join('teachers as t', 't.id', '=', 'a.teacher_id')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->join('sections as s', 's.id', '=', 'a.section_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('stages as cs', 'cs.id', '=', 'c.stage_id')
            ->join('stages as ts', 'ts.id', '=', 't.stage_id')
            ->join('subjects as sub', 'sub.id', '=', 'a.subject_id')
            ->whereColumn('c.stage_id', '!=', 't.stage_id')
            ->get(['a.id', 'u.user_name', 'ts.name as teacher_stage', 'cs.name as class_stage', 'c.name as class_name', 'sub.name as subject']);

        $this->section('تكليفات خارج مرحلة المعلم', $rows->count());

        foreach ($rows as $r) {
            $this->line("  تكليف #{$r->id}: {$r->user_name} ({$r->teacher_stage}) → {$r->class_name} ({$r->class_stage}) - {$r->subject}");
        }

        return $rows->count();
    }

    /** مادة غير مدرَّسة بمرحلة الصف — متل التاريخ بالابتدائي */
    private function reportSubjectNotInStage(): int
    {
        $rows = DB::table('teacher_assignments as a')
            ->join('users as u', 'u.id', '=', DB::raw('(select user_id from teachers where teachers.id = a.teacher_id)'))
            ->join('sections as s', 's.id', '=', 'a.section_id')
            ->join('classes as c', 'c.id', '=', 's.class_id')
            ->join('stages as cs', 'cs.id', '=', 'c.stage_id')
            ->join('subjects as sub', 'sub.id', '=', 'a.subject_id')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('stage_subject as ss')
                    ->whereColumn('ss.subject_id', 'a.subject_id')
                    ->whereColumn('ss.stage_id', 'c.stage_id');
            })
            ->get(['a.id', 'u.user_name', 'sub.name as subject', 'cs.name as class_stage', 'c.name as class_name']);

        $this->section('مواد غير مدرَّسة في مرحلة الصف', $rows->count());

        foreach ($rows as $r) {
            $this->line("  تكليف #{$r->id}: {$r->user_name} → {$r->subject} في {$r->class_name} ({$r->class_stage})");
        }

        return $rows->count();
    }

    /** الحصة مسجّلة لمعلم، والتكليف المربوط فيها لمعلم تاني */
    private function reportScheduleTeacherMismatch(): int
    {
        $rows = DB::table('weekly_schedules as w')
            ->join('teacher_assignments as a', 'a.id', '=', 'w.teacher_assignment_id')
            ->whereColumn('w.teacher_id', '!=', 'a.teacher_id')
            ->get(['w.id', 'w.teacher_id', 'a.teacher_id as assignment_teacher_id']);

        $this->section('حصص صاحبها غير صاحب التكليف', $rows->count());

        foreach ($rows as $r) {
            $this->line("  حصة #{$r->id}: مسجّلة للمعلم {$r->teacher_id} والتكليف للمعلم {$r->assignment_teacher_id}");
        }

        return $rows->count();
    }

    /** حصة بلا تكليف — بتظهر بلا مادة بالجداول */
    private function reportLessonsWithoutAssignment(): int
    {
        $count = DB::table('weekly_schedules')
            ->whereNull('teacher_assignment_id')
            ->where('type', 'class')
            ->count();

        $this->section('حصص بلا تكليف (بتظهر بلا مادة)', $count);

        return $count;
    }

    private function section(string $title, int $count): void
    {
        $this->newLine();

        if ($count === 0) {
            $this->info("[سليم] {$title}: 0");

            return;
        }

        $this->warn("[مخالف] {$title}: {$count}");
    }
}
