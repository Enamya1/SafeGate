<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTag;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedTags();
        $this->seedProducts();
    }

    protected function seedTags(): void
    {
        $jsonPath = base_path('../ui_test/test_data/tags.json');
        if (!File::exists($jsonPath)) {
            $jsonPath = base_path('ui_test/test_data/tags.json');
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("Tags JSON file not found");
            return;
        }

        $tags = json_decode(File::get($jsonPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse tags JSON: ' . json_last_error_msg());
            return;
        }

        $count = 0;
        foreach ($tags as $tag) {
            Tag::query()->updateOrCreate(['name' => $tag['name']], ['name' => $tag['name']]);
            $count++;
        }
        $this->command->info("Seeded $count tags.");
    }

    protected function seedProducts(): void
    {
        $jsonPath = base_path('../ui_test/test_data/products.json');
        if (!File::exists($jsonPath)) {
            $jsonPath = base_path('ui_test/test_data/products.json');
        }

        if (!File::exists($jsonPath)) {
            $this->command->error("Products JSON file not found");
            return;
        }

        $products = json_decode(File::get($jsonPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->command->error('Failed to parse products JSON: ' . json_last_error_msg());
            return;
        }

        $count = 0;
        foreach ($products as $prod) {
            $seller = User::where('email', $prod['seller_email'])->first();
            if (!$seller) {
                $this->command->warn("Seller not found: {$prod['seller_email']}");
                continue;
            }

            $dormitory = Dormitory::where('domain', $prod['dormitory_domain'])->first();
            if (!$dormitory) {
                $this->command->warn("Dormitory not found: {$prod['dormitory_domain']}");
                continue;
            }

            $category = Category::where('name', $prod['category_name'])->first();
            if (!$category) {
                $this->command->warn("Category not found: {$prod['category_name']}");
                continue;
            }

            $condition = ConditionLevel::where('name', $prod['condition_name'])->first();
            if (!$condition) {
                $this->command->warn("Condition not found: {$prod['condition_name']}");
                continue;
            }

            $product = Product::updateOrCreate(
                [
                    'seller_id' => $seller->id,
                    'title' => $prod['title'],
                ],
                [
                    'dormitory_id' => $dormitory->id,
                    'category_id' => $category->id,
                    'condition_level_id' => $condition->id,
                    'description' => $prod['description'],
                    'price' => $prod['price'],
                    'currency' => 'USD',
                    'status' => $prod['status'],
                ]
            );

            ProductImage::where('product_id', $product->id)->delete();
            ProductTag::where('product_id', $product->id)->delete();

            if (!empty($prod['images'])) {
                foreach ($prod['images'] as $index => $imageUrl) {
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $imageUrl,
                        'image_thumbnail_url' => str_replace('/1024/', '/320/', $imageUrl),
                        'is_primary' => $index === 0,
                    ]);
                }
            }

            if (!empty($prod['tags'])) {
                foreach ($prod['tags'] as $tagName) {
                    $tag = Tag::where('name', $tagName)->first();
                    if ($tag) {
                        ProductTag::create([
                            'product_id' => $product->id,
                            'tag_id' => $tag->id,
                        ]);
                    }
                }
            }

            $count++;
        }

        $this->command->info("Seeded $count products.");
    }
}