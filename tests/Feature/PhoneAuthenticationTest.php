<?php

namespace Tests\Feature;

use App\Models\Governortate;
use App\Models\Region;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PhoneAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_students_and_teachers_can_register_without_email_and_login_by_phone(): void
    {
        $province = Governortate::create(['name' => 'Province']);
        $region = Region::create(['name' => 'Region', 'governortate_id' => $province->id]);
        $school = School::create(['name' => 'School', 'region_id' => $region->id]);

        foreach (['student', 'teacher'] as $role) {
            $prefix = $role === 'student' ? '/api/v1/auth' : '/api/v1/teacher';
            $data = ['name' => 'Phone User', 'phone' => $role === 'student' ? '0991234567' : '0997654321',
                'password' => 'Password123!', 'password_confirmation' => 'Password123!',
                'device_uuid' => 'phone-test', 'platform' => 'android', 'region_id' => $region->id, 'school_id' => $school->id, 'grade' => '12'];
            $this->postJson($prefix.'/register', $data)->assertCreated();
            $this->assertDatabaseHas('users', ['phone' => $data['phone'], 'email' => null]);
            $this->postJson($prefix.'/register', $data)->assertUnprocessable()->assertJsonValidationErrors('phone');
            $credentials = ['phone' => $data['phone'], 'password' => $data['password'],
                'device_uuid' => 'phone-test', 'platform' => 'android'];
            $this->postJson($prefix.'/login', $credentials)->assertOk()->assertJsonPath('user.phone', $data['phone']);
            $this->postJson($prefix.'/login', array_replace($credentials, ['password' => 'wrong']))->assertUnauthorized();
            unset($credentials['phone']);
            $this->postJson($prefix.'/login', $credentials + ['email' => 'old@example.com'])
                ->assertUnauthorized();
        }
    }

    public function test_profile_cannot_take_another_accounts_phone(): void
    {
        $student = User::factory()->create();
        $other = User::factory()->create();
        Student::create(['user_id' => $student->id, 'status' => 'active']);
        Sanctum::actingAs($student);
        $this->patchJson('/api/v1/profile', ['phone' => $other->phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }
}
