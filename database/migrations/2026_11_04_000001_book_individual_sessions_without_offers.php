<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $addPricing = ! Schema::hasColumn('teacher_subjects', 'individual_session_price');
        if ($addPricing) {
            Schema::table('teacher_subjects', function (Blueprint $table) {
                // NULL means not configured, zero is an explicitly free session.
                $table->decimal('individual_session_price', 12, 2)->nullable();
                $table->string('individual_currency', 3)->default('SYP');
            });
        }
        if (! Schema::hasColumn('individuals', 'legacy_offer_snapshot')) {
            Schema::table('individuals', fn (Blueprint $table) => $table->json('legacy_offer_snapshot')->nullable());
        }

        // Only an unambiguous whole-subject price can be promoted to a course price.
        // Unit/lesson prices and historical individual snapshots are not reinterpreted.
        if ($addPricing) {
            DB::table('teacher_subjects')->whereNull('individual_session_price')->orderBy('id')->chunkById(100, function ($courses) {
                foreach ($courses as $course) {
                    $prices = DB::table('individual_offers')->where('teacher_subject_id', $course->id)
                        ->where('teaching_scope_type', 'subject')->where('teaching_scope_id', $course->id)->where('status', 'active')
                        ->select('session_price', 'currency')->distinct()->get();
                    if ($prices->count() === 1 && is_numeric($prices[0]->session_price) && $prices[0]->session_price >= 0
                        && preg_match('/^[A-Z]{3}$/', $prices[0]->currency)) {
                        DB::table('teacher_subjects')->where('id', $course->id)->whereNull('individual_session_price')
                            ->update(['individual_session_price' => $prices[0]->session_price, 'individual_currency' => $prices[0]->currency]);
                    }
                }
            });
        }

        if (Schema::hasColumn('individuals', 'individual_offer_id')) {
            DB::table('individuals')->whereNotNull('individual_offer_id')->whereNull('legacy_offer_snapshot')->orderBy('id')->chunkById(100, function ($individuals) {
                foreach ($individuals as $individual) {
                    $offer = DB::table('individual_offers')->find($individual->individual_offer_id);
                    DB::table('individuals')->where('id', $individual->id)->update([
                        'legacy_offer_snapshot' => json_encode(['offer_id' => $individual->individual_offer_id, 'offer' => $offer], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    ]);
                }
            });
            foreach (Schema::getForeignKeys('individuals') as $key) {
                if (in_array('individual_offer_id', $key['columns'], true)) {
                    Schema::table('individuals', fn (Blueprint $table) => $table->dropForeign($key['name'] ?? ['individual_offer_id']));
                }
            }
            Schema::table('individuals', fn (Blueprint $table) => $table->renameColumn('individual_offer_id', 'legacy_individual_offer_id'));
        }
        // individual_offers is retained as a read-only archive; no booking depends on it.
    }

    public function down(): void
    {
        if (Schema::hasColumn('individuals', 'legacy_individual_offer_id')) {
            $missing = DB::table('individuals')->whereNotNull('legacy_individual_offer_id')
                ->whereNotIn('legacy_individual_offer_id', DB::table('individual_offers')->select('id'))->exists();
            if ($missing) {
                throw new RuntimeException('Restore the archived offers before rolling back this migration.');
            }
            Schema::table('individuals', fn (Blueprint $table) => $table->renameColumn('legacy_individual_offer_id', 'individual_offer_id'));
            Schema::table('individuals', fn (Blueprint $table) => $table->foreign('individual_offer_id')->references('id')->on('individual_offers')->restrictOnDelete());
        }
        if (Schema::hasColumn('individuals', 'legacy_offer_snapshot')) {
            Schema::table('individuals', fn (Blueprint $table) => $table->dropColumn('legacy_offer_snapshot'));
        }
        if (Schema::hasColumn('teacher_subjects', 'individual_session_price')) {
            Schema::table('teacher_subjects', fn (Blueprint $table) => $table->dropColumn(['individual_session_price', 'individual_currency']));
        }
    }
};
