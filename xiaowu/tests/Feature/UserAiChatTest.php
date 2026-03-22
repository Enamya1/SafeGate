<?php

namespace Tests\Feature;

use App\Models\AiChatMessage;
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

    public function test_chat_without_title_gets_default_name_from_first_user_message(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $session = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => null,
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        Http::fake([
            'http://127.0.0.1:8001/api/ai/respond' => Http::response([
                'response' => 'Sure, I can help.',
                'function_calls' => [],
                'products' => [],
                'usage' => [
                    'total_tokens' => 10,
                    'prompt_tokens' => 4,
                    'completion_tokens' => 6,
                ],
            ], 200),
        ]);

        Sanctum::actingAs($user);

        $firstMessage = 'Help me find a cheap laptop for classes and coding';

        $this->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/ai/sessions/'.$session->session_uuid.'/messages', [
                'message' => $firstMessage,
                'message_type' => 'text',
            ])
            ->assertStatus(200);

        $updatedSession = AiChatSession::query()->findOrFail($session->id);
        $this->assertSame($firstMessage, $updatedSession->title);

        $historyResponse = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/ai/history?include_messages=0');

        $historyResponse->assertStatus(200);
        $historySession = collect($historyResponse->json('history'))
            ->firstWhere('session_id', $session->session_uuid);

        $this->assertNotNull($historySession);
        $this->assertSame($firstMessage, $historySession['title']);
    }

    public function test_user_can_get_all_chat_history(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $otherUser = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $sessionA = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => 'Session A',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        $sessionB = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => 'Session B',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        $otherSession = AiChatSession::create([
            'user_id' => $otherUser->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => 'Other Session',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        AiChatMessage::create([
            'session_id' => $sessionA->id,
            'user_id' => $user->id,
            'role' => 'user',
            'message_type' => 'user',
            'content_type' => 'text',
            'content' => 'hello from session A',
            'tokens_used' => 0,
        ]);

        AiChatMessage::create([
            'session_id' => $sessionB->id,
            'user_id' => null,
            'role' => 'assistant',
            'message_type' => 'assistant',
            'content_type' => 'text',
            'content' => 'assistant response in session B',
            'tokens_used' => 8,
        ]);

        AiChatMessage::create([
            'session_id' => $otherSession->id,
            'user_id' => $otherUser->id,
            'role' => 'user',
            'message_type' => 'user',
            'content_type' => 'text',
            'content' => 'other user message',
            'tokens_used' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/ai/history');

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Chat history retrieved successfully')
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'history');

        $sessionIds = collect($response->json('history'))->pluck('session_id')->values()->all();
        $this->assertContains($sessionA->session_uuid, $sessionIds);
        $this->assertContains($sessionB->session_uuid, $sessionIds);
        $this->assertNotContains($otherSession->session_uuid, $sessionIds);
    }

    public function test_user_can_delete_chat_history_by_session_id(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $session = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => 'Delete Me',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        AiChatMessage::create([
            'session_id' => $session->id,
            'user_id' => $user->id,
            'role' => 'user',
            'message_type' => 'user',
            'content_type' => 'text',
            'content' => 'temporary message',
            'tokens_used' => 0,
        ]);

        Sanctum::actingAs($user);

        $response = $this
            ->withHeader('Accept', 'application/json')
            ->delete('/api/ai/history/'.$session->session_uuid);

        $response
            ->assertStatus(200)
            ->assertJsonPath('message', 'Chat history deleted successfully')
            ->assertJsonPath('session_id', $session->session_uuid);

        $this->assertDatabaseMissing('ai_chat_sessions', [
            'id' => $session->id,
        ]);

        $this->assertDatabaseMissing('ai_chat_messages', [
            'session_id' => $session->id,
        ]);
    }

    public function test_user_can_rename_chat_history_by_session_id(): void
    {
        $user = User::factory()->create([
            'role' => 'user',
            'status' => 'active',
        ]);

        $session = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => 'Old Chat Name',
            'provider' => 'qwen',
            'model' => 'qwen-plus',
        ]);

        Sanctum::actingAs($user);

        $renameResponse = $this
            ->withHeader('Accept', 'application/json')
            ->patch('/api/ai/history/'.$session->session_uuid.'/rename', [
                'title' => 'New Chat Name',
            ]);

        $renameResponse
            ->assertStatus(200)
            ->assertJsonPath('message', 'Chat title updated successfully')
            ->assertJsonPath('session_id', $session->session_uuid)
            ->assertJsonPath('title', 'New Chat Name');

        $this->assertDatabaseHas('ai_chat_sessions', [
            'id' => $session->id,
            'title' => 'New Chat Name',
        ]);

        $historyResponse = $this
            ->withHeader('Accept', 'application/json')
            ->get('/api/ai/history?include_messages=0');

        $historyResponse->assertStatus(200);

        $historySession = collect($historyResponse->json('history'))
            ->firstWhere('session_id', $session->session_uuid);

        $this->assertNotNull($historySession);
        $this->assertSame('New Chat Name', $historySession['title']);
    }
}
