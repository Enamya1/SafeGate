<?php

namespace Database\Seeders;

use App\Models\Dormitory;
use App\Models\University;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class DormitorySeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = base_path('../ui_test/test_data/dorms.json');

        if (!File::exists($jsonPath)) {
            $jsonPath = base_path('ui_test/test_data/dorms.json');
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: $jsonPath");
            return;
        }

        $dorms = json_decode(File::get($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse JSON: ' . json_last_error_msg());
            return;
        }

        $count = 0;
        foreach ($dorms as $dorm) {
            $university = University::where('domain', $dorm['university_domain'])->first();

            if (!$university) {
                $this->command->warn("University not found: {$dorm['university_domain']}");
                continue;
            }

            Dormitory::updateOrCreate(
                ['domain' => $dorm['domain']],
                [
                    'dormitory_name' => $dorm['dormitory_name'],
                    'latitude' => $dorm['latitude'],
                    'longitude' => $dorm['longitude'],
                    'address' => $dorm['address'],
                    'description' => $dorm['description'],
                    'full_capacity' => $dorm['full_capacity'],
                    'is_active' => $dorm['is_active'],
                    'university_id' => $university->id,
                ]
            );
            $count++;
        }

        $this->command->info("Successfully seeded $count dormitories.");
    }
}