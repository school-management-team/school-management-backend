<?php
// database/migrations/0001_01_01_000001_create_teachers_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->string('teacher_name');
            $table->date('birth_date');
            $table->enum('gender', ['male', 'female']);
            // المعلومات المهنية
            $table->string('grade');
            $table->enum('education_level', ['primary', 'middle', 'high']);
            $table->string('specialization');

            // المستندات
            $table->string('cv');
            $table->string('legal_document_path');

            // الحالة والتقييم
            $table->enum('status', ['unverified', 'pending', 'active', 'on_leave', 'terminated'])->default('unverified');

            $table->timestamps();
            $table->softDeletes();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
