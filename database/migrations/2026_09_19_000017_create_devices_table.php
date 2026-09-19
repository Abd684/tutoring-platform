<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_uuid')->unique();
            $table->enum('platform', ['android', 'ios']);
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('os_version')->nullable();
            $table->string('app_version')->nullable();
            $table->text('public_key')->nullable();
            $table->enum('status', ['trusted', 'blocked'])->default('trusted');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
