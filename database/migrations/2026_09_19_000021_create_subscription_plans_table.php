<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 12, 2)->default(0);
            $table->enum('billing_cycle', ['monthly', 'yearly']);
            $table->decimal('commission_rate', 5, 2)->default(0);
            $table->unsignedInteger('max_students')->nullable();
            $table->unsignedBigInteger('max_storage')->nullable();
            $table->unsignedInteger('max_ai_usage')->nullable();
            $table->unsignedInteger('max_group_size')->nullable();
            $table->string('status')->default('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
