<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Product;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserExchangeProductCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_exchange_listing_with_image_urls_when_creating_new_product(): void
    {
        $university = University::create([
            'name' => 'Test University',
            'domain' => 'test.edu',
            'latitude' => null,
            'longitude' => null,
            'pic' => null,
        ]);

        $dormitory = Dormitory::create([
            'dormitory_name' => 'Dorm A',
            'domain' => 'dorm-a.test.edu',
            'latitude' => null,
            'longitude' => null,
            'is_active' => true,
            'university_id' => $university->id,
        ]);

        $category = Category::create([
            'name' => 'Electronics',
        ]);

        $conditionLevel = ConditionLevel::create([
            'name' => 'Good',
            'sort_order' => 1,
        ]);

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/exchange-products', [
                'exchange_type' => 'exchange_only',
                'category_id' => $category->id,
                'condition_level_id' => $conditionLevel->id,
                'title' => 'Mechanical Keyboard',
                'description' => 'RGB keyboard',
                'price' => 35.00,
                'currency' => 'USD',
                'primary_image_index' => 1,
                'image_urls' => [
                    '/img1.png',
                    '/img2.png',
                ],
                'image_thumbnail_urls' => [
                    '/thumb1.png',
                    '/thumb2.png',
                ],
            ]);

        $response->assertStatus(201);

        $product = Product::query()->firstOrFail();

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'image_url' => '/img1.png',
            'image_thumbnail_url' => '/thumb1.png',
            'is_primary' => 0,
        ]);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'image_url' => '/img2.png',
            'image_thumbnail_url' => '/thumb2.png',
            'is_primary' => 1,
        ]);

        $this->assertDatabaseHas('exchange_products', [
            'product_id' => $product->id,
            'exchange_type' => 'exchange_only',
            'exchange_status' => 'open',
        ]);
    }
}
