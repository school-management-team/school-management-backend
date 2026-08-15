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
    Schema::create('stages', function (Blueprint $table) {
        $table->id();
        $table->enum('name', ['primary', 'middle', 'high_scientific','high_literary'])->unique();
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('stages'); }
};
