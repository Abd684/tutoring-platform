<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $t) {
            $t->decimal('price', 12, 2)->nullable();
        });
        Schema::table('quizzes', function (Blueprint $t) {
            $t->string('quiz_type', 20)->default('protected');
            $t->decimal('progress_weight', 5, 2)->default(0.30);
        });
        foreach (['groups', 'individuals'] as $name) {
            Schema::table($name, function (Blueprint $t) {
                $t->string('teaching_scope_type', 20)->nullable();
                $t->unsignedBigInteger('teaching_scope_id')->nullable();
                $t->decimal('session_price', 12, 2)->default(0);
                $t->string('currency', 3)->default('SYP');
                $t->index(['teaching_scope_type', 'teaching_scope_id']);
            });
            DB::table($name)->update(['teaching_scope_type' => 'subject', 'teaching_scope_id' => DB::raw('teacher_subject_id')]);
        }
        Schema::table('groups', function (Blueprint $t) {
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
        });
        Schema::create('individual_offers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('teacher_subject_id')->constrained('teacher_subjects')->restrictOnDelete();
            $t->string('teaching_scope_type', 20);
            $t->unsignedBigInteger('teaching_scope_id');
            $t->string('title');
            $t->decimal('session_price', 12, 2);
            $t->string('currency', 3)->default('SYP');
            $t->string('status', 20)->default('active');
            $t->timestamps();
            $t->index(['teaching_scope_type', 'teaching_scope_id']);
        });
        Schema::table('individuals', function (Blueprint $t) {
            $t->foreignId('individual_offer_id')->nullable()->constrained('individual_offers')->restrictOnDelete();
        });
        Schema::table('enrollments', function (Blueprint $t) {
            $t->dateTime('access_frozen_at')->nullable();
        });
        Schema::create('lesson_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $t->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $t->dateTime('completed_at');
            $t->unique(['student_id', 'lesson_id']);
        });
        Schema::create('payment_lesson_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $t->foreignId('lesson_id')->constrained('lessons')->restrictOnDelete();
            $t->string('lesson_title');
            $t->decimal('lesson_price_snapshot', 12, 2);
            $t->decimal('allocated_amount', 12, 2);
            $t->json('content_ids_snapshot');
            $t->unique(['payment_id', 'lesson_id']);
        });
        Schema::table('payment_checkouts', function (Blueprint $t) {
            $t->json('pricing_snapshot')->nullable();
        });
        Schema::table('refund_requests', function (Blueprint $t) {
            $t->string('rejection_code', 40)->nullable();
            $t->string('refund_proof_path')->nullable();
            $t->string('refund_proof_mime')->nullable();
            $t->dateTime('refund_transferred_at')->nullable();
            $t->boolean('payout_details_required')->default(false);
            $t->dateTime('original_transfer_date')->nullable();
            $t->string('original_transfer_number')->nullable();
            $t->string('original_proof_path')->nullable();
            $t->string('original_proof_mime')->nullable();
        });
        Schema::create('refund_lesson_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('refund_request_id')->constrained('refund_requests')->restrictOnDelete();
            $t->foreignId('payment_lesson_allocation_id')->constrained('payment_lesson_allocations')->restrictOnDelete();
            $t->decimal('amount', 12, 2);
            $t->unique(['refund_request_id', 'payment_lesson_allocation_id']);
        });
        DB::table('refund_requests')->where('status', 'COMPLETED')->update(['active_payment_id' => null]);
        Schema::table('notifications', function (Blueprint $t) {
            $t->json('data')->nullable();
        });
        // Old completed/pending refunds have no item ledger. Runtime eligibility always
        // accounts for their recorded total before admitting another request.
        // Historic payments remain untouched: no guessed purchase-time prices are backfilled.
    }

    public function down(): void
    {
        throw new RuntimeException('Restore the pre-upgrade database backup; online price snapshots and refund ledgers cannot be discarded safely.');
    }
};
