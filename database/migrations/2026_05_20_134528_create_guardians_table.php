<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guardians', function (Blueprint $table) {
            $table->id();
            $table->enum('relationship', ['father', 'mother'])->default('father');
            $table->integer('number_of_children');
            $table->string('verification_student_number')->nullable();

            $table->foreignId('user_id')->constrained('users');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guardians');
    }
};
