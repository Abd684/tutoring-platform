<?php

namespace Tests\Feature;

use App\Models\Governortate;
use App\Models\Region;
use App\Models\School;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\GovernorateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GovernorateSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_locations_have_correct_parent_relationships_and_public_api_results(): void
    {
        $this->seed(GovernorateSeeder::class);

        $this->assertDatabaseCount('governortates', 14);
        $this->assertDatabaseCount('regions', 62);
        $this->assertDatabaseCount('schools', 2105);

        $latakia = Governortate::where('name', 'اللاذقية')->firstOrFail();
        $jableh = Region::where('governortate_id', $latakia->id)->where('name', 'جبلة')->firstOrFail();
        $school = School::where('region_id', $jableh->id)->where('name', 'مدرسة بتغرامو الثانوية')->firstOrFail();

        $this->getJson('/api/v1/governorates')->assertOk()->assertJsonCount(14, 'data');
        $this->getJson('/api/v1/governorates/'.$latakia->id.'/regions')
            ->assertOk()->assertJsonCount(4, 'data')->assertJsonFragment(['id' => $jableh->id, 'name' => 'جبلة']);
        $this->getJson('/api/v1/regions/'.$jableh->id.'/schools')
            ->assertOk()->assertJsonFragment(['id' => $school->id, 'name' => 'مدرسة بتغرامو الثانوية']);
    }

    public function test_repeated_import_preserves_existing_student_locations_without_duplicates(): void
    {
        $governorate = Governortate::create(['name' => 'دمشق']);
        $region = Region::create(['governortate_id' => $governorate->id, 'name' => 'المزة']);
        $school = School::create(['region_id' => $region->id, 'name' => 'مدرسة موجودة مسبقاً']);
        $user = User::factory()->create(['role' => 'student', 'region_id' => $region->id]);
        $student = Student::create(['user_id' => $user->id, 'school_id' => $school->id]);

        $this->seed(GovernorateSeeder::class);
        $this->seed(GovernorateSeeder::class);

        $this->assertDatabaseCount('governortates', 14);
        $this->assertDatabaseCount('regions', 63);
        $this->assertDatabaseCount('schools', 2106);
        $this->assertSame($school->id, $student->fresh()->school_id);
        $this->assertSame($region->id, $user->fresh()->region_id);
        $this->assertSame($governorate->id, Governortate::where('name', 'دمشق')->firstOrFail()->id);
    }
}
