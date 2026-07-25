<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('sections', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // أ، ب، ج
        $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
        $table->timestamps();
        $table->unique(['class_id', 'name']);
    });
}
public function down(): void { Schema::dropIfExists('sections'); }
};
