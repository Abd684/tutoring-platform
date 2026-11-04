<?php

namespace Tests\Feature;

use App\Models\Individual;
use App\Models\IndividualOffer;
use App\Models\Notification;
use App\Models\PaidBooking;
use App\Models\Session;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherAvailability;
use App\Models\TeacherSubject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DirectIndividualBookingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(?int $price = 5000): array
    {
        $user = User::factory()->create(['role' => 'teacher', 'status' => 'active']);
        $teacher = Teacher::create(['user_id' => $user->id, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => Subject::create(['name' => 'العربية', 'status' => 'active'])->id,
            'status' => 'active', 'price' => 250000, 'individual_session_price' => $price, 'individual_currency' => 'SYP']);
        $availability = TeacherAvailability::create(['teacher_id' => $teacher->id, 'date' => now()->addDays(3)->toDateString(), 'start_time' => '10:00', 'end_time' => '11:00', 'recurrence_type' => 'none', 'timezone' => 'UTC', 'status' => 'active', 'price' => 999]);
        $body = ['teacher_subject_id' => $course->id, 'availability_id' => $availability->id,
            'starts_at' => now()->addDays(3)->setTime(10, 0)->toIso8601String(), 'ends_at' => now()->addDays(3)->setTime(11, 0)->toIso8601String()];

        return compact('user', 'teacher', 'course', 'availability', 'body');
    }

    private function student(): Student
    {
        $student = Student::create(['user_id' => User::factory()->create(['role' => 'student', 'status' => 'active'])->id, 'status' => 'active']);
        Sanctum::actingAs($student->user);

        return $student;
    }

    private function slots(array $f, array $extra = [])
    {
        return $this->getJson('/api/v1/student/teachers/'.$f['teacher']->id.'/availability?'.http_build_query($extra + [
            'teacher_subject_id' => $f['course']->id, 'date' => now()->addDays(3)->toDateString(),
        ]));
    }

    public function test_booking_without_any_offer_snapshots_course_price_currency_and_subject(): void
    {
        $f = $this->fixture();
        $student = $this->student();
        $this->slots($f)->assertOk()->assertJsonPath('data.teacher_subject_id', $f['course']->id)
            ->assertJsonPath('data.session_price', '5000.00')->assertJsonPath('data.slots.0.status', 'available');
        $reply = $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertCreated()
            ->assertJsonPath('data.individual.student_id', $student->id)->assertJsonPath('data.individual.teacher_subject_id', $f['course']->id)
            ->assertJsonPath('data.individual.teaching_scope_type', 'subject')->assertJsonPath('data.individual.teaching_scope_id', $f['course']->id)
            ->assertJsonPath('data.individual.session_price', '5000.00')->assertJsonPath('data.individual.currency', 'SYP')
            ->assertJsonPath('data.session.status', 'scheduled')->assertJsonPath('data.session.capacity', 1)
            ->assertJsonPath('data.payment_method', 'cash_to_teacher')->assertJsonPath('data.payment_required_on_platform', false)
            ->assertJsonMissingPath('data.individual.individual_offer_id')->assertJsonMissingPath('data.individual.legacy_offer_snapshot');
        $this->assertDatabaseCount('individual_offers', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->slots($f)->assertOk()->assertJsonPath('data.slots.0.status', 'booked');
        $this->assertDatabaseHas('notifications', ['user_id' => $f['user']->id, 'type' => 'individual_booked']);
    }

    public function test_price_must_be_configured_but_an_explicit_zero_is_valid(): void
    {
        $f = $this->fixture(null);
        $this->student();
        $this->slots($f)->assertConflict()->assertJsonPath('code', 'INDIVIDUAL_PRICE_REQUIRED');
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertConflict()->assertJsonPath('code', 'INDIVIDUAL_PRICE_REQUIRED');
        $this->assertDatabaseCount('individuals', 0);
        $f['course']->update(['individual_session_price' => 0]);
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertCreated()->assertJsonPath('data.individual.session_price', '0.00');
    }

    public function test_foreign_teacher_availability_and_foreign_course_are_rejected(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->student();
        $this->postJson('/api/v1/student/individual-bookings', array_replace($f['body'], ['availability_id' => $other['availability']->id]))->assertNotFound();
        $this->slots($f, ['teacher_subject_id' => $other['course']->id])->assertNotFound();
        $this->assertDatabaseCount('individuals', 0);
        $this->assertDatabaseCount('sessions', 0);
    }

    public function test_client_cannot_supply_price_student_teacher_offer_or_scope(): void
    {
        $f = $this->fixture();
        $this->student();
        foreach (['session_price' => 1, 'currency' => 'USD', 'student_id' => 999, 'teacher_id' => 999,
            'offer_id' => 1, 'teaching_scope_type' => 'lesson', 'teaching_scope_id' => 1] as $key => $value) {
            $this->postJson('/api/v1/student/individual-bookings', $f['body'] + [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->slots($f, ['offer_id' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('individuals', 0);
    }

    public function test_only_complete_future_active_windows_can_be_booked(): void
    {
        $f = $this->fixture();
        $this->student();
        $this->postJson('/api/v1/student/individual-bookings', array_replace($f['body'], ['ends_at' => now()->addDays(3)->setTime(10, 30)->toIso8601String()]))->assertUnprocessable();
        $this->postJson('/api/v1/student/individual-bookings', array_replace($f['body'], ['starts_at' => now()->subDay()->setTime(10, 0)->toIso8601String()]))->assertUnprocessable();
        $f['availability']->update(['status' => 'disabled']);
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertUnprocessable();
        $this->assertDatabaseCount('individuals', 0);
        Sanctum::actingAs($f['user']);
        $this->getJson('/api/v1/teacher/availability?status=disabled')->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_teacher_overlap_rolls_back_the_second_individual_and_notification(): void
    {
        $f = $this->fixture();
        $this->student();
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertCreated();
        $this->student();
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertUnprocessable();
        $this->assertDatabaseCount('sessions', 1);
        $this->assertDatabaseCount('individuals', 1);
        $this->assertSame(1, Notification::where('type', 'individual_booked')->count());
    }

    public function test_student_cannot_book_overlapping_sessions_with_different_teachers(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->student();
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertCreated();
        $this->slots($other)->assertOk()->assertJsonPath('data.slots.0.status', 'booked');
        $this->postJson('/api/v1/student/individual-bookings', $other['body'])->assertUnprocessable();
        $this->assertDatabaseCount('individuals', 1);
    }

    public function test_legacy_held_paid_booking_blocks_display_and_booking(): void
    {
        $f = $this->fixture();
        $student = $this->student();
        PaidBooking::create(['teacher_id' => $f['teacher']->id, 'student_id' => $student->id, 'availability_id' => $f['availability']->id,
            'starts_at' => $f['body']['starts_at'], 'ends_at' => $f['body']['ends_at'], 'hold_expires_at' => now()->addMinutes(10), 'status' => 'HELD']);
        $this->slots($f)->assertOk()->assertJsonPath('data.slots.0.status', 'booked');
        $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertUnprocessable();
        $this->assertDatabaseCount('individuals', 0);
    }

    public function test_teacher_can_change_future_price_without_repricing_an_existing_booking(): void
    {
        $f = $this->fixture();
        $this->student();
        $id = $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertCreated()->json('data.individual.id');
        Sanctum::actingAs($f['user']);
        $this->patchJson('/api/v1/teacher/subjects/'.$f['course']->id, ['individual_session_price' => 7500, 'individual_currency' => 'USD'])
            ->assertOk()->assertJsonPath('data.individual_session_price', '7500.00')->assertJsonPath('data.individual_currency', 'USD');
        $this->assertSame('5000.00', Individual::findOrFail($id)->session_price);
        $this->assertSame('SYP', Individual::findOrFail($id)->currency);
        $other = $this->fixture();
        $this->patchJson('/api/v1/teacher/subjects/'.$other['course']->id, ['individual_session_price' => 1])->assertNotFound();
        $this->patchJson('/api/v1/teacher/subjects/'.$f['course']->id, ['individual_session_price' => -1])->assertUnprocessable();
        $this->patchJson('/api/v1/teacher/subjects/'.$f['course']->id, ['individual_currency' => 'usd'])->assertUnprocessable();
        $this->getJson('/api/v1/teachers/'.$f['teacher']->id.'/subjects')->assertOk()->assertJsonPath('data.0.individual_session_price', '7500.00');
    }

    public function test_disabled_course_subject_and_teacher_cannot_be_booked(): void
    {
        $f = $this->fixture();
        $this->student();
        foreach ([$f['course'], $f['course']->subject, $f['teacher'], $f['user']] as $model) {
            $old = $model->status;
            $model->update(['status' => $model instanceof Teacher || $model instanceof User ? 'suspended' : 'inactive']);
            $this->postJson('/api/v1/student/individual-bookings', $f['body'])->assertNotFound();
            $model->update(['status' => $old]);
        }
        $this->assertDatabaseCount('individuals', 0);
    }

    public function test_offer_routes_are_removed_and_archive_model_cannot_write(): void
    {
        $f = $this->fixture();
        $this->student();
        $this->getJson('/api/v1/student/teachers/'.$f['teacher']->id.'/physical/individual-offers')->assertNotFound();
        Sanctum::actingAs($f['user']);
        $this->getJson('/api/v1/teacher/individual-offers')->assertNotFound();
        $this->postJson('/api/v1/teacher/individual-offers', [])->assertNotFound();
        $this->patchJson('/api/v1/teacher/individual-offers/1', [])->assertNotFound();
        $this->expectException(\LogicException::class);
        IndividualOffer::create(['title' => 'must not write']);
    }

    public function test_migration_preserves_old_booking_scope_price_archive_and_can_be_repeated_and_rolled_back(): void
    {
        $f = $this->fixture();
        $student = $this->student();
        $migration = require database_path('migrations/2026_11_04_000001_book_individual_sessions_without_offers.php');
        $migration->down();
        $offer = DB::table('individual_offers')->insertGetId(['teacher_subject_id' => $f['course']->id, 'teaching_scope_type' => 'subject', 'teaching_scope_id' => $f['course']->id, 'title' => 'Old offer', 'session_price' => 1234, 'currency' => 'USD', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $individual = DB::table('individuals')->insertGetId(['student_id' => $student->id, 'teacher_subject_id' => $f['course']->id, 'individual_offer_id' => $offer,
            'teaching_scope_type' => 'lesson', 'teaching_scope_id' => 99, 'session_price' => 800, 'currency' => 'SYP', 'status' => 'active']);
        $session = Session::create(['teacher_id' => $f['teacher']->id, 'type' => 'individual', 'type_id' => $individual, 'starts_at' => $f['body']['starts_at'], 'ends_at' => $f['body']['ends_at'], 'status' => 'scheduled']);
        $before = DB::table('sessions')->where('id', $session->id)->first();
        $migration->up();
        $this->assertFalse(Schema::hasColumn('individuals', 'individual_offer_id'));
        $row = DB::table('individuals')->find($individual);
        $this->assertSame($offer, (int) $row->legacy_individual_offer_id);
        $this->assertSame('Old offer', json_decode($row->legacy_offer_snapshot, true)['offer']['title']);
        $this->assertEquals(800, $row->session_price);
        $this->assertSame('lesson', $row->teaching_scope_type);
        $this->assertEquals(99, $row->teaching_scope_id);
        $this->assertSame('1234.00', $f['course']->fresh()->individual_session_price);
        $this->assertSame('USD', $f['course']->fresh()->individual_currency);
        $this->assertEquals($before, DB::table('sessions')->where('id', $session->id)->first());
        foreach (Schema::getForeignKeys('individuals') as $key) {
            $this->assertNotContains('legacy_individual_offer_id', $key['columns']);
        }
        $migration->up();
        $this->assertEquals($row, DB::table('individuals')->find($individual));
        $f['course']->update(['individual_session_price' => null]);
        $migration->up();
        $this->assertNull($f['course']->fresh()->individual_session_price);
        $migration->down();
        $this->assertSame($offer, (int) DB::table('individuals')->find($individual)->individual_offer_id);
        $this->assertEquals($before, DB::table('sessions')->where('id', $session->id)->first());
        $migration->up();
    }

    public function test_migration_does_not_guess_conflicting_or_unit_offer_prices(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $migration = require database_path('migrations/2026_11_04_000001_book_individual_sessions_without_offers.php');
        $migration->down();
        foreach ([[$f, 'subject', 10], [$f, 'subject', 20], [$other, 'unit', 30]] as [$fixture, $scope, $price]) {
            DB::table('individual_offers')->insert(['teacher_subject_id' => $fixture['course']->id, 'teaching_scope_type' => $scope, 'teaching_scope_id' => $fixture['course']->id, 'title' => 'Archive', 'session_price' => $price, 'currency' => 'SYP', 'status' => 'active']);
        }
        $migration->up();
        $this->assertNull($f['course']->fresh()->individual_session_price);
        $this->assertNull($other['course']->fresh()->individual_session_price);
        $this->assertDatabaseCount('individual_offers', 3);
    }
}
