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
            $table->string('father_name');
            $table->string('mother_name');
            $table->enum('education_level', ['primary', 'middle', 'high']);
            $table->string('school_class');
            $table->date('enrollment_date');

            $table->foreignId('user_id')->constrained('users');

            $table->timestamps();
            $table->softDeletes();


        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
