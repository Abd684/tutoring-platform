<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrow_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->decimal('gross_amount', 12, 2);
            $table->decimal('commission', 12, 2);
            $table->decimal('teacher_amount', 12, 2);
            $table->enum('status', ['held', 'released', 'disputed', 'refunded'])->default('held');
            $table->dateTime('release_at')->nullable();
            $table->dateTime('released_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrow_transactions');
    }
};
