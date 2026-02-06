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
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserUploadProductImagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_upload_images_to_product(): void
    {
        Storage::fake('public');

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

        $product = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Keyboard',
            'price' => 20.00,
            'status' => 'available',
        ]);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/products/'.$product->id.'/images', [
                'primary_image_index' => 1,
                'images' => [
                    UploadedFile::fake()->createWithContent(
                        'img1.png',
                        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                    ),
                    UploadedFile::fake()->createWithContent(
                        'img2.png',
                        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                    ),
                ],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'is_primary' => 0,
        ]);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'is_primary' => 1,
        ]);
    }

    public function test_owner_can_upload_image_urls_with_thumbnail_urls_to_product(): void
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

        $product = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Keyboard',
            'price' => 20.00,
            'status' => 'available',
        ]);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/products/'.$product->id.'/images', [
                'primary_image_index' => 0,
                'image_urls' => [
                    '/img1.png',
                    'https://example.com/img2.png',
                ],
                'image_thumbnail_urls' => [
                    '/thumb1.png',
                    'https://example.com/thumb2.png',
                ],
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'image_url' => '/img1.png',
            'image_thumbnail_url' => '/thumb1.png',
            'is_primary' => 1,
        ]);

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'image_url' => 'https://example.com/img2.png',
            'image_thumbnail_url' => 'https://example.com/thumb2.png',
            'is_primary' => 0,
        ]);

        $this->assertSame(2, ProductImage::query()->where('product_id', $product->id)->count());
    }

    public function test_upload_image_urls_with_mismatched_thumbnail_urls_count_returns_422(): void
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

        $product = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Keyboard',
            'price' => 20.00,
            'status' => 'available',
        ]);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/products/'.$product->id.'/images', [
                'image_urls' => [
                    '/img1.png',
                    '/img2.png',
                ],
                'image_thumbnail_urls' => [
                    '/thumb1.png',
                ],
            ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath('errors.image_thumbnail_urls.0', 'The image_thumbnail_urls count must match image_urls count.');
    }

    public function test_non_owner_cannot_upload_images(): void
    {
        Storage::fake('public');

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

        $owner = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $other = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product = Product::create([
            'seller_id' => $owner->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Mouse',
            'price' => 10.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($other);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/products/'.$product->id.'/images', [
                'images' => [
                    UploadedFile::fake()->createWithContent(
                        'img1.png',
                        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                    ),
                ],
            ]);

        $response->assertStatus(403);
    }
}
