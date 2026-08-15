<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('user_name');
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'supervisor', 'teacher', 'student', 'guardian']);
            $table->string('phone');
            $table->enum('gender', ['male', 'female']);
            $table->date('birth_date');

            $table->enum('status', ['unverified', 'pending', 'active','rejected'])->default('unverified');
            $table->string('profile_photo_path')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();

            // تفعيل البريد
            $table->string('verification_code', 6)->nullable();
            $table->timestamp('verification_expires_at')->nullable();

            // أمان
            $table->timestamp('password_changed_at')->nullable();
            $table->integer('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();


        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
