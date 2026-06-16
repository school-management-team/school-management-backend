<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_number', 5)->nullable()->unique();
            $table->string('student_name');
            $table->string('father_name');
            $table->string('mother_name');
            $table->enum('gender', ['male', 'female']);
            $table->date('birth_date');
            $table->enum('education_level', ['primary', 'middle', 'high']);
            $table->string('grade');

            $table->enum('status', ['unverified', 'pending', 'rejected', 'active', 'graduated', 'transferred'])->default('unverified');
            $table->date('enrollment_date');

            $table->timestamps();
            $table->softDeletes();


        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
