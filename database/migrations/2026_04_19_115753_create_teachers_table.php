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

            $table->foreignId('user_id')->unique()->constrained('users');
            $table->foreignId('subject_id')->constrained('subjects');
            $table->foreignId('stage_id')->constrained('stages');

            // المستندات
            $table->text('cv');
            $table->string('legal_document_path');

            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teachers');
    }
};
