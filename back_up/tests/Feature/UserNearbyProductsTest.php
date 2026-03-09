<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTag;
use App\Models\Tag;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserNearbyProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_get_nearby_products_with_distance_and_meta(): void
    {
        $university = University::create([
            'name' => 'Nearby University',
            'domain' => 'nearby.test.edu',
            'latitude' => 30.0600000,
            'longitude' => 31.2400000,
            'pic' => null,
        ]);

        $nearDorm = Dormitory::create([
            'dormitory_name' => 'Maple Hall',
            'domain' => 'maple.test.edu',
            'latitude' => 30.0610000,
            'longitude' => 31.2410000,
            'is_active' => true,
            'university_id' => $university->id,
            'address' => 'North Campus',
        ]);

        $farDorm = Dormitory::create([
            'dormitory_name' => 'Far Hall',
            'domain' => 'far.test.edu',
            'latitude' => 30.2000000,
            'longitude' => 31.5000000,
            'is_active' => true,
            'university_id' => $university->id,
            'address' => 'South Campus',
        ]);

        $category = Category::create([
            'name' => 'Electronics',
            'parent_id' => null,
            'logo' => '💻',
        ]);

        $conditionLevel = ConditionLevel::create([
            'name' => 'Like New',
            'description' => 'Barely used',
            'sort_order' => 1,
            'level' => 4,
        ]);

        $tag = Tag::create(['name' => 'Gaming']);

        $seller = User::factory()->create([
            'dormitory_id' => $nearDorm->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'dormitory_id' => $nearDorm->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $nearProduct = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $nearDorm->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Sony WH-1000XM4 Headphones',
            'description' => 'Best noise-cancelling headphones',
            'price' => 180.00,
            'status' => 'available',
        ]);

        ProductImage::create([
            'product_id' => $nearProduct->id,
            'image_url' => 'https://img.test/headphones.jpg',
            'image_thumbnail_url' => 'https://img.test/headphones-thumb.jpg',
            'is_primary' => true,
        ]);

        ProductTag::create([
            'product_id' => $nearProduct->id,
            'tag_id' => $tag->id,
        ]);

        Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $farDorm->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Far Product',
            'description' => 'Too far',
            'price' => 100.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/nearby?lat=30.061&lng=31.241&distance_km=5');

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'ok')
            ->assertJsonPath('center.lat', 30.061)
            ->assertJsonPath('center.lng', 31.241)
            ->assertJsonPath('products.0.id', $nearProduct->id)
            ->assertJsonPath('products.0.seller.id', $seller->id)
            ->assertJsonPath('products.0.dormitory.id', $nearDorm->id)
            ->assertJsonPath('products.0.category.id', $category->id)
            ->assertJsonPath('products.0.condition_level.id', $conditionLevel->id)
            ->assertJsonPath('products.0.tags.0.id', $tag->id)
            ->assertJsonPath('meta.categories.0.id', $category->id)
            ->assertJsonPath('meta.condition_levels.0.id', $conditionLevel->id)
            ->assertJsonCount(1, 'products');

        $distance = $response->json('products.0.distance_km');
        $this->assertIsNumeric($distance);
        $this->assertLessThanOrEqual(5.0, (float) $distance);
    }

    public function test_user_can_filter_nearby_products(): void
    {
        $university = University::create([
            'name' => 'Filter University',
            'domain' => 'filter.test.edu',
            'latitude' => 30.1000000,
            'longitude' => 31.1000000,
            'pic' => null,
        ]);

        $dorm = Dormitory::create([
            'dormitory_name' => 'Alpha Residence',
            'domain' => 'alpha.filter.edu',
            'latitude' => 30.1010000,
            'longitude' => 31.1010000,
            'is_active' => true,
            'university_id' => $university->id,
            'address' => 'Alpha Block',
        ]);

        $electronics = Category::create([
            'name' => 'Electronics',
            'parent_id' => null,
            'logo' => '💻',
        ]);
        $furniture = Category::create([
            'name' => 'Furniture',
            'parent_id' => null,
            'logo' => '🪑',
        ]);

        $likeNew = ConditionLevel::create([
            'name' => 'Like New',
            'description' => null,
            'sort_order' => 1,
            'level' => 4,
        ]);
        $good = ConditionLevel::create([
            'name' => 'Good',
            'description' => null,
            'sort_order' => 2,
            'level' => 3,
        ]);

        $seller = User::factory()->create([
            'dormitory_id' => $dorm->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'dormitory_id' => $dorm->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $matching = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dorm->id,
            'category_id' => $electronics->id,
            'condition_level_id' => $likeNew->id,
            'title' => 'Gaming Laptop',
            'description' => 'Fast and light',
            'price' => 700.00,
            'status' => 'available',
        ]);

        Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dorm->id,
            'category_id' => $furniture->id,
            'condition_level_id' => $good->id,
            'title' => 'Wooden Desk',
            'description' => 'Large desk',
            'price' => 90.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/nearby?lat=30.101&lng=31.101&distance_km=10&category_id='.$electronics->id.'&condition_level_id='.$likeNew->id.'&q=laptop&location_q=Alpha');

        $response
            ->assertStatus(200)
            ->assertJsonCount(1, 'products')
            ->assertJsonPath('products.0.id', $matching->id)
            ->assertJsonPath('products.0.category_id', $electronics->id)
            ->assertJsonPath('products.0.condition_level_id', $likeNew->id);
    }
}
