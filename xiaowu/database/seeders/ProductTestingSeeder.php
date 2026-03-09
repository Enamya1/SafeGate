<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTag;
use App\Models\Tag;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ProductTestingSeeder extends Seeder
{
    public function run(): void
    {
        $university = University::query()->updateOrCreate(
            ['domain' => 'test.xiaowu.local'],
            [
                'name' => 'XiaoWu Test University',
                'website' => 'https://test.xiaowu.local',
                'latitude' => 30.0600000,
                'longitude' => 31.2400000,
                'address' => 'Test Campus Road',
                'pic' => null,
                'contact_email' => 'help@test.xiaowu.local',
                'contact_phone' => '+20-100-000-0000',
                'description' => 'Testing university',
            ]
        );

        $dormA = Dormitory::query()->updateOrCreate(
            ['domain' => 'alpha.test.xiaowu.local'],
            [
                'dormitory_name' => 'Dorm Alpha',
                'latitude' => 30.0610000,
                'longitude' => 31.2410000,
                'address' => 'Alpha Street',
                'description' => 'Primary testing dormitory',
                'full_capacity' => 240,
                'is_active' => true,
                'university_id' => $university->id,
            ]
        );

        $dormB = Dormitory::query()->updateOrCreate(
            ['domain' => 'beta.test.xiaowu.local'],
            [
                'dormitory_name' => 'Dorm Beta',
                'latitude' => 30.0660000,
                'longitude' => 31.2450000,
                'address' => 'Beta Street',
                'description' => 'Secondary testing dormitory',
                'full_capacity' => 180,
                'is_active' => true,
                'university_id' => $university->id,
            ]
        );

        $electronics = Category::query()->updateOrCreate(
            ['name' => 'Electronics'],
            ['description' => 'Tech and gadgets', 'logo' => null, 'parent_id' => null]
        );
        $furniture = Category::query()->updateOrCreate(
            ['name' => 'Furniture'],
            ['description' => 'Dorm furniture', 'logo' => null, 'parent_id' => null]
        );
        $books = Category::query()->updateOrCreate(
            ['name' => 'Books'],
            ['description' => 'Study books', 'logo' => null, 'parent_id' => null]
        );

        $likeNew = ConditionLevel::query()->updateOrCreate(
            ['name' => 'Like New'],
            ['description' => 'Almost unused', 'sort_order' => 1, 'level' => 4]
        );
        $good = ConditionLevel::query()->updateOrCreate(
            ['name' => 'Good'],
            ['description' => 'Good condition', 'sort_order' => 2, 'level' => 3]
        );
        $fair = ConditionLevel::query()->updateOrCreate(
            ['name' => 'Fair'],
            ['description' => 'Visible usage', 'sort_order' => 3, 'level' => 2]
        );

        $tagLaptop = Tag::query()->updateOrCreate(['name' => 'laptop']);
        $tagPhone = Tag::query()->updateOrCreate(['name' => 'phone']);
        $tagStudy = Tag::query()->updateOrCreate(['name' => 'study']);
        $tagFurniture = Tag::query()->updateOrCreate(['name' => 'furniture']);

        $sellerA = User::query()->updateOrCreate(
            ['email' => 'seller.alpha@test.xiaowu.local'],
            [
                'full_name' => 'Seller Alpha',
                'username' => 'seller_alpha',
                'password' => Hash::make('password123'),
                'dormitory_id' => $dormA->id,
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $sellerB = User::query()->updateOrCreate(
            ['email' => 'seller.beta@test.xiaowu.local'],
            [
                'full_name' => 'Seller Beta',
                'username' => 'seller_beta',
                'password' => Hash::make('password123'),
                'dormitory_id' => $dormB->id,
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'ai.user@test.xiaowu.local'],
            [
                'full_name' => 'AI Test User',
                'username' => 'ai_test_user',
                'password' => Hash::make('password123'),
                'dormitory_id' => $dormA->id,
                'role' => 'user',
                'status' => 'active',
                'email_verified_at' => now(),
            ]
        );

        $products = [
            [
                'seller' => $sellerA,
                'dormitory_id' => $dormA->id,
                'category_id' => $electronics->id,
                'condition_level_id' => $likeNew->id,
                'title' => 'Dell XPS 13 Laptop',
                'description' => '16GB RAM, 512GB SSD',
                'price' => 850.00,
                'status' => 'available',
                'tags' => [$tagLaptop, $tagStudy],
            ],
            [
                'seller' => $sellerA,
                'dormitory_id' => $dormA->id,
                'category_id' => $electronics->id,
                'condition_level_id' => $good->id,
                'title' => 'iPhone 13 128GB',
                'description' => 'Battery health 89%',
                'price' => 520.00,
                'status' => 'available',
                'tags' => [$tagPhone],
            ],
            [
                'seller' => $sellerB,
                'dormitory_id' => $dormB->id,
                'category_id' => $books->id,
                'condition_level_id' => $fair->id,
                'title' => 'Calculus Textbook',
                'description' => 'Some highlights inside',
                'price' => 35.00,
                'status' => 'available',
                'tags' => [$tagStudy],
            ],
            [
                'seller' => $sellerB,
                'dormitory_id' => $dormB->id,
                'category_id' => $furniture->id,
                'condition_level_id' => $good->id,
                'title' => 'Wooden Study Desk',
                'description' => 'Good for dorm rooms',
                'price' => 110.00,
                'status' => 'available',
                'tags' => [$tagFurniture],
            ],
            [
                'seller' => $sellerA,
                'dormitory_id' => $dormA->id,
                'category_id' => $electronics->id,
                'condition_level_id' => $good->id,
                'title' => 'Samsung Galaxy S21',
                'description' => 'Minor scratches',
                'price' => 390.00,
                'status' => 'sold',
                'tags' => [$tagPhone],
            ],
        ];

        foreach ($products as $index => $item) {
            $product = Product::query()->updateOrCreate(
                [
                    'seller_id' => $item['seller']->id,
                    'title' => $item['title'],
                ],
                [
                    'dormitory_id' => $item['dormitory_id'],
                    'category_id' => $item['category_id'],
                    'condition_level_id' => $item['condition_level_id'],
                    'description' => $item['description'],
                    'price' => $item['price'],
                    'status' => $item['status'],
                    'modified_by' => null,
                    'modification_reason' => null,
                ]
            );

            ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'image_url' => 'https://picsum.photos/seed/xiaowu-'.$product->id.'/1024/768',
                ],
                [
                    'image_thumbnail_url' => 'https://picsum.photos/seed/xiaowu-thumb-'.$product->id.'/320/240',
                    'is_primary' => true,
                ]
            );

            ProductTag::query()->where('product_id', $product->id)->delete();
            foreach ($item['tags'] as $tag) {
                ProductTag::query()->create([
                    'product_id' => $product->id,
                    'tag_id' => $tag->id,
                ]);
            }

            ProductImage::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'image_url' => 'https://picsum.photos/seed/xiaowu-alt-'.($index + 1).'/1024/768',
                ],
                [
                    'image_thumbnail_url' => 'https://picsum.photos/seed/xiaowu-alt-thumb-'.($index + 1).'/320/240',
                    'is_primary' => false,
                ]
            );
        }
    }
}
