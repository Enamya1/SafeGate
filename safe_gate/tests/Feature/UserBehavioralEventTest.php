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
            'location' => null,
            'pic' => null,
        ]);

        $dormitory = Dormitory::create([
            'dormitory_name' => 'Dorm A',
            'domain' => 'dorm-a.test.edu',
            'location' => null,
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
