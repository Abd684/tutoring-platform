<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('legacy_teaching_links')) {
            Schema::create('legacy_teaching_links', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 30);
                $t->unsignedBigInteger('source_id');
                $t->json('payload');
                $t->unique(['kind', 'source_id']);
            });
        }
        foreach (['sessions' => 'session', 'group_enrollments' => 'membership'] as $table => $kind) {
            if (! Schema::hasTable($table) || ($table === 'sessions' && ! Schema::hasColumn('sessions', 'enrollment_id'))) {
                continue;
            }
            foreach (DB::table($table)->orderBy('id')->get() as $row) {
                DB::table('legacy_teaching_links')->insertOrIgnore(['kind' => $kind, 'source_id' => $row->id, 'payload' => json_encode($row, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Compatibility links must be retained; restore the verified backup if needed.');
    }
};
