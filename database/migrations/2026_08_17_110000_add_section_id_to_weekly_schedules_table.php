<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * الشعبة كانت توصلها بس عبر teacher_assignment_id (وهو nullable)، فما كان في
     * طريقة تمنع إنو شعبة وحدة يجيها معلمين اثنين بنفس اليوم ونفس الحصة.
     * منزّلها كعمود مستقل حتى نقدر نحط عليها unique على مستوى الداتابيز.
     */
    public function up(): void
    {
        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->foreignId('section_id')
                ->nullable()
                ->after('teacher_assignment_id')
                ->constrained('sections')
                ->cascadeOnDelete();
        });

        // تعبئة الصفوف الموجودة من التكليف المرتبط فيها
        DB::table('weekly_schedules')
            ->whereNotNull('teacher_assignment_id')
            ->update([
                'section_id' => DB::raw('(select section_id from teacher_assignments where teacher_assignments.id = weekly_schedules.teacher_assignment_id)'),
            ]);

        Schema::table('weekly_schedules', function (Blueprint $table) {
            // صفوف الاستراحة/الفراغ إلها section_id = NULL، والـ NULL بتتكرر بلا مشكلة
            $table->unique(['section_id', 'day_of_week', 'period_number'], 'ws_section_day_period_unique');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_schedules', function (Blueprint $table) {
            $table->dropUnique('ws_section_day_period_unique');
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }
};
