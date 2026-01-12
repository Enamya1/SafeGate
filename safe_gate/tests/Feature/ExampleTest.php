<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/api/health-check');

        $response->assertStatus(200);
    }

    public function test_admin_users_endpoint_returns_unauthenticated_without_token(): void
    {
        $response = $this->get('/api/admin/users');

        $response->assertStatus(401);
    }

    public function test_admin_users_endpoint_returns_users_for_admin_token(): void
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

        User::create([
            'full_name' => 'Regular User',
            'username' => 'regularuser',
            'email' => 'user@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'user',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/users');

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Users retrieved successfully')
            ->assertJsonCount(2, 'users.data')
            ->assertJsonStructure([
                'users' => [
                    'data' => [
                        '*' => [
                            'id',
                            'full_name',
                            'email',
                            'role',
                            'phone_number',
                            'dormitory_id',
                            'status',
                            'profile_picture',
                        ],
                    ],
                ],
            ]);
    }

    public function test_admin_users_endpoint_accepts_token_from_query_string(): void
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

        User::create([
            'full_name' => 'Regular User',
            'username' => 'regularuser',
            'email' => 'user@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'user',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this->getJson('/api/admin/users?access_token='.urlencode($token));

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Users retrieved successfully')
            ->assertJsonCount(2, 'users.data')
            ->assertJsonStructure([
                'users' => [
                    'data' => [
                        '*' => [
                            'id',
                            'full_name',
                            'email',
                            'role',
                            'phone_number',
                            'dormitory_id',
                            'status',
                            'profile_picture',
                        ],
                    ],
                ],
            ]);
    }

    public function test_admin_show_user_endpoint_returns_user_data_for_admin_token(): void
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

        $user = User::create([
            'full_name' => 'Regular User',
            'username' => 'regularuser',
            'email' => 'user@example.com',
            'phone_number' => null,
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'user',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/admin/users/'.$user->id);

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'User retrieved successfully')
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'full_name',
                    'email',
                    'role',
                    'phone_number',
                    'dormitory_id',
                    'status',
                    'profile_picture',
                ],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.full_name', $user->full_name);
    }
}
