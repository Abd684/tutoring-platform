<?php

namespace Tests\Feature;

use App\Models\Content;
use App\Models\Device;
use App\Models\Governortate;
use App\Models\Lesson;
use App\Models\Region;
use App\Models\School;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\TeacherSubject;
use App\Models\Unit;
use App\Models\User;
use App\Services\OnlinePricing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnlineUpgradeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function data(): array
    {
        $g = Governortate::create(['name' => 'Province']);
        $region = Region::create(['name' => 'Region', 'governortate_id' => $g->id]);
        $school = School::create(['name' => 'School', 'region_id' => $region->id]);

        return ['name' => 'Student', 'phone' => '0991234567', 'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'region_id' => $region->id, 'school_id' => $school->id, 'grade' => '12', 'device_uuid' => 'new-device', 'platform' => 'android'];
    }

    public function test_student_registration_logs_in_and_creates_device_session_atomically_with_full_profile(): void
    {
        $d = $this->data();
        $response = $this->postJson('/api/v1/student/register', $d)->assertCreated()->assertJsonStructure(['access_token', 'refresh_token', 'student', 'user'])->assertJsonMissingPath('user.email');
        $this->withToken($response->json('access_token'))->getJson('/api/v1/student/profile')->assertOk()->assertJsonPath('student.grade', '12')->assertJsonPath('user.phone', $d['phone']);
        $this->assertDatabaseCount('student_devices', 1);
        $this->assertDatabaseCount('device_sessions', 1);
    }

    public function test_invalid_device_rolls_back_the_entire_registration(): void
    {
        $d = $this->data();
        Device::create(['device_uuid' => 'new-device', 'platform' => 'android', 'status' => 'blocked']);
        $this->postJson('/api/v1/student/register', $d)->assertForbidden();
        $this->assertDatabaseMissing('users', ['phone' => $d['phone']]);
        $this->assertDatabaseCount('students', 0);
    }

    public function test_teacher_can_login_after_registration_but_only_view_identity_until_verification(): void
    {
        $d = $this->data();
        $d['phone'] = '0999876543';
        $res = $this->postJson('/api/v1/teacher/register', $d)->assertCreated()->assertJsonPath('user.can_teach', false);
        $token = $res->json('access_token');
        $this->withToken($token)->getJson('/api/v1/teacher/me')->assertOk()->assertJsonPath('can_teach', false);
        $this->withToken($token)->getJson('/api/v1/teacher/sessions')->assertForbidden()->assertJsonPath('code', 'TEACHER_NOT_VERIFIED');
        User::where('phone', $d['phone'])->firstOrFail()->teacher->update(['status' => 'active']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/teacher/sessions')->assertOk();
    }

    public function test_profile_accepts_name_and_image_and_prohibits_phone_changes(): void
    {
        Storage::fake('public');
        $u = User::factory()->create();
        Student::create(['user_id' => $u->id, 'status' => 'active']);
        Sanctum::actingAs($u);
        $this->postJson('/api/v1/student/profile', ['name' => 'New name', 'profile_image' => UploadedFile::fake()->image('avatar.png')])->assertOk()->assertJsonPath('user.name', 'New name');
        $this->assertNotNull($u->fresh()->avatar_path);
        $this->patchJson('/api/v1/student/profile', ['phone' => '0999999999'])->assertUnprocessable();
    }

    public function test_allocation_rounding_preserves_exact_purchase_total(): void
    {
        $teacher = Teacher::create(['user_id' => User::factory()->create(['role' => 'teacher'])->id, 'status' => 'active']);
        $course = TeacherSubject::create(['teacher_id' => $teacher->id, 'subject_id' => Subject::create(['name' => 'Math', 'status' => 'active'])->id, 'price' => 1, 'status' => 'active']);
        $unit = Unit::create(['teacher_subject_id' => $course->id, 'title' => 'Unit', 'status' => 'published']);
        for ($i = 0; $i < 3; $i++) {
            $l = Lesson::create(['unit_id' => $unit->id, 'title' => 'Lesson '.$i, 'price' => 1, 'status' => 'published']);
            Content::create(['lesson_id' => $l->id, 'title' => 'Video', 'type' => 'video', 'status' => 'published']);
        }
        $snapshot = app(OnlinePricing::class)->snapshot($course, '1.00');
        $this->assertSame(['0.34', '0.33', '0.33'], array_column($snapshot['lessons'], 'allocated_amount'));
        $this->assertSame(100, array_sum(array_map(fn ($l) => OnlinePricing::cents($l['allocated_amount']), $snapshot['lessons'])));
    }
}
