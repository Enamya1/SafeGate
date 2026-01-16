<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTag;
use App\Models\Tag;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCreateProductTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_product_with_image_and_tags(): void
    {
        Storage::fake('public');

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

        $tag1 = Tag::create(['name' => 'Phone']);
        $tag2 = Tag::create(['name' => 'Android']);

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Used Phone',
            'description' => 'Works fine',
            'price' => 99.99,
            'tag_ids' => [$tag1->id, $tag2->id],
            'images' => [
                UploadedFile::fake()->createWithContent(
                    'phone.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                ),
                UploadedFile::fake()->createWithContent(
                    'phone2.png',
                    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVQI12P4//8/AwAI/AL+X9x2AAAAAElFTkSuQmCC')
                ),
            ],
        ];

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->post('/api/user/products', $payload);

        $response->assertStatus(201);

        $this->assertDatabaseHas('products', [
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Used Phone',
            'status' => 'available',
        ]);

        $product = Product::query()->firstOrFail();

        $this->assertDatabaseHas('product_images', [
            'product_id' => $product->id,
            'is_primary' => 1,
        ]);

        $this->assertDatabaseHas('product_tags', [
            'product_id' => $product->id,
            'tag_id' => $tag1->id,
        ]);

        $this->assertDatabaseHas('product_tags', [
            'product_id' => $product->id,
            'tag_id' => $tag2->id,
        ]);

        $this->assertSame(2, ProductImage::query()->count());
        $this->assertSame(2, ProductTag::query()->count());
    }

    public function test_user_can_create_product_with_image_urls_and_thumbnail_urls(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Used Laptop',
            'description' => 'Great condition',
            'price' => 250.00,
            'primary_image_index' => 1,
            'image_urls' => [
                '/img1.png',
                'https://example.com/img2.png',
            ],
            'image_thumbnail_urls' => [
                '/thumb1.png',
                'https://example.com/thumb2.png',
            ],
        ];

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/products', $payload);

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
            'image_url' => 'https://example.com/img2.png',
            'image_thumbnail_url' => 'https://example.com/thumb2.png',
            'is_primary' => 1,
        ]);
    }

    public function test_create_product_with_mismatched_thumbnail_urls_count_returns_422(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $payload = [
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Used Laptop',
            'price' => 250.00,
            'image_urls' => [
                '/img1.png',
                '/img2.png',
            ],
            'image_thumbnail_urls' => [
                '/thumb1.png',
            ],
        ];

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/products', $payload);

        $response
            ->assertStatus(422)
            ->assertJsonPath('errors.image_thumbnail_urls.0', 'The image_thumbnail_urls count must match image_urls count.');
    }

    public function test_user_can_list_only_their_uploaded_products(): void
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

        $tag = Tag::create(['name' => 'MyTag']);

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product1 = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'My Product 1',
            'description' => null,
            'price' => 10.00,
            'status' => 'available',
        ]);

        $product2 = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'My Product 2',
            'description' => null,
            'price' => 20.00,
            'status' => 'available',
        ]);

        $otherProduct = Product::create([
            'seller_id' => $otherUser->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Other Product',
            'description' => null,
            'price' => 30.00,
            'status' => 'available',
        ]);

        ProductImage::create([
            'product_id' => $product1->id,
            'image_url' => '/p1.png',
            'image_thumbnail_url' => '/p1_thumb.png',
            'is_primary' => true,
        ]);

        ProductImage::create([
            'product_id' => $product2->id,
            'image_url' => '/p2.png',
            'image_thumbnail_url' => null,
            'is_primary' => true,
        ]);

        ProductTag::create([
            'product_id' => $product1->id,
            'tag_id' => $tag->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/products');

        $response->assertStatus(200)->assertJsonCount(2, 'products');

        $products = $response->json('products');
        $productIds = array_column($products, 'id');

        $this->assertContains($product1->id, $productIds);
        $this->assertContains($product2->id, $productIds);
        $this->assertNotContains($otherProduct->id, $productIds);

        $product1Payload = collect($products)->firstWhere('id', $product1->id);
        $this->assertNotNull($product1Payload);
        $this->assertCount(1, $product1Payload['images'] ?? []);
        $this->assertCount(1, $product1Payload['tags'] ?? []);
        $this->assertSame($tag->id, $product1Payload['tags'][0]['id']);
        $this->assertSame($tag->name, $product1Payload['tags'][0]['name']);
    }

    public function test_user_can_list_products_by_tag_name(): void
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

        $tag = Tag::create(['name' => 'MyTag']);
        $otherTag = Tag::create(['name' => 'OtherTag']);

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product1 = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Product 1',
            'description' => null,
            'price' => 10.00,
            'status' => 'available',
        ]);

        $product2 = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Product 2',
            'description' => null,
            'price' => 20.00,
            'status' => 'sold',
        ]);

        $otherProduct = Product::create([
            'seller_id' => $otherUser->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Other Product',
            'description' => null,
            'price' => 30.00,
            'status' => 'available',
        ]);

        $untaggedProduct = Product::create([
            'seller_id' => $otherUser->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Untagged Product',
            'description' => null,
            'price' => 40.00,
            'status' => 'available',
        ]);

        ProductImage::create([
            'product_id' => $product1->id,
            'image_url' => '/p1.png',
            'image_thumbnail_url' => '/p1_thumb.png',
            'is_primary' => true,
        ]);

        ProductImage::create([
            'product_id' => $product2->id,
            'image_url' => '/p2.png',
            'image_thumbnail_url' => null,
            'is_primary' => true,
        ]);

        ProductImage::create([
            'product_id' => $otherProduct->id,
            'image_url' => '/p3.png',
            'image_thumbnail_url' => null,
            'is_primary' => true,
        ]);

        ProductTag::create([
            'product_id' => $product1->id,
            'tag_id' => $tag->id,
        ]);

        ProductTag::create([
            'product_id' => $product2->id,
            'tag_id' => $tag->id,
        ]);

        ProductTag::create([
            'product_id' => $otherProduct->id,
            'tag_id' => $tag->id,
        ]);

        ProductTag::create([
            'product_id' => $product1->id,
            'tag_id' => $otherTag->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/products/by-tag/'.$tag->name);

        $response
            ->assertStatus(200)
            ->assertJsonPath('tag.id', $tag->id)
            ->assertJsonPath('tag.name', $tag->name)
            ->assertJsonCount(3, 'products');

        $products = $response->json('products');
        $productIds = array_column($products, 'id');

        $this->assertContains($product1->id, $productIds);
        $this->assertContains($product2->id, $productIds);
        $this->assertContains($otherProduct->id, $productIds);
        $this->assertNotContains($untaggedProduct->id, $productIds);

        $product1Payload = collect($products)->firstWhere('id', $product1->id);
        $this->assertNotNull($product1Payload);
        $this->assertCount(1, $product1Payload['images'] ?? []);
        $this->assertCount(2, $product1Payload['tags'] ?? []);
    }

    public function test_list_products_by_unknown_tag_returns_404(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/products/by-tag/UnknownTag');

        $response->assertStatus(404);
    }

    public function test_user_can_list_products_by_category_name(): void
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

        $electronics = Category::create([
            'name' => 'Electronics',
            'parent_id' => null,
        ]);

        $books = Category::create([
            'name' => 'Books',
            'parent_id' => null,
        ]);

        $conditionLevel = ConditionLevel::create([
            'name' => 'Good',
            'description' => null,
            'sort_order' => 1,
        ]);

        $tag = Tag::create(['name' => 'MyTag']);

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product1 = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $electronics->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Product 1',
            'description' => null,
            'price' => 10.00,
            'status' => 'available',
        ]);

        $product2 = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $electronics->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Product 2',
            'description' => null,
            'price' => 20.00,
            'status' => 'sold',
        ]);

        $otherCategoryProduct = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $books->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Other Category Product',
            'description' => null,
            'price' => 30.00,
            'status' => 'available',
        ]);

        ProductImage::create([
            'product_id' => $product1->id,
            'image_url' => '/p1.png',
            'image_thumbnail_url' => '/p1_thumb.png',
            'is_primary' => true,
        ]);

        ProductImage::create([
            'product_id' => $otherCategoryProduct->id,
            'image_url' => '/p3.png',
            'image_thumbnail_url' => null,
            'is_primary' => true,
        ]);

        ProductTag::create([
            'product_id' => $product1->id,
            'tag_id' => $tag->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/products/by-category/'.$electronics->name);

        $response
            ->assertStatus(200)
            ->assertJsonPath('category.id', $electronics->id)
            ->assertJsonPath('category.name', $electronics->name)
            ->assertJsonCount(2, 'products');

        $products = $response->json('products');
        $productIds = array_column($products, 'id');

        $this->assertContains($product1->id, $productIds);
        $this->assertContains($product2->id, $productIds);
        $this->assertNotContains($otherCategoryProduct->id, $productIds);

        $product1Payload = collect($products)->firstWhere('id', $product1->id);
        $this->assertNotNull($product1Payload);
        $this->assertCount(1, $product1Payload['images'] ?? []);
        $this->assertCount(1, $product1Payload['tags'] ?? []);
        $this->assertSame($tag->id, $product1Payload['tags'][0]['id']);
        $this->assertSame($tag->name, $product1Payload['tags'][0]['name']);
    }

    public function test_list_products_by_unknown_category_returns_404(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/products/by-category/UnknownCategory');

        $response->assertStatus(404);
    }

    public function test_user_can_add_product_to_favorites(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Keyboard',
            'description' => null,
            'price' => 20.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/favorites', [
                'product_id' => $product->id,
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('favorite.user_id', $user->id)
            ->assertJsonPath('favorite.product_id', $product->id);

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    public function test_add_product_to_favorites_is_idempotent(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Mouse',
            'description' => null,
            'price' => 10.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($user);

        $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/favorites', [
                'product_id' => $product->id,
            ])
            ->assertStatus(201);

        $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/favorites', [
                'product_id' => $product->id,
            ])
            ->assertStatus(200);

        $this->assertSame(1, Favorite::query()->count());
    }

    public function test_user_can_list_favorite_products(): void
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

        $tag = Tag::create(['name' => 'FavoriteTag']);

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        $product1 = Product::create([
            'seller_id' => $otherUser->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Favorite Product 1',
            'description' => null,
            'price' => 10.00,
            'status' => 'available',
        ]);

        $product2 = Product::create([
            'seller_id' => $otherUser->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Favorite Product 2',
            'description' => null,
            'price' => 20.00,
            'status' => 'sold',
        ]);

        $deletedProduct = Product::create([
            'seller_id' => $otherUser->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Deleted Favorite Product',
            'description' => null,
            'price' => 30.00,
            'status' => 'available',
            'deleted_at' => now(),
        ]);

        $otherUsersProduct = Product::create([
            'seller_id' => $user->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Other User Favorite Product',
            'description' => null,
            'price' => 40.00,
            'status' => 'available',
        ]);

        Favorite::create([
            'user_id' => $user->id,
            'product_id' => $product1->id,
        ]);

        Favorite::create([
            'user_id' => $user->id,
            'product_id' => $product2->id,
        ]);

        Favorite::create([
            'user_id' => $user->id,
            'product_id' => $deletedProduct->id,
        ]);

        Favorite::create([
            'user_id' => $otherUser->id,
            'product_id' => $otherUsersProduct->id,
        ]);

        ProductImage::create([
            'product_id' => $product1->id,
            'image_url' => '/fav1.png',
            'image_thumbnail_url' => '/fav1_thumb.png',
            'is_primary' => true,
        ]);

        ProductTag::create([
            'product_id' => $product1->id,
            'tag_id' => $tag->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/get_favorites');

        $response
            ->assertStatus(200)
            ->assertJsonCount(2, 'products');

        $products = $response->json('products');
        $productIds = array_column($products, 'id');

        $this->assertContains($product1->id, $productIds);
        $this->assertContains($product2->id, $productIds);
        $this->assertNotContains($deletedProduct->id, $productIds);
        $this->assertNotContains($otherUsersProduct->id, $productIds);

        $product1Payload = collect($products)->firstWhere('id', $product1->id);
        $this->assertNotNull($product1Payload);
        $this->assertCount(1, $product1Payload['images'] ?? []);
        $this->assertCount(1, $product1Payload['tags'] ?? []);
        $this->assertSame($tag->id, $product1Payload['tags'][0]['id']);
        $this->assertSame($tag->name, $product1Payload['tags'][0]['name']);
    }

    public function test_user_can_get_product_details_by_id(): void
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

        $tag = Tag::create(['name' => 'DetailTag']);

        $seller = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
            'username' => 'selleruser',
        ]);

        $viewer = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
            'username' => 'vieweruser',
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Keyboard',
            'description' => 'Nice keyboard',
            'price' => 20.00,
            'status' => 'available',
        ]);

        ProductImage::create([
            'product_id' => $product->id,
            'image_url' => '/p1.png',
            'image_thumbnail_url' => '/p1_thumb.png',
            'is_primary' => true,
        ]);

        ProductTag::create([
            'product_id' => $product->id,
            'tag_id' => $tag->id,
        ]);

        Sanctum::actingAs($viewer);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/get_product/'.$product->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.title', $product->title)
            ->assertJsonPath('product.category.id', $category->id)
            ->assertJsonPath('product.condition_level.id', $conditionLevel->id)
            ->assertJsonPath('product.seller.id', $seller->id)
            ->assertJsonPath('product.seller.username', $seller->username)
            ->assertJsonCount(1, 'product.images')
            ->assertJsonCount(1, 'product.tags');
    }

    public function test_get_product_with_unknown_product_returns_404(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/get_product/999999');

        $response->assertStatus(404);
    }

    public function test_add_product_to_favorites_with_unknown_product_returns_404(): void
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

        $user = User::factory()->create([
            'dormitory_id' => $dormitory->id,
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/favorites', [
                'product_id' => 999999,
            ]);

        $response->assertStatus(404);
    }

    public function test_non_user_role_cannot_list_favorites(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/get_favorites');

        $response->assertStatus(403);
    }

    public function test_non_user_role_cannot_add_product_to_favorites(): void
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

        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
            'dormitory_id' => $dormitory->id,
        ]);

        $seller = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
            'dormitory_id' => $dormitory->id,
        ]);

        $product = Product::create([
            'seller_id' => $seller->id,
            'dormitory_id' => $dormitory->id,
            'category_id' => $category->id,
            'condition_level_id' => $conditionLevel->id,
            'title' => 'Keyboard',
            'description' => null,
            'price' => 20.00,
            'status' => 'available',
        ]);

        Sanctum::actingAs($admin);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/favorites', [
                'product_id' => $product->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_non_user_role_cannot_list_products(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/products');

        $response->assertStatus(403);
    }

    public function test_non_user_role_cannot_get_product_details(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/user/get_product/1');

        $response->assertStatus(403);
    }
}
