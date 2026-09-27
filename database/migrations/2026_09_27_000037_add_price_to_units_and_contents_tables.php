<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->decimal('price', 12, 2)->nullable()->after('description');
        });

        Schema::table('contents', function (Blueprint $table): void {
            $table->decimal('price', 12, 2)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('price');
        });

        Schema::table('units', function (Blueprint $table): void {
            $table->dropColumn('price');
        });
    }
};
