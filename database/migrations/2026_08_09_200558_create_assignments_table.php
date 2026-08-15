<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_assignment_id')->constrained('teacher_assignments')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('max_grade')->default(100);
            $table->string('attachment_path')->nullable();
            $table->string('attachment_link')->nullable();
            $table->timestamps();

            $table->index('due_date');
        });
    }

    public function down(): void { Schema::dropIfExists('assignments'); }
};
