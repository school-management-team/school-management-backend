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
        Schema::create('teacher_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_important')->default(false);
            $table->enum('status', ['in_progress', 'completed'])->default('in_progress');
            $table->date('due_date');
            $table->timestamps();

            $table->index('due_date');
            $table->index('status');
        });
    }

    public function down(): void { Schema::dropIfExists('teacher_tasks'); }
};
