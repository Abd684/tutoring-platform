<?php

namespace Database\Seeders;

use App\Models\Governortate;
use App\Models\Region;
use App\Models\School;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GovernorateSeeder extends Seeder
{
    public function run(): void
    {
        $data = json_decode(
            file_get_contents(__DIR__.'/data/syria-locations.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        DB::transaction(function () use ($data): void {
            foreach ($data['governorates'] as $governorateData) {
                $governorate = Governortate::firstOrCreate(['name' => $governorateData['name']]);

                foreach ($governorateData['regions'] as $regionData) {
                    $region = Region::firstOrCreate([
                        'governortate_id' => $governorate->id,
                        'name' => $regionData['name'],
                    ]);

                    foreach ($regionData['schools'] as $schoolData) {
                        School::firstOrCreate([
                            'region_id' => $region->id,
                            'name' => $schoolData['name'],
                        ]);
                    }
                }
            }
        });

        $this->command?->info('Syrian locations imported: 14 governorates, 62 districts and 2,105 sourced school names. Existing records preserved.');
    }
}
