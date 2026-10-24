<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('account_id')->nullable();
            $table->string('qr_path')->nullable();
            $table->timestamps();
        });
        DB::table('payment_settings')->insert(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('internal_reference')->nullable()->unique();
            // Nullable for legacy providers; unique across all Sham Cash attempts.
            $table->string('sham_transfer_reference')->nullable()->unique();
            $table->string('proof_path')->nullable();
            $table->string('proof_mime', 100)->nullable();
            $table->json('service_snapshot')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->dateTime('submitted_at')->nullable()->index();
        });

        Schema::create('payment_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('service_type', 30);
            $table->unsignedBigInteger('service_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('account_id');
            $table->string('qr_path');
            $table->dateTime('expires_at')->index();
            $table->foreignId('payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('related_payment_id')->nullable()->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', fn (Blueprint $table) => $table->dropConstrainedForeignId('related_payment_id'));
        Schema::dropIfExists('payment_checkouts');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropUnique(['internal_reference']);
            $table->dropUnique(['sham_transfer_reference']);
            $table->dropIndex(['submitted_at']);
            $table->dropColumn(['internal_reference', 'sham_transfer_reference', 'proof_path', 'proof_mime', 'service_snapshot', 'rejection_reason', 'reviewed_at', 'submitted_at']);
        });
        Schema::dropIfExists('payment_settings');
    }
};
