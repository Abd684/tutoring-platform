<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', fn (Blueprint $table) => $table->string('code', 40)->nullable()->unique());
        Schema::table('schools', function (Blueprint $table) {
            $table->string('code', 40)->nullable()->unique();
            $table->string('education_stage', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
        Schema::table('schools', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'education_stage']);
        });
    }
};
