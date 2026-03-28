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
        $path = ltrim($path, '/');
        $request = request();
        $baseUrl = '';
        if ($request) {
            $baseUrl = $request->getSchemeAndHttpHost();
        }

        if ($baseUrl === '') {
            $baseUrl = (string) config('filesystems.disks.public.url', '');
        }

        if ($baseUrl === '') {
            return '/storage/'.$path;
        }

        $baseUrl = rtrim($baseUrl, '/');

        if (str_ends_with($baseUrl, '/storage')) {
            return $baseUrl.'/'.$path;
        }

        return $baseUrl.'/storage/'.$path;
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
                'messages.message_type',
                'messages.transfer_data',
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

        $messages = $messages->map(function ($message) {
            $type = is_string($message->message_type ?? null) ? $message->message_type : 'text';
            $transferDataRaw = $message->transfer_data ?? null;
            $transferData = null;
            if (is_string($transferDataRaw) && trim($transferDataRaw) !== '') {
                try {
                    $decoded = json_decode($transferDataRaw, true);
                    $transferData = is_array($decoded) ? $decoded : null;
                } catch (\Throwable $e) {
                    $transferData = null;
                }
            }
            $kind = 'normal';
            $paymentStatus = null;
            if ($type === 'payment_request') {
                $paymentStatus = is_array($transferData) ? ($transferData['status'] ?? 'pending') : 'pending';
                $kind = $paymentStatus === 'paid' ? 'payment_request_confirmed' : 'payment_request_unconfirmed';
            } elseif ($type === 'payment_confirmation') {
                $paymentStatus = 'paid';
                $kind = 'payment_request_confirmed';
            } elseif ($type === 'transfer') {
                $kind = 'transfer';
            }
            return [
                'id' => (int) $message->id,
                'sender_id' => (int) $message->sender_id,
                'sender_username' => $message->sender_username,
                'message_text' => $message->message_text,
                'read_at' => $message->read_at,
                'created_at' => $message->created_at,
                'message_type' => $type,
                'message_kind' => $kind,
                'payment_request_status' => $paymentStatus,
                'transfer_data' => $transferData,
            ];
        })->values();

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

        // Get current user's wallet
        $currentUserWallet = DB::table('wallets')
            ->select('id')
            ->where('user_id', (int) $user->id)
            ->where('wallet_type_id', 1) // Primary wallet
            ->first();

        return response()->json([
            'message' => 'Messages retrieved successfully',
            'conversation' => [
                'id' => (int) $conversation->id,
                'current_user_wallet_id' => $currentUserWallet ? (int) $currentUserWallet->id : null,
                'other_user' => [
                    'id' => (int) $otherUser->id,
                    'username' => $otherUser->username,
                    'wallet_id' => $otherUser->wallet_id ? (int) $otherUser->wallet_id : null,
                ],
            ],
            'messages' => $messages,
        ], 200);
    }

    public function transferMoney(Request $request)
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
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'required|string|size:3',
                'reference' => 'nullable|string|max:255',
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

        // Determine the other user's wallet ID
        $otherUserId = (int) $conversation->buyer_id === (int) $user->id
            ? (int) $conversation->seller_id
            : (int) $conversation->buyer_id;

        // Get sender's primary wallet
        $senderWallet = DB::table('wallets')
            ->select('id', 'balance', 'currency', 'status_id')
            ->where('user_id', (int) $user->id)
            ->where('wallet_type_id', 1)
            ->first();

        if (! $senderWallet) {
            return response()->json([
                'message' => 'You do not have a wallet.',
            ], 409);
        }

        // Check if wallet is active
        if ($senderWallet->status_id !== 1) { // Assuming 1 = active
            return response()->json([
                'message' => 'Your wallet is not active.',
            ], 409);
        }

        // Check currency match
        if ($senderWallet->currency !== $validated['currency']) {
            return response()->json([
                'message' => 'Currency mismatch.',
            ], 409);
        }

        // Check sufficient balance
        if ((float) $senderWallet->balance < (float) $validated['amount']) {
            return response()->json([
                'message' => 'Insufficient balance.',
            ], 409);
        }

        // Get recipient's primary wallet
        $recipientWallet = DB::table('wallets')
            ->select('id', 'currency', 'status_id')
            ->where('user_id', $otherUserId)
            ->where('wallet_type_id', 1)
            ->first();

        if (! $recipientWallet) {
            return response()->json([
                'message' => 'Recipient does not have a wallet.',
            ], 404);
        }

        // Check if recipient wallet is active
        if ($recipientWallet->status_id !== 1) {
            return response()->json([
                'message' => 'Recipient wallet is not active.',
            ], 409);
        }

        // Check currency match
        if ($recipientWallet->currency !== $validated['currency']) {
            return response()->json([
                'message' => 'Recipient wallet currency does not match.',
            ], 409);
        }

        // Perform the transfer using atomic transaction
        try {
            $atomicTransactionId = DB::transaction(function () use ($senderWallet, $recipientWallet, $validated, $user, $conversation) {
                // Create atomic transaction record
                $atomicTransactionId = DB::table('atomic_transactions')->insertGetId([
                    'atomic_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'status' => 'completed',
                    'total_amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'initiated_by' => (int) $user->id,
                    'metadata' => json_encode([
                        'type' => 'chat_transfer',
                        'conversation_id' => (int) $conversation->id,
                        'reference' => $validated['reference'] ?? null,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Debit from sender
                $debitLedgerId = DB::table('transaction_ledgers')->insertGetId([
                    'ledger_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'atomic_transaction_id' => $atomicTransactionId,
                    'wallet_id' => (int) $senderWallet->id,
                    'related_wallet_id' => (int) $recipientWallet->id,
                    'direction' => 'debit',
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'status' => 'completed',
                    'type' => 'transfer_sent',
                    'reference' => $validated['reference'] ?? null,
                    'metadata' => json_encode([
                        'conversation_id' => (int) $conversation->id,
                        'recipient_wallet_id' => (int) $recipientWallet->id,
                    ]),
                    'occurred_at' => now(),
                    'initiated_by' => (int) $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Credit to recipient
                $creditLedgerId = DB::table('transaction_ledgers')->insertGetId([
                    'ledger_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'atomic_transaction_id' => $atomicTransactionId,
                    'wallet_id' => (int) $recipientWallet->id,
                    'related_wallet_id' => (int) $senderWallet->id,
                    'direction' => 'credit',
                    'amount' => $validated['amount'],
                    'currency' => $validated['currency'],
                    'status' => 'completed',
                    'type' => 'transfer_received',
                    'reference' => $validated['reference'] ?? null,
                    'metadata' => json_encode([
                        'conversation_id' => (int) $conversation->id,
                        'sender_wallet_id' => (int) $senderWallet->id,
                    ]),
                    'occurred_at' => now(),
                    'initiated_by' => (int) $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update wallet balances
                DB::table('wallets')
                    ->where('id', (int) $senderWallet->id)
                    ->decrement('balance', $validated['amount']);

                DB::table('wallets')
                    ->where('id', (int) $recipientWallet->id)
                    ->increment('balance', $validated['amount']);

                // Create transfer message
                $transferData = [
                    'amount' => (float) $validated['amount'],
                    'currency' => $validated['currency'],
                    'from_wallet_id' => (int) $senderWallet->id,
                    'to_wallet_id' => (int) $recipientWallet->id,
                    'transaction_ledger_id' => $creditLedgerId,
                    'atomic_transaction_id' => $atomicTransactionId,
                    'sender_username' => $user->username,
                    'reference' => $validated['reference'] ?? null,
                ];

                DB::table('messages')->insert([
                    'conversation_id' => (int) $conversation->id,
                    'sender_id' => (int) $user->id,
                    'message_text' => sprintf('Sent %.2f %s', $validated['amount'], $validated['currency']),
                    'message_type' => 'transfer',
                    'transfer_data' => json_encode($transferData),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $atomicTransactionId;
            });

            return response()->json([
                'message' => 'Transfer completed successfully',
                'transfer' => [
                    'amount' => (float) $validated['amount'],
                    'currency' => $validated['currency'],
                    'from_wallet_id' => (int) $senderWallet->id,
                    'to_wallet_id' => (int) $recipientWallet->id,
                    'atomic_transaction_id' => $atomicTransactionId,
                    'reference' => $validated['reference'] ?? null,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Transfer failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function createPaymentRequest(Request $request)
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
                'product_id' => 'nullable|integer|min:1|exists:products,id',
                'amount' => 'required|numeric|min:0.01',
                'currency' => 'required|string|size:3',
                'message' => 'nullable|string|max:1000',
                'expires_in_hours' => 'nullable|integer|min:1|max:720', // Default 24 hours, max 30 days
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

        // Determine the other user (receiver of the payment request)
        $otherUserId = (int) $conversation->buyer_id === (int) $user->id
            ? (int) $conversation->seller_id
            : (int) $conversation->buyer_id;

        // Get product details if provided
        $product = null;
        if (isset($validated['product_id'])) {
            $product = DB::table('products')
                ->select(['id', 'title', 'price', 'seller_id'])
                ->where('id', (int) $validated['product_id'])
                ->first();

            if (! $product) {
                return response()->json([
                    'message' => 'Product not found.',
                ], 404);
            }

            // Verify product belongs to one of the conversation participants
            if ($product->seller_id !== (int) $user->id && $product->seller_id !== $otherUserId) {
                return response()->json([
                    'message' => 'Product is not related to this conversation.',
                ], 409);
            }
        }

        // Calculate expiration time
        $expiresAt = $validated['expires_in_hours'] ?? 24;
        $expiresAtTime = now()->addHours($expiresAt);

        // Create payment request
        $paymentRequestId = DB::table('payment_requests')->insertGetId([
            'conversation_id' => (int) $conversation->id,
            'sender_id' => (int) $user->id,
            'receiver_id' => $otherUserId,
            'product_id' => isset($validated['product_id']) ? (int) $validated['product_id'] : null,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'],
            'status' => 'pending',
            'message' => $validated['message'] ?? null,
            'expires_at' => $expiresAtTime,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create a message about the payment request
        $messageText = sprintf(
            'Payment request created for %s %s%s',
            number_format($validated['amount'], 2),
            $validated['currency'],
            $product ? ' - ' . $product->title : ''
        );

        if (isset($validated['message'])) {
            $messageText .= ': ' . $validated['message'];
        }

        $messageId = DB::table('messages')->insertGetId([
            'conversation_id' => (int) $conversation->id,
            'sender_id' => (int) $user->id,
            'message_text' => $messageText,
            'message_type' => 'payment_request',
            'transfer_data' => json_encode([
                'type' => 'payment_request',
                'payment_request_id' => $paymentRequestId,
                'amount' => (float) $validated['amount'],
                'currency' => $validated['currency'],
                'product_id' => $product?->id,
                'product_title' => $product?->title,
                'sender_username' => $user->username,
                'receiver_id' => $otherUserId,
                'expires_at' => $expiresAtTime->toIso8601String(),
                'reference' => $validated['message'] ?? null,
            ]),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update payment request with message_id
        DB::table('payment_requests')
            ->where('id', $paymentRequestId)
            ->update(['message_id' => $messageId]);

        return response()->json([
            'message' => 'Payment request created successfully',
            'payment_request' => [
                'id' => $paymentRequestId,
                'conversation_id' => (int) $conversation->id,
                'sender_id' => (int) $user->id,
                'sender_username' => $user->username,
                'receiver_id' => $otherUserId,
                'product_id' => $product?->id,
                'product_title' => $product?->title,
                'amount' => (float) $validated['amount'],
                'currency' => $validated['currency'],
                'status' => 'pending',
                'message' => $validated['message'] ?? null,
                'expires_at' => $expiresAtTime->toIso8601String(),
                'created_at' => now()->toIso8601String(),
            ],
        ], 201);
    }

    public function confirmPaymentRequest(Request $request, int $requestId)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $paymentRequest = DB::table('payment_requests')
            ->select([
                'id',
                'conversation_id',
                'sender_id',
                'receiver_id',
                'product_id',
                'amount',
                'currency',
                'status',
                'message',
                'expires_at',
                'message_id',
            ])
            ->where('id', $requestId)
            ->first();

        if (! $paymentRequest) {
            return response()->json([
                'message' => 'Payment request not found.',
            ], 404);
        }

        // Check if user is the receiver
        if ((int) $paymentRequest->receiver_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Unauthorized: You are not the receiver of this payment request.',
            ], 403);
        }

        // Check status
        if ($paymentRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This payment request has already been ' . $paymentRequest->status . '.',
            ], 409);
        }

        // Check expiration
        if ($paymentRequest->expires_at && now()->gt($paymentRequest->expires_at)) {
            // Mark as expired
            DB::table('payment_requests')
                ->where('id', $paymentRequest->id)
                ->update(['status' => 'expired']);

            return response()->json([
                'message' => 'Payment request has expired.',
            ], 409);
        }

        // Get wallets
        $payerWallet = DB::table('wallets')
            ->select('id', 'balance', 'currency', 'status_id')
            ->where('user_id', (int) $user->id)
            ->where('wallet_type_id', 1)
            ->first();

        if (! $payerWallet) {
            return response()->json([
                'message' => 'You do not have a wallet.',
            ], 409);
        }

        if ($payerWallet->status_id !== 1) {
            return response()->json([
                'message' => 'Your wallet is not active.',
            ], 409);
        }

        if ($payerWallet->currency !== $paymentRequest->currency) {
            return response()->json([
                'message' => 'Currency mismatch.',
            ], 409);
        }

        if ((float) $payerWallet->balance < (float) $paymentRequest->amount) {
            return response()->json([
                'message' => 'Insufficient balance.',
            ], 409);
        }

        // Get recipient's wallet
        $recipientWallet = DB::table('wallets')
            ->select('id', 'currency', 'status_id')
            ->where('user_id', (int) $paymentRequest->sender_id)
            ->where('wallet_type_id', 1)
            ->first();

        if (! $recipientWallet) {
            return response()->json([
                'message' => 'Recipient does not have a wallet.',
            ], 404);
        }

        if ($recipientWallet->status_id !== 1) {
            return response()->json([
                'message' => 'Recipient wallet is not active.',
            ], 409);
        }

        if ($recipientWallet->currency !== $paymentRequest->currency) {
            return response()->json([
                'message' => 'Recipient wallet currency does not match.',
            ], 409);
        }

        // Perform the payment
        try {
            $atomicTransactionId = DB::transaction(function () use ($paymentRequest, $payerWallet, $recipientWallet, $user) {
                // Create atomic transaction record
                $atomicTransactionId = DB::table('atomic_transactions')->insertGetId([
                    'atomic_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'status' => 'completed',
                    'total_amount' => $paymentRequest->amount,
                    'currency' => $paymentRequest->currency,
                    'initiated_by' => (int) $user->id,
                    'metadata' => json_encode([
                        'type' => 'payment_request_fulfillment',
                        'payment_request_id' => $paymentRequest->id,
                        'conversation_id' => (int) $paymentRequest->conversation_id,
                    ]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Debit from payer
                $debitLedgerId = DB::table('transaction_ledgers')->insertGetId([
                    'ledger_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'atomic_transaction_id' => $atomicTransactionId,
                    'wallet_id' => (int) $payerWallet->id,
                    'related_wallet_id' => (int) $recipientWallet->id,
                    'direction' => 'debit',
                    'amount' => $paymentRequest->amount,
                    'currency' => $paymentRequest->currency,
                    'status' => 'completed',
                    'type' => 'payment_request_paid',
                    'reference' => $paymentRequest->message,
                    'metadata' => json_encode([
                        'payment_request_id' => $paymentRequest->id,
                        'conversation_id' => (int) $paymentRequest->conversation_id,
                        'product_id' => $paymentRequest->product_id,
                    ]),
                    'occurred_at' => now(),
                    'initiated_by' => (int) $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Credit to recipient
                $creditLedgerId = DB::table('transaction_ledgers')->insertGetId([
                    'ledger_uuid' => (string) \Illuminate\Support\Str::uuid(),
                    'atomic_transaction_id' => $atomicTransactionId,
                    'wallet_id' => (int) $recipientWallet->id,
                    'related_wallet_id' => (int) $payerWallet->id,
                    'direction' => 'credit',
                    'amount' => $paymentRequest->amount,
                    'currency' => $paymentRequest->currency,
                    'status' => 'completed',
                    'type' => 'payment_request_received',
                    'reference' => $paymentRequest->message,
                    'metadata' => json_encode([
                        'payment_request_id' => $paymentRequest->id,
                        'conversation_id' => (int) $paymentRequest->conversation_id,
                        'product_id' => $paymentRequest->product_id,
                    ]),
                    'occurred_at' => now(),
                    'initiated_by' => (int) $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update wallet balances
                DB::table('wallets')
                    ->where('id', (int) $payerWallet->id)
                    ->decrement('balance', $paymentRequest->amount);

                DB::table('wallets')
                    ->where('id', (int) $recipientWallet->id)
                    ->increment('balance', $paymentRequest->amount);

                // Update payment request status
                DB::table('payment_requests')
                    ->where('id', $paymentRequest->id)
                    ->update([
                        'status' => 'paid',
                        'atomic_transaction_id' => $atomicTransactionId,
                    ]);

                // Create payment confirmation message
                $recipient = DB::table('users')->select('username')->where('id', (int) $paymentRequest->sender_id)->first();
                
                $confirmationMessageText = sprintf(
                    'Payment request of %s %s confirmed and paid%s',
                    number_format($paymentRequest->amount, 2),
                    $paymentRequest->currency,
                    $paymentRequest->product_id ? ' - Product ID: ' . $paymentRequest->product_id : ''
                );

                $confirmationMessageId = DB::table('messages')->insertGetId([
                    'conversation_id' => (int) $paymentRequest->conversation_id,
                    'sender_id' => (int) $user->id,
                    'message_text' => $confirmationMessageText,
                    'message_type' => 'payment_confirmation',
                    'transfer_data' => json_encode([
                        'type' => 'payment_confirmation',
                        'payment_request_id' => $paymentRequest->id,
                        'amount' => (float) $paymentRequest->amount,
                        'currency' => $paymentRequest->currency,
                        'product_id' => $paymentRequest->product_id,
                        'atomic_transaction_id' => $atomicTransactionId,
                        'payer_username' => $user->username,
                        'recipient_username' => $recipient?->username,
                        'transaction_ledger_id' => $creditLedgerId,
                    ]),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Update original payment request message
                if ($paymentRequest->message_id) {
                    $originalMessageData = DB::table('messages')->where('id', $paymentRequest->message_id)->first();
                    if ($originalMessageData) {
                        $transferData = json_decode($originalMessageData->transfer_data, true);
                        if (is_array($transferData)) {
                            $transferData['status'] = 'paid';
                            $transferData['paid_at'] = now()->toIso8601String();
                            $transferData['atomic_transaction_id'] = $atomicTransactionId;
                            
                            DB::table('messages')
                                ->where('id', $paymentRequest->message_id)
                                ->update(['transfer_data' => json_encode($transferData)]);
                        }
                    }
                }

                if ($paymentRequest->product_id) {
                    DB::table('products')
                        ->where('id', (int) $paymentRequest->product_id)
                        ->update(['status' => 'sold', 'updated_at' => now()]);
                }

                return $atomicTransactionId;
            });

            return response()->json([
                'message' => 'Payment request confirmed and paid successfully',
                'payment' => [
                    'payment_request_id' => $paymentRequest->id,
                    'amount' => (float) $paymentRequest->amount,
                    'currency' => $paymentRequest->currency,
                    'from_wallet_id' => (int) $payerWallet->id,
                    'to_wallet_id' => (int) $recipientWallet->id,
                    'atomic_transaction_id' => $atomicTransactionId,
                    'status' => 'paid',
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Payment failed: ' . $e->getMessage(),
            ], 500);
        }
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
