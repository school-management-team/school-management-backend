<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->date('end_date')->nullable()->after('date');
            $table->index(['type', 'date'], 'announcements_type_date_idx');
        });

        $this->setTypeEnum(['academic', 'administrative', 'activity', 'holiday']);
    }

    public function down(): void
    {
        DB::table('announcements')->where('type', 'holiday')->delete();

        $this->setTypeEnum(['academic', 'administrative', 'activity']);

        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex('announcements_type_date_idx');
            $table->dropColumn('end_date');
        });
    }

    private function setTypeEnum(array $values): void
    {
        if (DB::getDriverName() === 'mysql') {
            $list = collect($values)->map(fn ($v) => "'{$v}'")->implode(',');
            DB::statement("ALTER TABLE announcements MODIFY COLUMN type ENUM({$list}) NOT NULL");

            return;
        }

        Schema::table('announcements', function (Blueprint $table) use ($values) {
            $table->enum('type', $values)->change();
        });
    }
};
