<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('student_devices', 'student_devices_one_active')) {
            return;
        }
        // Fail without altering bindings when existing data needs reconciliation.
        if (DB::table('student_devices')->select('student_id')->where('status', 'active')->where('is_active', true)
            ->groupBy('student_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Reconcile duplicate active student device bindings before migrating.');
        }
        if (DB::getDriverName() === 'mysql') {
            if (! Schema::hasColumn('student_devices', 'active_student_id')) {
                DB::statement("ALTER TABLE student_devices ADD active_student_id BIGINT UNSIGNED GENERATED ALWAYS AS (CASE WHEN status = 'active' AND is_active = 1 THEN student_id ELSE NULL END) STORED");
            }
            Schema::table('student_devices', fn (Blueprint $table) => $table->unique('active_student_id', 'student_devices_one_active'));
        } else {
            DB::statement("CREATE UNIQUE INDEX student_devices_one_active ON student_devices (student_id) WHERE status = 'active' AND is_active = true");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            Schema::table('student_devices', function (Blueprint $table) {
                $table->dropUnique('student_devices_one_active');
                $table->dropColumn('active_student_id');
            });
        } else {
            DB::statement('DROP INDEX student_devices_one_active');
        }
    }
};
