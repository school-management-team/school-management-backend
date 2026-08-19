<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_substitutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_schedule_id')->constrained('weekly_schedules')->cascadeOnDelete();
            $table->foreignId('absent_teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('substitute_teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('supervisor_id')->constrained('supervisors')->cascadeOnDelete();
            $table->date('date');

            // منسوخة من الحصة حتى نفحص تعارض البديل بدون join
            $table->enum('day_of_week', ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday']);
            $table->unsignedTinyInteger('period_number');

            $table->enum('status', ['assigned', 'completed', 'cancelled'])->default('assigned');
            $table->string('note')->nullable();
            $table->timestamps();

            // حصة واحدة بتاريخ واحد = تعويض واحد
            $table->unique(['weekly_schedule_id', 'date']);
            $table->index(['substitute_teacher_id', 'date', 'period_number'], 'subs_substitute_date_period_idx');
            $table->index(['absent_teacher_id', 'date'], 'subs_absent_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_substitutions');
    }
};
