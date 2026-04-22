<?php

namespace Database\Seeders;

use App\Models\ConditionLevel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ConditionSeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = base_path('../ui_test/test_data/conditions.json');

        if (!File::exists($jsonPath)) {
            $jsonPath = base_path('ui_test/test_data/conditions.json');
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: $jsonPath");
            return;
        }

        $conditions = json_decode(File::get($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse JSON: ' . json_last_error_msg());
            return;
        }

        foreach ($conditions as $condition) {
            ConditionLevel::query()->updateOrCreate(
                ['name' => $condition['name']],
                [
                    'description' => $condition['description'],
                    'sort_order' => $condition['sort_order'],
                    'level' => $condition['level'],
                ]
            );
        }

        $this->command->info('Successfully seeded ' . count($conditions) . ' condition levels.');
    }
}