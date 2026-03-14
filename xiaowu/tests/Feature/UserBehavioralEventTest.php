<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Product;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserBehavioralEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_behavioral_events_endpoint_returns_unauthenticated_without_token(): void
    {
        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/behavioral_events', [
                'event_type' => 'view',
            ]);

        $response->assertStatus(401);
    }

    public function test_non_user_role_cannot_store_behavioral_event(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/behavioral_events', [
                'event_type' => 'view',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_store_behavioral_event_with_product_id(): void
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
            'parent_id' => null,
        ]);

        $conditionLevel = ConditionLevel::create([
            'name' => 'Good',
            'description' => null,
            'sort_order' => 1,
        ]);

        $seller = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Laptop',
            'description' => null,
            'price' => 100.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/behavioral_events', [
                'event_type' => 'view',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => [
                    'source' => 'product_page',
                ],
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Behavioral event stored successfully')
            ->assertJsonPath('event.user_id', $viewer->id)
            ->assertJsonPath('event.event_type', 'view')
            ->assertJsonPath('event.product_id', $product->id);

        $this->assertDatabaseHas('behavioral_events', [
            'user_id' => $viewer->id,
            'event_type' => 'view',
            'product_id' => $product->id,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
        ]);
    }

    public function test_search_products_creates_search_events(): void
    {
        $university = University::create([
            'name' => 'Search University',
            'domain' => 'search.edu',
            'latitude' => null,
            'longitude' => null,
            'pic' => null,
        ]);

        $dormitory = Dormitory::create([
            'dormitory_name' => 'Search Dorm',
            'domain' => 'search-dorm.edu',
            'latitude' => null,
            'longitude' => null,
            'is_active' => true,
            'university_id' => $university->id,
        ]);

        $category = Category::create([
            'name' => 'Accessories',
            'parent_id' => null,
        ]);

        $conditionLevel = ConditionLevel::create([
            'name' => 'Fair',
            'description' => null,
            'sort_order' => 1,
        ]);

        $seller = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $viewer = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Searchable Cable',
            'description' => 'Charging cable',
            'price' => 10.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($viewer);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/search/products?q=Searchable');

        $response
            ->assertStatus(200)
            ->assertJsonPath('products.0.id', $product->id);

        $this->assertDatabaseHas('behavioral_events', [
            'user_id' => $viewer->id,
            'event_type' => 'search',
            'product_id' => $product->id,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
        ]);
    }

    public function test_user_can_get_product_engagement(): void
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
            'parent_id' => null,
        ]);

        $conditionLevel = ConditionLevel::create([
            'name' => 'Good',
            'description' => null,
            'sort_order' => 1,
        ]);

        $seller = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $viewer1 = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
            'profile_picture' => 'https://example.com/pic1.jpg',
        ]);

        $viewer2 = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
            'profile_picture' => 'https://example.com/pic2.jpg',
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Laptop',
            'description' => null,
            'price' => 100.00,
            'status' => 'available',
        ]);

        $older = now()->subMinutes(10);
        $newer = now()->subMinutes(1);

        DB::table('behavioral_events')->insert([
            [
                'user_id' => $viewer1->id,
                'event_type' => 'view',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => $older,
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => $older,
                'updated_at' => $older,
            ],
            [
                'user_id' => $viewer2->id,
                'event_type' => 'view',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => $newer,
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => $newer,
                'updated_at' => $newer,
            ],
            [
                'user_id' => $viewer1->id,
                'event_type' => 'click',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => $older,
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => $older,
                'updated_at' => $older,
            ],
            [
                'user_id' => $viewer1->id,
                'event_type' => 'click',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => $older,
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => $older,
                'updated_at' => $older,
            ],
            [
                'user_id' => $viewer2->id,
                'event_type' => 'click',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => $newer,
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => $newer,
                'updated_at' => $newer,
            ],
        ]);

        Sanctum::actingAs($seller);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->getJson('/api/user/products/'.$product->id.'/engagement');

        $response
            ->assertStatus(200)
            ->assertJsonPath('views', 2)
            ->assertJsonPath('clicks', 3)
            ->assertJsonPath('recent_clickers.0.id', $viewer2->id)
            ->assertJsonPath('recent_clickers.1.id', $viewer1->id)
            ->assertJsonCount(2, 'recent_clickers');
    }

    public function test_store_behavioral_event_with_unknown_product_returns_404(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/behavioral_events', [
                'event_type' => 'view',
                'product_id' => 999999,
            ]);

        $response->assertStatus(404);
    }
}
