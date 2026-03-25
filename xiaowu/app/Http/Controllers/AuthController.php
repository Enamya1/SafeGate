<?php

namespace App\Http\Controllers;

use App\Models\Dormitory;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    private function publicDiskUrl(string $path): string
    {
        $baseUrl = (string) config('filesystems.disks.public.url', '');
        $path = ltrim($path, '/');

        if ($baseUrl === '') {
            return '/storage/'.$path;
        }

        return rtrim($baseUrl, '/').'/'.$path;
    }

    private function storeProfilePicture($file, int $userId): string
    {
        $path = $file->storePublicly('users/'.$userId.'/profile', 'public');

        return $this->publicDiskUrl($path);
    }

    private function hasValue($value): bool
    {
        return $value !== null && $value !== '';
    }

    private function isAccountCompleted(User $user): bool
    {
        return $this->hasValue($user->phone_number)
            && $this->hasValue($user->dormitory_id)
            && $this->hasValue($user->student_id)
            && $this->hasValue($user->date_of_birth)
            && $this->hasValue($user->gender)
            && $this->hasValue($user->language);
    }

    public function signup(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'full_name' => 'required|string|max:255',
                'username' => 'required|string|max:255|unique:users',
                'email' => 'required|string|email|max:255|unique:users',
                'phone_number' => 'nullable|string|max:20',
                'password' => 'required|string|min:8',
                'dormitory_id' => 'nullable|exists:dormitories,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = null;

        DB::transaction(function () use ($validatedData, &$user) {
            $user = User::create([
                'full_name' => $validatedData['full_name'],
                'username' => $validatedData['username'],
                'email' => $validatedData['email'],
                'phone_number' => data_get($validatedData, 'phone_number', null),
                'password' => Hash::make($validatedData['password']),
                'dormitory_id' => data_get($validatedData, 'dormitory_id', null),
            ]);

            $accountCompleted = $this->isAccountCompleted($user);
            if ($user->account_completed !== $accountCompleted) {
                $user->account_completed = $accountCompleted;
                $user->save();
            }

            $walletTypeId = DB::table('wallet_types')->where('code', 'primary')->value('id');
            $statusId = DB::table('wallet_statuses')->where('code', 'active')->value('id');

            if ($walletTypeId && $statusId) {
                $walletId = DB::table('wallets')->insertGetId([
                    'user_id' => $user->id,
                    'wallet_type_id' => $walletTypeId,
                    'status_id' => $statusId,
                    'currency' => 'CNY',
                    'balance' => 0,
                    'available_balance' => 0,
                    'locked_balance' => 0,
                    'frozen_at' => null,
                    'freeze_reason' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('wallet_status_histories')->insert([
                    'wallet_id' => $walletId,
                    'from_status_id' => null,
                    'to_status_id' => $statusId,
                    'changed_by' => $user->id,
                    'reason' => null,
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json([
            'message' => 'User registered successfully',
            'user' => $user,
        ], 201);
    }

    public function login(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|string|email',
                'password' => 'required|string',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        if ($user->status === 'inactive') {
            return response()->json([
                'message' => 'Your account is deactivated. Please contact support.',
            ], 403);
        }

        $user->last_login_at = now();
        $user->account_completed = $this->isAccountCompleted($user);
        $user->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'account_completed' => (bool) $user->account_completed,
        ]);
    }

    public function updateProfile(Request $request)
    {
        try {
            $user = Auth::user();

            $rules = [
                'full_name' => 'sometimes|string|max:255',
                'username' => 'sometimes|string|max:255|unique:users,username,'.$user->id,
                'email' => 'sometimes|string|email|max:255|unique:users,email,'.$user->id,
                'phone_number' => 'nullable|string|max:20',
                'dormitory_id' => 'nullable|exists:dormitories,id',
                'profile_picture' => 'nullable|string|max:255',
                'student_id' => 'nullable|string|max:255|unique:users,student_id,'.$user->id,
                'bio' => 'nullable|string',
                'date_of_birth' => 'nullable|date',
                'gender' => 'nullable|string|max:255',
                'language' => 'nullable|string|max:255',
                'timezone' => 'nullable|string|max:255',
            ];

            if ($request->hasFile('profile_picture')) {
                $rules['profile_picture'] = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
            }

            $validatedData = $request->validate($rules);

            if ($request->hasFile('profile_picture')) {
                $validatedData['profile_picture'] = $this->storeProfilePicture($request->file('profile_picture'), (int) $user->id);
            }

            $user->fill($validatedData);
            $user->account_completed = $this->isAccountCompleted($user);
            $user->save();

            return response()->json([
                'message' => 'Profile updated successfully',
                'user' => $user,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function settingsUniversityOptions(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'university_id' => 'nullable|integer|exists:universities,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $universities = University::query()
            ->select(['id', 'name', 'address'])
            ->orderBy('name')
            ->get();

        $currentDormitory = null;
        $currentUniversityId = null;

        if ($user->dormitory_id) {
            $currentDormitory = Dormitory::query()
                ->select(['id', 'dormitory_name', 'university_id', 'address', 'full_capacity', 'is_active'])
                ->whereKey($user->dormitory_id)
                ->first();
            $currentUniversityId = $currentDormitory?->university_id;
        }

        $selectedUniversityId = $validated['university_id'] ?? $currentUniversityId;
        $dormitories = collect();

        if ($selectedUniversityId) {
            $dormitories = Dormitory::query()
                ->select(['id', 'dormitory_name', 'university_id', 'address', 'full_capacity', 'is_active'])
                ->where('university_id', $selectedUniversityId)
                ->orderBy('dormitory_name')
                ->get();
        }

        return response()->json([
            'message' => 'University options retrieved successfully',
            'current' => [
                'university_id' => $currentUniversityId,
                'dormitory_id' => $user->dormitory_id,
            ],
            'universities' => $universities,
            'dormitories' => $dormitories,
        ], 200);
    }

    public function updateUniversitySettings(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'university_id' => 'required|integer|exists:universities,id',
                'dormitory_id' => 'required|integer|exists:dormitories,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $dormitory = Dormitory::query()
            ->whereKey($validated['dormitory_id'])
            ->where('university_id', $validated['university_id'])
            ->first();

        if (! $dormitory) {
            return response()->json([
                'message' => 'Dormitory not found for the selected university.',
            ], 404);
        }

        $user->dormitory_id = $dormitory->id;
        $user->save();

        $university = University::query()
            ->select(['id', 'name'])
            ->whereKey($validated['university_id'])
            ->first();

        return response()->json([
            'message' => 'University settings updated successfully',
            'user' => [
                'id' => $user->id,
                'dormitory_id' => $user->dormitory_id,
            ],
            'university' => $university,
            'dormitory' => [
                'id' => $dormitory->id,
                'dormitory_name' => $dormitory->dormitory_name,
                'is_active' => $dormitory->is_active,
            ],
        ], 200);
    }

    public function sendMessage(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'receiver_id' => 'required|integer|min:1|exists:users,id',
                'message_text' => 'required|string|max:2000',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        if ((int) $validated['receiver_id'] === (int) $user->id) {
            return response()->json([
                'message' => 'Receiver cannot be the sender.',
            ], 422);
        }

        $senderId = (int) $user->id;
        $receiverId = (int) $validated['receiver_id'];
        $participantA = min($senderId, $receiverId);
        $participantB = max($senderId, $receiverId);

        $conversation = DB::table('conversations')
            ->whereNull('product_id')
            ->where('buyer_id', $participantA)
            ->where('seller_id', $participantB)
            ->first();

        $conversationId = $conversation?->id;

        if (! $conversationId) {
            $conversationId = DB::table('conversations')->insertGetId([
                'product_id' => null,
                'buyer_id' => $participantA,
                'seller_id' => $participantB,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $messageId = DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'message_text' => $validated['message_text'],
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $message = DB::table('messages')->where('id', $messageId)->first();

        return response()->json([
            'message' => 'Message sent successfully',
            'conversation_id' => $conversationId,
            'message_data' => $message,
        ], 201);
    }

    public function myMessages(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'conversation_id' => 'required|integer|min:1|exists:conversations,id',
                'limit' => 'nullable|integer|min:1|max:100',
                'before_id' => 'nullable|integer|min:1',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $conversation = DB::table('conversations')
            ->select(['id', 'buyer_id', 'seller_id'])
            ->where('id', (int) $validated['conversation_id'])
            ->first();

        if (! $conversation) {
            return response()->json([
                'message' => 'Conversation not found.',
            ], 404);
        }

        if ((int) $conversation->buyer_id !== (int) $user->id && (int) $conversation->seller_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Unauthorized: You are not part of this conversation.',
            ], 403);
        }

        $limit = (int) ($validated['limit'] ?? 50);

        $messagesQuery = DB::table('messages')
            ->join('users', 'messages.sender_id', '=', 'users.id')
            ->where('messages.conversation_id', (int) $conversation->id)
            ->select([
                'messages.id',
                'messages.sender_id',
                'users.username as sender_username',
                'messages.message_text',
                'messages.read_at',
                'messages.created_at',
            ])
            ->orderByDesc('messages.id')
            ->limit($limit);

        if (array_key_exists('before_id', $validated) && $validated['before_id'] !== null) {
            $messagesQuery->where('messages.id', '<', (int) $validated['before_id']);
        }

        $messages = $messagesQuery->get()->reverse()->values();

        $readAt = now();
        $unreadIds = $messages
            ->filter(fn ($message) => (int) $message->sender_id !== (int) $user->id && $message->read_at === null)
            ->pluck('id')
            ->values();

        if ($unreadIds->isNotEmpty()) {
            DB::table('messages')
                ->whereIn('id', $unreadIds->all())
                ->update([
                    'read_at' => $readAt,
                    'updated_at' => now(),
                ]);

            $messages = $messages->map(function ($message) use ($unreadIds, $readAt) {
                if ($unreadIds->contains($message->id)) {
                    $message->read_at = $readAt;
                }

                return $message;
            })->values();
        }

        $otherUserId = (int) $conversation->buyer_id === (int) $user->id
            ? (int) $conversation->seller_id
            : (int) $conversation->buyer_id;

        $otherUser = DB::table('users')
            ->leftJoin('wallets', function ($join) use ($otherUserId) {
                $join->on('users.id', '=', 'wallets.user_id')
                    ->where('wallets.wallet_type_id', 1); // Primary wallet
            })
            ->select(['users.id', 'users.username', 'wallets.id as wallet_id'])
            ->where('users.id', $otherUserId)
            ->first();

        return response()->json([
            'message' => 'Messages retrieved successfully',
            'conversation' => [
                'id' => (int) $conversation->id,
                'other_user' => [
                    'id' => (int) $otherUser->id,
                    'username' => $otherUser->username,
                    'wallet_id' => $otherUser->wallet_id ? (int) $otherUser->wallet_id : null,
                ],
            ],
            'messages' => $messages,
        ], 200);
    }

    public function unreadMessages(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $limit = (int) ($validated['limit'] ?? 20);

        $rawMessages = DB::table('messages')
            ->join('conversations', 'messages.conversation_id', '=', 'conversations.id')
            ->join('users', 'messages.sender_id', '=', 'users.id')
            ->whereNull('messages.read_at')
            ->where('messages.sender_id', '!=', (int) $user->id)
            ->where(function ($query) use ($user) {
                $query->where('conversations.buyer_id', (int) $user->id)
                    ->orWhere('conversations.seller_id', (int) $user->id);
            })
            ->select([
                'messages.id',
                'messages.conversation_id',
                'messages.sender_id',
                'users.username as sender_username',
                'users.profile_picture as sender_profile_picture',
                'conversations.product_id',
                'messages.created_at',
            ])
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->get()
            ->values();

        $messages = $rawMessages
            ->groupBy(function ($message) {
                return $message->sender_id.'|'.($message->product_id ?? 'none');
            })
            ->map(function ($group) {
                $latest = $group->first();
                $count = $group->count();
                $notificationType = $latest->product_id ? 'product_message_received' : 'message_received';
                $notificationText = $latest->product_id
                    ? ($count > 1
                        ? "{$latest->sender_username} sent you {$count} messages about a product"
                        : "{$latest->sender_username} sent you a message about a product")
                    : ($count > 1
                        ? "{$latest->sender_username} sent you {$count} messages"
                        : "{$latest->sender_username} sent you a message");

                return [
                    'id' => (int) $latest->id,
                    'conversation_id' => (int) $latest->conversation_id,
                    'sender_id' => (int) $latest->sender_id,
                    'sender_username' => $latest->sender_username,
                    'sender_profile_picture' => $latest->sender_profile_picture,
                    'product_id' => $latest->product_id ? (int) $latest->product_id : null,
                    'notification_type' => $notificationType,
                    'notification_text' => $notificationText,
                    'notification_count' => $count,
                    'created_at' => $latest->created_at,
                ];
            })
            ->values();

        $rawNotifications = DB::table('notifications')
            ->where('notifiable_type', User::class)
            ->where('notifiable_id', (int) $user->id)
            ->whereNull('read_at')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        $notifications = $rawNotifications
            ->map(function ($notification) {
                $payload = json_decode($notification->data, true);
                $payload = is_array($payload) ? $payload : [];

                return [
                    'id' => $notification->id,
                    'conversation_id' => null,
                    'sender_id' => $payload['sender_id'] ?? null,
                    'sender_username' => $payload['sender_username'] ?? null,
                    'sender_profile_picture' => $payload['sender_profile_picture'] ?? null,
                    'product_id' => null,
                    'notification_type' => $notification->type,
                    'notification_text' => $payload['notification_text'] ?? 'You have a new notification',
                    'notification_count' => 1,
                    'amount' => $payload['amount'] ?? null,
                    'currency' => $payload['currency'] ?? null,
                    'wallet_id' => $payload['wallet_id'] ?? null,
                    'transaction_ledger_id' => $payload['transaction_ledger_id'] ?? null,
                    'data' => $payload,
                    'created_at' => $notification->created_at,
                ];
            })
            ->values();

        $items = $messages
            ->merge($notifications)
            ->sortByDesc('created_at')
            ->values()
            ->take($limit)
            ->values();

        return response()->json([
            'message' => 'Unread messages retrieved successfully',
            'total' => $items->count(),
            'messages' => $items,
        ], 200);
    }

    public function messageContacts(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'limit' => 'nullable|integer|min:1|max:100',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $userId = (int) $user->id;
        $limit = (int) ($validated['limit'] ?? 20);

        $latestMessages = DB::table('messages')
            ->select(['conversation_id', DB::raw('MAX(id) as last_message_id')])
            ->groupBy('conversation_id');

        $conversations = DB::table('conversations')
            ->joinSub($latestMessages, 'latest_messages', function ($join) {
                $join->on('conversations.id', '=', 'latest_messages.conversation_id');
            })
            ->join('messages', 'messages.id', '=', 'latest_messages.last_message_id')
            ->where(function ($query) use ($userId) {
                $query->where('conversations.buyer_id', $userId)
                    ->orWhere('conversations.seller_id', $userId);
            })
            ->select([
                'conversations.id as conversation_id',
                'conversations.buyer_id',
                'conversations.seller_id',
                'messages.id as last_message_id',
                'messages.message_text as last_message_text',
                'messages.created_at as last_message_created_at',
            ])
            ->orderByDesc('messages.id')
            ->limit($limit)
            ->get();

        $otherUserIds = $conversations
            ->map(fn ($row) => (int) ($row->buyer_id === $userId ? $row->seller_id : $row->buyer_id))
            ->unique()
            ->values();

        $usersById = DB::table('users')
            ->select(['id', 'username', 'profile_picture'])
            ->whereIn('id', $otherUserIds->all())
            ->get()
            ->keyBy('id');

        $contacts = $conversations
            ->map(function ($row) use ($usersById, $userId) {
                $otherUserId = (int) ($row->buyer_id === $userId ? $row->seller_id : $row->buyer_id);
                $otherUser = $usersById->get($otherUserId);

                return [
                    'conversation_id' => (int) $row->conversation_id,
                    'user' => $otherUser ? [
                        'id' => (int) $otherUser->id,
                        'username' => $otherUser->username,
                        'profile_picture' => $otherUser->profile_picture,
                    ] : null,
                    'last_message' => [
                        'id' => (int) $row->last_message_id,
                        'message_text' => $row->last_message_text,
                        'created_at' => $row->last_message_created_at,
                    ],
                ];
            })
            ->values();

        return response()->json([
            'message' => 'Message contacts retrieved successfully',
            'total' => $contacts->count(),
            'contacts' => $contacts,
        ], 200);
    }

    public function language(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'message' => 'User language retrieved successfully',
            'language' => $user->language,
        ], 200);
    }

    public function profilePicture(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'message' => 'User profile picture retrieved successfully',
            'profile_picture' => $user->profile_picture,
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $token = $user?->currentAccessToken();

        if ($token) {
            $token->delete();
        }

        return response()->json([
            'message' => 'Logout successful',
        ], 200);
    }

    public function me(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'message' => 'User retrieved successfully',
            'user' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'username' => $user->username,
                'email' => $user->email,
                'profile_picture' => $user->profile_picture,
                'dormitory_id' => $user->dormitory_id,
                'role' => $user->role,
            ],
        ], 200);
    }
}
