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

    public function test_admin_update_user_endpoint_updates_user_data(): void
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
            'full_name' => 'Original User',
            'username' => 'originaluser',
            'email' => 'original@example.com',
            'phone_number' => '1234567890',
            'password' => Hash::make('password123'),
            'dormitory_id' => null,
            'role' => 'user',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $updatedData = [
            'full_name' => 'Updated User Name',
            'email' => 'updated@example.com',
            'phone_number' => '0987654321',
            'role' => 'admin',
        ];

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/admin/users/'.$user->id, $updatedData);

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'User updated successfully')
            ->assertJsonPath('user.full_name', 'Updated User Name')
            ->assertJsonPath('user.email', 'updated@example.com')
            ->assertJsonPath('user.phone_number', '0987654321')
            ->assertJsonPath('user.role', 'admin');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'full_name' => 'Updated User Name',
            'email' => 'updated@example.com',
            'phone_number' => '0987654321',
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_activate_user(): void
    {
        $admin = User::create([
            'full_name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $user = User::create([
            'full_name' => 'Inactive User',
            'username' => 'inactiveuser',
            'email' => 'inactive@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'status' => 'inactive',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/admin/users/'.$user->id.'/activate');

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'User activated successfully')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'active',
        ]);
    }

    public function test_admin_can_deactivate_user(): void
    {
        $admin = User::create([
            'full_name' => 'Admin User',
            'username' => 'adminuser',
            'email' => 'admin@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $user = User::create([
            'full_name' => 'Active User',
            'username' => 'activeuser',
            'email' => 'active@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'status' => 'active',
        ]);

        $token = $admin->createToken('admin_auth_token')->plainTextToken;

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson('/api/admin/users/'.$user->id.'/deactivate');

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'User deactivated successfully')
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => 'inactive',
        ]);
    }

    public function test_deactivated_user_cannot_login(): void
    {
        $user = User::create([
            'full_name' => 'Deactivated User',
            'username' => 'deactivateduser',
            'email' => 'deactivated@example.com',
            'password' => Hash::make('password123'),
            'role' => 'user',
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/user/login', [
            'email' => 'deactivated@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your account is deactivated. Please contact support.');
    }

    public function test_deactivated_admin_cannot_login(): void
    {
        $admin = User::create([
            'full_name' => 'Deactivated Admin',
            'username' => 'deactivatedadmin',
            'email' => 'deactivatedadmin@example.com',
            'password' => Hash::make('password123'),
            'role' => 'admin',
            'status' => 'inactive',
        ]);

        $response = $this->postJson('/api/admin/login', [
            'email' => 'deactivatedadmin@example.com',
            'password' => 'password123',
        ]);

        $response
            ->assertStatus(403)
            ->assertJsonPath('message', 'Your admin account is deactivated. Please contact support.');
    }
}
