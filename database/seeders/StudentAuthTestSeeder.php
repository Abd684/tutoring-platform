<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Governortate;
use App\Models\RefreshToken;
use App\Models\Region;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentDevice;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class StudentAuthTestSeeder extends Seeder
{
    public function run(): void
    {
        $data = DB::transaction(function (): array {
            $governortate = Governortate::updateOrCreate(
                ['name' => 'Damascus'],
                []
            );

            $region = Region::updateOrCreate(
                [
                    'name' => 'Mazzeh',
                    'governortate_id' => $governortate->id,
                ],
                []
            );

            $school = School::updateOrCreate(
                [
                    'name' => 'Student Auth Test School',
                    'region_id' => $region->id,
                ],
                ['student_id' => null]
            );

            $studentUser = User::updateOrCreate(
                ['email' => 'student.auth@test.com'],
                [
                    'name' => 'Student Auth Test',
                    'phone' => '0991111111',
                    'password_hash' => Hash::make('Student123!'),
                    'role' => 'student',
                    'status' => 'active',
                    'region_id' => $region->id,
                ]
            );

            $student = Student::updateOrCreate(
                ['user_id' => $studentUser->id],
                [
                    'grade' => '12',
                    'school_id' => $school->id,
                    'status' => 'active',
                ]
            );

            // Reset only this test student's auth/device state so the login,
            // logout, refresh and transfer scenarios can be repeated safely.
            $studentUser->tokens()->delete();
            RefreshToken::query()->where('user_id', $studentUser->id)->delete();

            StudentDevice::query()
                ->where('student_id', $student->id)
                ->delete();

            Device::query()
                ->whereIn('device_uuid', [
                    'student-auth-device-001',
                    'student-auth-device-002',
                ])
                ->whereDoesntHave('studentDevices')
                ->delete();

            $admin = User::updateOrCreate(
                ['email' => 'admin.auth@test.com'],
                [
                    'name' => 'Student Auth Admin',
                    'phone' => '0992222222',
                    'password_hash' => Hash::make('Admin123!'),
                    'role' => 'company_admin',
                    'status' => 'active',
                    'region_id' => $region->id,
                ]
            );

            $admin->tokens()->where('name', 'student-auth-admin-test')->delete();

            $adminToken = $admin->createToken(
                'student-auth-admin-test',
                ['*'],
                now()->addDay()
            )->plainTextToken;

            return [
                'governortate_id' => $governortate->id,
                'region_id' => $region->id,
                'school_id' => $school->id,
                'student_user_id' => $studentUser->id,
                'student_id' => $student->id,
                'admin_user_id' => $admin->id,
                'admin_token' => $adminToken,
            ];
        });

        $this->command?->newLine();
        $this->command?->info('Student auth test data is ready.');
        $this->command?->line('Student: student.auth@test.com / Student123!');
        $this->command?->line('Admin:   admin.auth@test.com / Admin123!');
        $this->command?->line('Region ID: '.$data['region_id'].' | School ID: '.$data['school_id']);
        $this->command?->line('Student User ID: '.$data['student_user_id'].' | Student ID: '.$data['student_id']);
        $this->command?->line('Admin User ID: '.$data['admin_user_id']);
        $this->command?->warn('Admin Bearer token (testing only):');
        $this->command?->line($data['admin_token']);
        $this->command?->newLine();
    }
}
