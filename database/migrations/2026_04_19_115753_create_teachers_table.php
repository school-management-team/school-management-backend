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

            // المعلومات المهنية
            $table->enum('education_level', ['primary', 'middle','high']);
            $table->string('school_class');
            $table->string('specialization');

            // المستندات
            $table->text('cv');
            $table->string('legal_document_path');

            $table->foreignId('user_id')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
