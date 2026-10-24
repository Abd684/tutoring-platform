<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_availabilities', fn (Blueprint $table) => $table->decimal('price', 12, 2)->nullable());
        Schema::table('payment_checkouts', function (Blueprint $table) {
            $table->dateTime('booking_starts_at')->nullable();
            $table->dateTime('booking_ends_at')->nullable();
        });
        Schema::create('paid_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->restrictOnDelete();
            $table->foreignId('availability_id')->constrained('teacher_availabilities')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('session_id')->nullable()->unique()->constrained('sessions')->restrictOnDelete();
            $table->string('status', 30)->default('HELD');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('hold_expires_at')->index();
            $table->string('cancelled_by_role', 30)->nullable();
            $table->timestamps();
            $table->index(['teacher_id', 'status', 'starts_at']);
            $table->index(['student_id', 'status', 'starts_at']);
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            // Null after rejection only: protects against concurrent duplicate/full refunds.
            $table->unsignedBigInteger('active_payment_id')->nullable()->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('account_id');
            $table->string('reason_code', 40);
            $table->text('reason');
            $table->string('status', 30)->default('PENDING_REVIEW')->index();
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->string('transfer_reference')->nullable()->unique();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_requests');
        Schema::dropIfExists('paid_bookings');
        Schema::table('payment_checkouts', fn (Blueprint $table) => $table->dropColumn(['booking_starts_at', 'booking_ends_at']));
        Schema::table('teacher_availabilities', fn (Blueprint $table) => $table->dropColumn('price'));
    }
};
