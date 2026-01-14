<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminCreateConditionLevelTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_condition_level(): void
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
            ->postJson('/api/admin/condition-levels', [
                'name' => 'Good',
                'description' => 'Minor wear, fully functional.',
                'sort_order' => 1,
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Condition level created successfully')
            ->assertJsonPath('condition_level.name', 'Good')
            ->assertJsonPath('condition_level.sort_order', 1);

        $this->assertDatabaseHas('condition_levels', [
            'name' => 'Good',
            'sort_order' => 1,
        ]);
    }
}
