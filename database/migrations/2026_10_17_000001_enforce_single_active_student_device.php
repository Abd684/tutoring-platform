<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
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
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE student_devices DROP INDEX student_devices_one_active, DROP COLUMN active_student_id');
        } else {
            DB::statement('DROP INDEX student_devices_one_active');
        }
    }
};
