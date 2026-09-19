<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->index('status', 'teachers_status_idx');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->index('status', 'subjects_status_idx');
            $table->index('name', 'subjects_name_idx');
        });

        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->index(['teacher_id', 'status'], 'teacher_subjects_teacher_status_idx');
            $table->index(['subject_id', 'status'], 'teacher_subjects_subject_status_idx');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->index(['teacher_subject_id', 'status', 'order_no'], 'units_parent_status_order_idx');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->index(['unit_id', 'status', 'order_no'], 'lessons_parent_status_order_idx');
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->index(['lesson_id', 'status', 'order_no'], 'contents_parent_status_order_idx');
            $table->index(['type', 'status'], 'contents_type_status_idx');
        });

        Schema::table('content_assets', function (Blueprint $table) {
            $table->index(['content_id', 'status'], 'content_assets_content_status_idx');
            $table->index('encryption_status', 'content_assets_encryption_status_idx');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->index(['teacher_subject_id', 'status'], 'groups_teacher_subject_status_idx');
        });

        Schema::table('group_enrollments', function (Blueprint $table) {
            $table->index(['group_id', 'status'], 'group_enrollments_group_status_idx');
            $table->index(['enrollment_id', 'status'], 'group_enrollments_enrollment_status_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->index(['payer_id', 'status'], 'payments_payer_status_idx');
            $table->index(['status', 'paid_at'], 'payments_status_paid_at_idx');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->index(['status', 'billing_cycle'], 'subscription_plans_status_cycle_idx');
        });

        Schema::table('teacher_subscriptions', function (Blueprint $table) {
            $table->index(['teacher_id', 'status', 'ends_at'], 'teacher_subscriptions_teacher_status_end_idx');
            $table->index(['plan_id', 'status'], 'teacher_subscriptions_plan_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_subscriptions', function (Blueprint $table) {
            $table->dropIndex('teacher_subscriptions_teacher_status_end_idx');
            $table->dropIndex('teacher_subscriptions_plan_status_idx');
        });

        Schema::table('subscription_plans', function (Blueprint $table) {
            $table->dropIndex('subscription_plans_status_cycle_idx');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_payer_status_idx');
            $table->dropIndex('payments_status_paid_at_idx');
        });

        Schema::table('group_enrollments', function (Blueprint $table) {
            $table->dropIndex('group_enrollments_group_status_idx');
            $table->dropIndex('group_enrollments_enrollment_status_idx');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex('groups_teacher_subject_status_idx');
        });

        Schema::table('content_assets', function (Blueprint $table) {
            $table->dropIndex('content_assets_content_status_idx');
            $table->dropIndex('content_assets_encryption_status_idx');
        });

        Schema::table('contents', function (Blueprint $table) {
            $table->dropIndex('contents_parent_status_order_idx');
            $table->dropIndex('contents_type_status_idx');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('lessons_parent_status_order_idx');
        });

        Schema::table('units', function (Blueprint $table) {
            $table->dropIndex('units_parent_status_order_idx');
        });

        Schema::table('teacher_subjects', function (Blueprint $table) {
            $table->dropIndex('teacher_subjects_teacher_status_idx');
            $table->dropIndex('teacher_subjects_subject_status_idx');
        });

        Schema::table('subjects', function (Blueprint $table) {
            $table->dropIndex('subjects_status_idx');
            $table->dropIndex('subjects_name_idx');
        });

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropIndex('teachers_status_idx');
        });
    }
};
