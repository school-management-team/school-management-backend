<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->time('left_at')->nullable()->after('excuse');
        });

        $this->setStatusEnum(config('school.attendance_statuses.student'));
    }

    public function down(): void
    {
        DB::table('attendance')->where('status', 'early_leave')->update(['status' => 'present']);

        $this->setStatusEnum(['present', 'absent', 'late', 'excused']);

        Schema::table('attendance', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }

    private function setStatusEnum(array $values): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = collect($values)->map(fn ($v) => "'{$v}'")->implode(',');
            DB::statement("ALTER TABLE attendance MODIFY COLUMN status ENUM({$list}) NOT NULL");

            return;
        }

        Schema::table('attendance', function (Blueprint $table) use ($values) {
            $table->enum('status', $values)->change();
        });
    }
};
