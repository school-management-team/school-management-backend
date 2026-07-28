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
    Schema::create('grades', function (Blueprint $table) {
        $table->id();
        $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
        $table->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->cascadeOnDelete();
        $table->enum('type', ['homework', 'quiz', 'exam', 'participation', 'other']);
        $table->double('value');
        $table->enum('status', ['draft', 'approved', 'rejected'])->default('draft');
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('grades'); }
};
