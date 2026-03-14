<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserVisualSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_search_products_by_image(): void
    {
        $university = University::create([
            'name' => 'Visual University',
            'domain' => 'visual.edu',
            'latitude' => null,
            'longitude' => null,
            'pic' => null,
        ]);

        $dormitory = Dormitory::create([
            'dormitory_name' => 'Visual Dorm',
            'domain' => 'visual-dorm.edu',
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
            'name' => 'Like New',
            'description' => null,
            'sort_order' => 1,
        ]);

        $seller = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $queryUser = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $productA = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Product A',
            'description' => 'First product',
            'price' => 200.00,
            'status' => 'available',
        ]);

        $productB = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Product B',
            'description' => 'Second product',
            'price' => 300.00,
            'status' => 'available',
        ]);

        ProductImage::create([
            'product_id' => $productA->id,
            'image_url' => '/storage/a.jpg',
            'image_thumbnail_url' => '/storage/a-thumb.jpg',
            'is_primary' => true,
        ]);

        ProductImage::create([
            'product_id' => $productB->id,
            'image_url' => '/storage/b.jpg',
            'image_thumbnail_url' => '/storage/b-thumb.jpg',
            'is_primary' => true,
        ]);

        Http::fake([
            'http://127.0.0.1:8001/py/api/user/search/visual' => Http::response([
                'message' => 'Visual search completed successfully',
                'product_ids' => [$productB->id, $productA->id],
                'matches' => [
                    ['product_id' => $productB->id, 'score' => 0.95],
                    ['product_id' => $productA->id, 'score' => 0.65],
                ],
                'model_name' => 'ViT-B-32',
                'embedding_dim' => 512,
            ], 200),
        ]);

        Sanctum::actingAs($queryUser);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer testing-token')
            ->post('/api/user/search/visual', [
                'image' => UploadedFile::fake()->createWithContent(
                    'query.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                ),
                'top_k' => 5,
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('products.0.id', $productB->id)
            ->assertJsonPath('products.1.id', $productA->id)
            ->assertJsonPath('products.0.visual_similarity_score', 0.95)
            ->assertJsonPath('products.1.visual_similarity_score', 0.65);

        $this->assertDatabaseHas('behavioral_events', [
            'user_id' => $queryUser->id,
            'event_type' => 'search',
            'product_id' => $productB->id,
        ]);

        $this->assertDatabaseMissing('behavioral_events', [
            'user_id' => $queryUser->id,
            'event_type' => 'search',
            'product_id' => $productA->id,
        ]);

        $this->assertSame(1, DB::table('behavioral_events')
            ->where('user_id', $queryUser->id)
            ->where('event_type', 'search')
            ->count());
    }

    public function test_non_user_role_cannot_search_products_by_image(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/search/visual', [
                'image' => UploadedFile::fake()->createWithContent(
                    'query.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                ),
            ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized: Only users can access this endpoint.');
    }
}
