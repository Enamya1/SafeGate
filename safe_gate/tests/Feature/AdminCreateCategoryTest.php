<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
