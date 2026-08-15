<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_schedule_id')->constrained('weekly_schedules')->cascadeOnDelete();
            $table->date('date');
            $table->text('content');
            $table->timestamps();

            $table->unique(['weekly_schedule_id', 'date']);
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('lesson_plans');
    }
};
