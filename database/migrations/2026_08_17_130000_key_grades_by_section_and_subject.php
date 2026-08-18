<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['grades', 'grade_submissions'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                if (!Schema::hasColumn($name, 'subject_id')) {
                    $table->foreignId('subject_id')->nullable()->after('teacher_assignment_id')->constrained('subjects')->cascadeOnDelete();
                }

                if (!Schema::hasColumn($name, 'section_id')) {
                    $table->foreignId('section_id')->nullable()->after('subject_id')->constrained('sections')->cascadeOnDelete();
                }
            });
        }

        $this->backfill('grades');
        $this->backfill('grade_submissions');

        
        $this->dedupe('grades', ['student_id', 'subject_id', 'semester', 'type']);
        $this->dedupe('grade_submissions', ['section_id', 'subject_id', 'semester']);

        Schema::table('grades', function (Blueprint $table) {
        
            $table->unique(['student_id', 'subject_id', 'semester', 'type'], 'grades_student_subject_book_unique');
        });

        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'teacher_assignment_id', 'type', 'semester']);
        });

        Schema::table('grade_submissions', function (Blueprint $table) {
            $table->unique(['section_id', 'subject_id', 'semester'], 'submissions_book_unique');
            $table->index('teacher_assignment_id', 'submissions_assignment_idx');
        });

        Schema::table('grade_submissions', function (Blueprint $table) {
            $table->dropUnique(['teacher_assignment_id', 'semester']);
        });
    }

    public function down(): void
    {
        Schema::table('grades', function (Blueprint $table) {
            $table->dropUnique('grades_student_subject_book_unique');
            $table->unique(['student_id', 'teacher_assignment_id', 'type', 'semester']);
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['section_id']);
            $table->dropColumn(['subject_id', 'section_id']);
        });

        Schema::table('grade_submissions', function (Blueprint $table) {
            $table->dropUnique('submissions_book_unique');
            $table->unique(['teacher_assignment_id', 'semester']);
            $table->dropForeign(['subject_id']);
            $table->dropForeign(['section_id']);
            $table->dropColumn(['subject_id', 'section_id']);
        });
    }

    private function backfill(string $table): void
    {
        foreach (DB::table('teacher_assignments')->get() as $assignment) {
            DB::table($table)
                ->where('teacher_assignment_id', $assignment->id)
                ->update([
                    'subject_id' => $assignment->subject_id,
                    'section_id' => $assignment->section_id,
                ]);
        }
    }

    
    private function dedupe(string $table, array $keys): void
    {
        $duplicates = DB::table($table)
            ->select($keys)
            ->selectRaw('MIN(id) as keep_id')
            ->groupBy($keys)
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $query = DB::table($table)->where('id', '!=', $duplicate->keep_id);

            foreach ($keys as $key) {
                $query->where($key, $duplicate->$key);
            }

            $query->delete();
        }
    }
};
