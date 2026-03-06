<?php

namespace Tests\Feature;

use App\Models\AiChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserAiChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_ai_chat_session(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->postJson('/api/ai/sessions', [
                'title' => 'Dorm shopping',
            ]);

        $response
            ->assertStatus(201)
            ->assertJsonStructure(['session_id', 'expires_at']);

        $sessionUuid = $response->json('session_id');

        $this->assertDatabaseHas('ai_chat_sessions', [
            'user_id' => $user->id,
            'session_uuid' => $sessionUuid,
            'title' => 'Dorm shopping',
        ]);
    }

    public function test_user_can_send_ai_chat_message(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $session = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => 'Session',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        Http::fake([
            'http://127.0.0.1:8001/api/ai/respond' => Http::response([
                'response' => 'I found products for you.',
                'function_calls' => [
                    [
                        'name' => 'search_by_price',
                        'arguments' => ['max_price' => 500],
                    ],
                ],
                'products' => [
                    [
                        'id' => 12,
                        'title' => 'Dell XPS 13 Laptop',
                    ],
                    [
                        'id' => 15,
                        'title' => 'iPhone 13',
                    ],
                ],
                'usage' => [
                    'total_tokens' => 42,
                    'prompt_tokens' => 20,
                    'completion_tokens' => 22,
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/ai/sessions/'.$session->session_uuid.'/messages', [
                'message' => 'Find a laptop under 500',
                'message_type' => 'text',
            ]);

        $response
            ->assertStatus(200)
            ->assertJsonPath('response', 'I found products for you.')
            ->assertJsonPath('function_calls.0.name', 'search_by_price')
            ->assertJsonPath('products.0.id', 12)
            ->assertJsonPath('products.1.id', 15);

        $this->assertDatabaseHas('ai_chat_messages', [
            'session_id' => $session->id,
            'message_type' => 'user',
            'content' => 'Find a laptop under 500',
        ]);

        $this->assertDatabaseHas('ai_chat_messages', [
            'session_id' => $session->id,
            'message_type' => 'assistant',
            'content' => 'I found products for you.',
            'tokens_used' => 42,
        ]);

        $this->assertDatabaseHas('ai_activity_events', [
            'user_id' => $user->id,
            'event_type' => 'chat_message',
            'success' => 1,
        ]);
    }
}
