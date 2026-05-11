<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('teacher_id')->unique();
            $table->string('national_id')->unique();
            $table->date('birth_date');
            $table->enum('gender', ['male', 'female']);
            $table->text('address');
            $table->text('health_status')->nullable();
            $table->string('specialization');
            $table->enum('education_level', ['primary', 'middle', 'high']);
            $table->string('high_school_branch')->nullable();
            $table->boolean('is_class_teacher')->default(false);
            $table->integer('years_of_experience')->default(0);
            $table->integer('weekly_hours')->default(40);
            $table->date('hire_date');
            $table->string('cv_path')->nullable();
            $table->string('legal_document_path')->nullable();
            $table->enum('status', ['unverified','pending', 'active', 'on_leave', 'terminated'])->default('pending');
            $table->decimal('rating', 3, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['education_level', 'specialization']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
