<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $jsonPath = base_path('../ui_test/test_data/categories.json');

        if (!File::exists($jsonPath)) {
            $jsonPath = base_path('ui_test/test_data/categories.json');
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("JSON file not found at: $jsonPath");
            return;
        }

        $categories = json_decode(File::get($jsonPath), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse JSON: ' . json_last_error_msg());
            return;
        }

        $categoryMap = [];

        foreach ($categories as $cat) {
            $category = Category::query()->updateOrCreate(
                ['name' => $cat['name']],
                [
                    'description' => $cat['description'],
                    'logo' => $cat['logo'] ?? null,
                    'parent_id' => null,
                ]
            );
            $categoryMap[$cat['name']] = $category->id;
        }

        foreach ($categories as $cat) {
            if ($cat['parent_name'] && isset($categoryMap[$cat['parent_name']])) {
                Category::where('name', $cat['name'])->update(['parent_id' => $categoryMap[$cat['parent_name']]]);
            }
        }

        $this->command->info('Successfully seeded ' . count($categories) . ' categories.');
    }
}