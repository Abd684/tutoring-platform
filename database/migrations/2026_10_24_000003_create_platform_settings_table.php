<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value');
            $table->timestamps();
        });
        Schema::table('contents', function (Blueprint $table) {
            $table->dateTime('scheduled_publish_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex(['scheduled_publish_at']);
            $table->dropColumn('scheduled_publish_at');
        });
        Schema::dropIfExists('platform_settings');
    }
};
