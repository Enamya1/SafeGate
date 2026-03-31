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
    private function defaultSessionTitleFromMessage(string $message): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($message));
        $normalized = is_string($normalized) ? $normalized : '';

        if ($normalized === '') {
            return 'New Chat';
        }

        $title = Str::limit($normalized, 80, '');
        $title = rtrim($title, " \t\n\r\0\x0B.,!?;:-");

        if ($title === '') {
            return 'New Chat';
        }

        return Str::limit($title, 80);
    }

    public function listHistory(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:50',
                'include_messages' => 'nullable|boolean',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);
        $includeMessages = (bool) ($validated['include_messages'] ?? true);

        $paginator = AiChatSession::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $sessions = $paginator->getCollection()->values();
        $sessionIds = $sessions->pluck('id')->all();

        $messageCountsBySession = collect();
        $latestMessagesBySession = collect();
        $messagesBySession = collect();

        if (count($sessionIds) > 0) {
            $messageCountsBySession = AiChatMessage::query()
                ->whereIn('session_id', $sessionIds)
                ->selectRaw('session_id, COUNT(*) as total_messages')
                ->groupBy('session_id')
                ->pluck('total_messages', 'session_id');

            $latestMessagesBySession = AiChatMessage::query()
                ->whereIn('session_id', $sessionIds)
                ->orderByDesc('id')
                ->get([
                    'session_id',
                    'message_type',
                    'content_type',
                    'content',
                    'created_at',
                ])
                ->groupBy('session_id')
                ->map(fn ($group) => $group->first());

            if ($includeMessages) {
                $messagesBySession = AiChatMessage::query()
                    ->whereIn('session_id', $sessionIds)
                    ->orderBy('id')
                    ->get([
                        'id',
                        'session_id',
                        'message_type',
                        'content_type',
                        'content',
                        'function_name',
                        'function_arguments',
                        'function_response',
                        'tokens_used',
                        'audio_duration_seconds',
                        'created_at',
                    ])
                    ->groupBy('session_id');
            }
        }

        $history = $sessions->map(function (AiChatSession $session) use ($messageCountsBySession, $latestMessagesBySession, $messagesBySession, $includeMessages) {
            $latestMessage = $latestMessagesBySession->get($session->id);

            return [
                'session_id' => $session->session_uuid,
                'title' => $session->title,
                'provider' => $session->provider,
                'model' => $session->model,
                'created_at' => $session->created_at,
                'updated_at' => $session->updated_at,
                'total_messages' => (int) ($messageCountsBySession->get($session->id) ?? 0),
                'latest_message' => $latestMessage ? [
                    'message_type' => $latestMessage->message_type,
                    'content_type' => $latestMessage->content_type,
                    'content' => $latestMessage->content,
                    'created_at' => $latestMessage->created_at,
                ] : null,
                'messages' => $includeMessages ? ($messagesBySession->get($session->id, collect())->values()) : [],
            ];
        })->values();

        return response()->json([
            'message' => 'Chat history retrieved successfully',
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'history' => $history,
        ], 200);
    }

    public function deleteHistory(Request $request, string $sessionId)
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

        $deletedSessionId = $session->session_uuid;
        $session->delete();

        return response()->json([
            'message' => 'Chat history deleted successfully',
            'session_id' => $deletedSessionId,
        ], 200);
    }

    public function renameHistory(Request $request, string $sessionId)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'title' => 'required|string|max:255',
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

        $session->title = $validated['title'];
        $session->save();

        return response()->json([
            'message' => 'Chat title updated successfully',
            'session_id' => $session->session_uuid,
            'title' => $session->title,
        ], 200);
    }

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
        return $this->handleSessionMessage($request, $sessionId, false);
    }

    public function sendVoiceCall(Request $request, string $sessionId)
    {
        return $this->handleSessionMessage($request, $sessionId, true);
    }

    private function handleSessionMessage(Request $request, string $sessionId, bool $voiceCallMode)
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

        $messageType = $voiceCallMode ? 'voice' : ($validated['message_type'] ?? 'text');
        $interactionMode = $voiceCallMode ? 'voice_call' : 'chat';
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

        if (trim((string) ($session->title ?? '')) === '') {
            $session->title = $this->defaultSessionTitleFromMessage($validated['message']);
            $session->save();
        }

        $serviceUrl = rtrim(env('PYTHON_SERVICE_BASE_URL', 'http://127.0.0.1:8001'), '/');
        $pythonTimeoutSeconds = (int) env('PYTHON_SERVICE_TIMEOUT_SECONDS', 45);
        $pythonConnectTimeoutSeconds = (int) env('PYTHON_SERVICE_CONNECT_TIMEOUT_SECONDS', 5);
        $pythonInternalToken = (string) env('PYTHON_INTERNAL_TOKEN', '');
        $authHeader = $request->header('Authorization');
        $startedAt = microtime(true);

        $assistantText = '';
        $functionCalls = [];
        $products = [];
        $voiceResponse = [
            'text' => '',
            'should_speak' => $messageType === 'voice',
        ];
        $shouldDisplayProducts = false;
        $displayPayload = null;
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
                    'interaction_mode' => $interactionMode,
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
                $voiceResponsePayload = is_array($payload['voice_response'] ?? null) ? $payload['voice_response'] : [];
                $voiceResponse['text'] = (string) ($voiceResponsePayload['text'] ?? $assistantText);
                $voiceResponse['should_speak'] = (bool) ($voiceResponsePayload['should_speak'] ?? ($messageType === 'voice'));
                $shouldDisplayProducts = (bool) ($payload['should_display_products'] ?? (count($products) > 0));
                $displayPayload = is_array($payload['display_payload'] ?? null) ? $payload['display_payload'] : null;
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
                    'interaction_mode' => $interactionMode,
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
            'content_type' => $voiceResponse['should_speak'] ? 'voice' : 'text',
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
                'interaction_mode' => $interactionMode,
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

        $responsePayload = [
            'response' => $assistantText,
            'function_calls' => $functionCalls,
            'products' => $products,
        ];

        if ($voiceCallMode) {
            $responsePayload['voice_response'] = $voiceResponse;
            $responsePayload['should_display_products'] = $shouldDisplayProducts;
            $responsePayload['display_payload'] = $displayPayload;
        }

        return response()->json($responsePayload, 200);
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
