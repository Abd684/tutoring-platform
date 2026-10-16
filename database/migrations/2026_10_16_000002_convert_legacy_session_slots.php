<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $slots = DB::table('sessions')->where('type', 'INDIVIDUAL')->whereNull('enrollment_id')->get();
        foreach ($slots as $slot) {
            $start = Carbon::parse($slot->starts_at, config('app.timezone'));
            $end = Carbon::parse($slot->ends_at, config('app.timezone'));
            if ($start->toDateString() !== $end->toDateString() || $end->lte($start)) {
                throw new RuntimeException('Legacy slot '.$slot->id.' has an unsupported time range.');
            }
            if ($slot->group_id !== null || ! in_array($slot->status, ['scheduled', 'cancelled'], true)
                || DB::table('attendance')->where('session_id', $slot->id)->exists()) {
                throw new RuntimeException('Legacy session '.$slot->id.' requires manual reconciliation; no sessions were converted.');
            }
        }
        Schema::create('legacy_session_slots', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->json('original_session');
            $table->unsignedBigInteger('converted_availability_id');
        });
        DB::transaction(function () use ($slots) {
            foreach ($slots as $slot) {
                // Database session timestamps use the application timezone.
                $start = Carbon::parse($slot->starts_at, config('app.timezone'));
                $end = Carbon::parse($slot->ends_at, config('app.timezone'));
                if ($start->toDateString() !== $end->toDateString() || $end->lte($start)) {
                    throw new RuntimeException('Legacy slot '.$slot->id.' has an unsupported time range.');
                }
                $availabilityId = DB::table('teacher_availabilities')->insertGetId([
                    'teacher_id' => $slot->teacher_id, 'date' => $start->toDateString(),
                    'start_time' => $start->format('H:i:s'), 'end_time' => $end->format('H:i:s'),
                    'recurrence_type' => 'none', 'timezone' => config('app.timezone'),
                    'status' => $slot->status === 'cancelled' ? 'disabled' : 'active',
                ]);
                DB::table('legacy_session_slots')->insert(['id' => $slot->id,
                    'original_session' => json_encode($slot, JSON_THROW_ON_ERROR), 'converted_availability_id' => $availabilityId]);
                DB::table('sessions')->where('id', $slot->id)->delete();
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (DB::table('legacy_session_slots')->get() as $archive) {
                DB::table('sessions')->insert(json_decode($archive->original_session, true, flags: JSON_THROW_ON_ERROR));
                // Keep availability if a real booking now references it.
                if (! DB::table('sessions')->where('availability_id', $archive->converted_availability_id)->exists()) {
                    DB::table('teacher_availabilities')->where('id', $archive->converted_availability_id)->delete();
                }
            }
        });
        Schema::dropIfExists('legacy_session_slots');
    }
};
