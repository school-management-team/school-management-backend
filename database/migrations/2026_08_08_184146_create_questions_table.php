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
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects');
            $table->foreignId('class_id')->constrained('classes');
            $table->enum('type', ['multiple_choice', 'true_false', 'essay']);
            $table->enum('difficulty', ['easy', 'medium', 'hard']);
            $table->text('text');
            $table->json('choices')->nullable(); // [{"text": "...", "is_correct": true}, ...]
            $table->text('model_answer')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['teacher_id', 'subject_id']);
            $table->index(['teacher_id', 'difficulty']);
        });
    }



    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
