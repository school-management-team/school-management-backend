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
            $table->string('email')->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'teacher', 'student']);
            $table->string('phone')->nullable();
            $table->string('device_token')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();

            $table->unsignedBigInteger('admin_id')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->unsignedBigInteger('student_id')->nullable();

            $table->string('verification_code', 6)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->integer('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->json('active_tokens')->nullable();
            $table->boolean('force_logout')->default(false);
            $table->timestamp('force_logout_at')->nullable();


            $table->rememberToken();
            $table->timestamp('remember_expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // الفهارس
            $table->index('role');
            $table->index('is_active');
            $table->index('admin_id');
            $table->index('teacher_id');
            $table->index('student_id');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
