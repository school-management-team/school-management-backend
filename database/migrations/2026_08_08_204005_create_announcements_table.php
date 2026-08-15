<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supervisor_id')->constrained('supervisors')->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->enum('type', ['academic', 'administrative', 'activity']);
            $table->boolean('is_important')->default(false);
            $table->date('date');
            $table->string('image_path')->nullable();
            $table->string('attachment_path')->nullable();
            $table->timestamps();

            $table->index(['date']);
            $table->index(['type']);
            $table->index(['is_important']);
        });
    }

    public function down(): void { Schema::dropIfExists('announcements'); }
};
