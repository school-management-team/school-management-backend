<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
   
    public function up(): void
    {

        DB::table('weekly_schedules')->whereNull('teacher_assignment_id')->delete();

        $this->setNullOnDelete('grades');
        $this->setNullOnDelete('grade_submissions');

        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->dropForeign(['teacher_assignment_id']);
        });

        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->foreign('teacher_assignment_id')
                ->references('id')->on('teacher_assignments')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['grades', 'grade_submissions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['teacher_assignment_id']);
            });

            Schema::table($name, function (Blueprint $table) {
                $table->foreign('teacher_assignment_id')
                    ->references('id')->on('teacher_assignments')
                    ->cascadeOnDelete();
            });
        }

        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->dropForeign(['teacher_assignment_id']);
        });

        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->foreign('teacher_assignment_id')
                ->references('id')->on('teacher_assignments')
                ->nullOnDelete();
        });
    }

    private function setNullOnDelete(string $name): void
    {
        Schema::table($name, function (Blueprint $table) {
            $table->dropForeign(['teacher_assignment_id']);
        });

        Schema::table($name, function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_assignment_id')->nullable()->change();
        });

        Schema::table($name, function (Blueprint $table) {
            $table->foreign('teacher_assignment_id')
                ->references('id')->on('teacher_assignments')
                ->nullOnDelete();
        });
    }
};
