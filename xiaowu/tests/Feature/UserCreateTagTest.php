<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCreateTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_tag(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/user/tags', [
                'name' => 'Laptop',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonPath('message', 'Tag created successfully')
            ->assertJsonPath('tag.name', 'Laptop');

        $this->assertDatabaseHas('tags', [
            'name' => 'Laptop',
        ]);
    }
}
