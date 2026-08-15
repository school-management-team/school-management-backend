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
    Schema::create('classes', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->unsignedTinyInteger('grade_order'); // يطابق مفاتيح config.school (1-12)
        $table->foreignId('stage_id')->constrained('stages');

        $table->unique(['stage_id', 'grade_order']);
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('classes'); }
};
