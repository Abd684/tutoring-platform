<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('student_devices', 'student_devices_one_active')) {
            return;
        }
        $duplicates = DB::table('student_devices')->where('status', 'active')->where('is_active', true)
            ->select('student_id')->groupBy('student_id')->havingRaw('COUNT(*) > 1')->exists();
        if ($duplicates) {
            throw new RuntimeException('Resolve duplicate active student devices before applying the unique constraint.');
        }
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE student_devices ADD active_student_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' AND is_active = 1 THEN student_id ELSE NULL END) STORED, ADD UNIQUE INDEX student_devices_one_active (active_student_id)");
        } elseif (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement("CREATE UNIQUE INDEX student_devices_one_active ON student_devices (student_id) WHERE status = 'active' AND is_active = ".($driver === 'pgsql' ? 'true' : '1'));
        } else {
            throw new RuntimeException('Unsupported database driver for active device constraint.');
        }
    }

    public function down(): void
    {
        // The shared constraint is owned by the earlier compatibility migration.
        // Preserve it when rolling back only this release line's marker.
    }
};
