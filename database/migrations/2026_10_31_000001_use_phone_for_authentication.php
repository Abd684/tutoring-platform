<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->select('phone')->groupBy('phone')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Resolve duplicate users.phone values before enabling phone authentication.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
        // Keep email nullable: accounts created without email must remain valid.
    }
};
