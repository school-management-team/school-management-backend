<?php
// database/migrations/0001_01_01_000002_create_students_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('father_name');
            $table->string('mother_name');
            $table->string('last_name');
            $table->string('student_id')->unique();
            $table->date('birth_date');
            $table->enum('gender', ['male', 'female']);
            $table->enum('education_level', ['primary', 'middle', 'high']);
            $table->integer('grade');
            $table->string('section')->nullable();
            $table->text('address');
            $table->string('guardian_phone');
            $table->string('guardian_email')->nullable();
            $table->enum('guardian_relation', ['father', 'mother', 'other'])->nullable();
            $table->text('health_status')->nullable();
            $table->string('legal_document_path')->nullable();
            $table->unsignedBigInteger('bus_id')->nullable();
            $table->enum('enrollment_status', ['pending', 'active', 'graduated', 'transferred'])->default('pending');
            $table->date('enrollment_date');
            $table->decimal('wallet_balance', 10, 2)->default(0.00);
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['education_level', 'grade', 'section']);
            $table->index('enrollment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};