<?php

namespace App\Http\Controllers;

use App\Models\AiActivityEvent;
use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiChatController extends Controller
{
    public function createSession(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'title' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $session = AiChatSession::create([
            'user_id' => $user->id,
            'session_uuid' => (string) Str::uuid(),
            'title' => $validated['title'] ?? null,
            'provider' => env('AI_PROVIDER', 'qwen'),
            'model' => env('AI_MODEL', 'qwen-plus'),
            'metadata' => null,
        ]);

        return response()->json([
            'session_id' => $session->session_uuid,
            'expires_at' => now()->addMinutes(15)->toISOString(),
        ], 201);
    }

    public function sendMessage(Request $request, string $sessionId)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'message' => 'required|string|max:5000',
                'message_type' => 'nullable|in:text,voice',
                'audio_duration_seconds' => 'nullable|numeric|min:0|max:9999.99',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $session = AiChatSession::query()
            ->where('session_uuid', $sessionId)
            ->where('user_id', $user->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Session not found.',
            ], 404);
        }

        $messageType = $validated['message_type'] ?? 'text';
        $contentType = $messageType === 'voice' ? 'voice' : 'text';

        AiChatMessage::create([
            'session_id' => $session->id,
            'user_id' => $user->id,
            'role' => 'user',
            'message_type' => 'user',
            'content_type' => $contentType,
            'content' => $validated['message'],
            'audio_duration_seconds' => $validated['audio_duration_seconds'] ?? null,
            'tokens' => null,
            'tokens_used' => 0,
            'response_ms' => null,
            'metadata' => null,
        ]);

        $serviceUrl = rtrim(env('PYTHON_SERVICE_BASE_URL', 'http://127.0.0.1:8001'), '/');
        $pythonTimeoutSeconds = (int) env('PYTHON_SERVICE_TIMEOUT_SECONDS', 45);
        $pythonConnectTimeoutSeconds = (int) env('PYTHON_SERVICE_CONNECT_TIMEOUT_SECONDS', 5);
        $pythonInternalToken = (string) env('PYTHON_INTERNAL_TOKEN', '');
        $authHeader = $request->header('Authorization');
        $startedAt = microtime(true);

        $assistantText = '';
        $functionCalls = [];
        $products = [];
        $upstreamStatusCode = null;
        $upstreamDetail = null;
        $usage = [
            'total_tokens' => 0,
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
        ];
        $success = true;
        $errorMessage = null;

        try {
            $pythonResponse = Http::connectTimeout($pythonConnectTimeoutSeconds)
                ->timeout($pythonTimeoutSeconds)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => (string) $authHeader,
                    'X-Internal-Token' => $pythonInternalToken,
                ])
                ->post($serviceUrl.'/api/ai/respond', [
                    'session_id' => $session->session_uuid,
                    'message' => $validated['message'],
                    'message_type' => $messageType,
                    'user_context' => [
                        'id' => $user->id,
                        'role' => $user->role,
                        'dormitory_id' => $user->dormitory_id,
                    ],
                ]);

            if (! $pythonResponse->successful()) {
                $body = $pythonResponse->json();
                $upstreamStatusCode = $pythonResponse->status();
                $upstreamDetail = is_array($body) ? $body : ['raw' => (string) $pythonResponse->body()];
                $errorMessage = is_array($body) ? json_encode($body) : (string) $pythonResponse->body();
                $success = false;
            } else {
                $payload = $pythonResponse->json();
                $assistantText = (string) ($payload['response'] ?? '');
                $functionCalls = is_array($payload['function_calls'] ?? null) ? $payload['function_calls'] : [];
                $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
                $usagePayload = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];
                $usage['total_tokens'] = (int) ($usagePayload['total_tokens'] ?? 0);
                $usage['prompt_tokens'] = (int) ($usagePayload['prompt_tokens'] ?? 0);
                $usage['completion_tokens'] = (int) ($usagePayload['completion_tokens'] ?? 0);
            }
        } catch (\Throwable $e) {
            $success = false;
            $errorMessage = $e->getMessage();
            $upstreamStatusCode = 0;
            $upstreamDetail = [
                'exception' => class_basename($e),
                'message' => $e->getMessage(),
            ];
        }

        $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

        if (! $success) {
            AiActivityEvent::create([
                'user_id' => $user->id,
                'activity_type' => 'ai_message_failed',
                'context' => 'chat',
                'payload' => [
                    'session_id' => $session->session_uuid,
                    'message_type' => $messageType,
                ],
                'event_type' => 'chat_message',
                'model_used' => env('AI_MODEL', 'qwen-plus'),
                'total_tokens' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost' => 0,
                'duration_ms' => $durationMs,
                'success' => false,
                'error_message' => $errorMessage,
            ]);

            return response()->json([
                'message' => 'AI service unavailable.',
                'detail' => $upstreamDetail,
                'upstream_status' => $upstreamStatusCode,
            ], 502);
        }

        $primaryCall = is_array($functionCalls[0] ?? null) ? $functionCalls[0] : null;

        AiChatMessage::create([
            'session_id' => $session->id,
            'user_id' => null,
            'role' => 'assistant',
            'message_type' => 'assistant',
            'content_type' => 'text',
            'content' => $assistantText,
            'function_name' => is_array($primaryCall) ? ($primaryCall['name'] ?? null) : null,
            'function_arguments' => is_array($primaryCall) ? ($primaryCall['arguments'] ?? null) : null,
            'function_response' => $functionCalls,
            'tokens' => $usage['total_tokens'],
            'tokens_used' => $usage['total_tokens'],
            'response_ms' => $durationMs,
            'metadata' => null,
        ]);

        AiActivityEvent::create([
            'user_id' => $user->id,
            'activity_type' => 'ai_message',
            'context' => 'chat',
            'payload' => [
                'session_id' => $session->session_uuid,
                'message_type' => $messageType,
            ],
            'event_type' => 'chat_message',
            'model_used' => env('AI_MODEL', 'qwen-plus'),
            'total_tokens' => $usage['total_tokens'],
            'prompt_tokens' => $usage['prompt_tokens'],
            'completion_tokens' => $usage['completion_tokens'],
            'cost' => 0,
            'duration_ms' => $durationMs,
            'success' => true,
            'error_message' => null,
        ]);

        return response()->json([
            'response' => $assistantText,
            'function_calls' => $functionCalls,
            'products' => $products,
        ], 200);
    }

    public function listMessages(Request $request, string $sessionId)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $session = AiChatSession::query()
            ->where('session_uuid', $sessionId)
            ->where('user_id', $user->id)
            ->first();

        if (! $session) {
            return response()->json([
                'message' => 'Session not found.',
            ], 404);
        }

        $messages = AiChatMessage::query()
            ->where('session_id', $session->id)
            ->orderBy('id')
            ->get([
                'id',
                'message_type',
                'content_type',
                'content',
                'function_name',
                'function_arguments',
                'function_response',
                'tokens_used',
                'audio_duration_seconds',
                'created_at',
            ]);

        return response()->json([
            'message' => 'Messages retrieved successfully',
            'session_id' => $session->session_uuid,
            'messages' => $messages,
        ], 200);
    }
}
