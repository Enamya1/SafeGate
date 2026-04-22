<?php

namespace Database\Seeders;

use App\Models\University;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class UniversitySeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = base_path('../ui_test/test_data/universty.json');

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: $jsonPath");
            return;
        }

        $universities = json_decode(File::get($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse JSON: ' . json_last_error_msg());
            return;
        }

        $data = array_map(function ($uni) {
            return [
                'name' => $uni['name'],
                'domain' => $uni['domain'],
                'pic' => json_encode([$uni['imageUrl']]),
                'address' => $uni['location'],
                'latitude' => $uni['lat'],
                'longitude' => $uni['lng'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }, $universities);

        foreach ($data as $university) {
            University::updateOrCreate(
                ['domain' => $university['domain']],
                $university
            );
        }

        $this->command->info('Successfully seeded ' . count($data) . ' universities.');
    }
}