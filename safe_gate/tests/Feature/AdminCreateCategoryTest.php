<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\University;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCreateCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_category(): void
    {
        $admin = User::create([
            'full_name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'admin',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/categories', [
                'name' => 'Electronics',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Category created successfully')
            ->assertJsonPath('category.name', 'Electronics');

        $this->assertDatabaseHas('categories', [
            'name' => 'Electronics',
            'parent_id' => null,
        ]);
    }

    public function test_admin_can_create_child_category(): void
    {
        $admin = User::create([
            'full_name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'admin',
        ]);

        $parent = Category::create([
            'name' => 'Electronics',
            'parent_id' => null,
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/admin/categories', [
                'name' => 'Phones',
                'parent_id' => $parent->id,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('categories', [
            'name' => 'Phones',
            'parent_id' => $parent->id,
        ]);
    }

    public function test_admin_can_block_product_with_reason(): void
    {
        $admin = User::create([
            'full_name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'admin',
        ]);

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

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/admin/products/'.$product->id.'/block', [
                'reason' => 'Violates marketplace policy',
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Product blocked successfully')
            ->assertJsonPath('product.id', $product->id)
            ->assertJsonPath('product.status', 'block')
            ->assertJsonPath('product.modified_by', $admin->id)
            ->assertJsonPath('product.modification_reason', 'Violates marketplace policy');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'status' => 'block',
            'modified_by' => $admin->id,
            'modification_reason' => 'Violates marketplace policy',
        ]);
    }

    public function test_admin_product_details_include_metrics(): void
    {
        $admin = User::create([
            'full_name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'admin',
        ]);

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
            'role' => 'user',
            'status' => 'active',
            'dormitory_id' => $dormitory->id,
        ]);

        $viewer = User::factory()->create([
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

        DB::table('behavioral_events')->insert([
            [
                'user_id' => $viewer->id,
                'event_type' => 'view',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => now(),
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $viewer->id,
                'event_type' => 'click',
                'product_id' => $product->id,
                'category_id' => $category->id,
                'seller_id' => $seller->id,
                'metadata' => null,
                'occurred_at' => now(),
                'session_id' => null,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Favorite::create([
            'user_id' => $viewer->id,
            'product_id' => $product->id,
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/admin/products/'.$product->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('product.views', 1)
            ->assertJsonPath('product.clicks', 1)
            ->assertJsonPath('product.favorites', 1);
    }
}
