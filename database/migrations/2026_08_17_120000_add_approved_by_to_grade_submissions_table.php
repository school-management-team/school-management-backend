<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GradeSubmission::$fillable وعلاقة approver() و AdminAcademicController
     * كلهن بيكتبوا approved_by، بس العمود ما كان موجود بالجدول أصلاً —
     * يعني اعتماد أي كشف علامات كان بيوقع بخطأ SQL.
     */
    public function up(): void
    {
        Schema::table('grade_submissions', function (Blueprint $table) {
            $table->foreignId('approved_by')
                ->nullable()
                ->after('status')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('grade_submissions', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn('approved_by');
        });
    }
};
