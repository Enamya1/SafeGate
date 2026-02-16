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

        $user = User::create([
            'full_name' => $validatedData['full_name'],
            'username' => $validatedData['username'],
            'email' => $validatedData['email'],
            'phone_number' => data_get($validatedData, 'phone_number', null),
            'password' => Hash::make($validatedData['password']),
            'dormitory_id' => data_get($validatedData, 'dormitory_id', null),
        ]);

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
        $user->save();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
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

        $selectedUniversityId = $validated['university_id'] ?? null;
        $dormitories = collect();

        if ($selectedUniversityId) {
            $dormitories = Dormitory::query()
                ->select(['id', 'dormitory_name', 'university_id', 'address', 'full_capacity', 'is_active'])
                ->where('university_id', $selectedUniversityId)
                ->orderBy('dormitory_name')
                ->get();
        }

        $currentDormitory = null;
        $currentUniversityId = null;

        if ($user->dormitory_id) {
            $currentDormitory = Dormitory::query()
                ->select(['id', 'dormitory_name', 'university_id', 'address', 'full_capacity', 'is_active'])
                ->whereKey($user->dormitory_id)
                ->first();
            $currentUniversityId = $currentDormitory?->university_id;
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
                'messages.conversation_id',
                'messages.sender_id',
                'users.username as sender_username',
                'users.full_name as sender_full_name',
                'users.profile_picture as sender_profile_picture',
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

        $otherUserId = (int) $conversation->buyer_id === (int) $user->id
            ? (int) $conversation->seller_id
            : (int) $conversation->buyer_id;

        $otherUser = DB::table('users')
            ->select(['id', 'username', 'full_name', 'profile_picture'])
            ->where('id', $otherUserId)
            ->first();

        return response()->json([
            'message' => 'Messages retrieved successfully',
            'conversation' => [
                'id' => (int) $conversation->id,
                'other_user' => $otherUser,
            ],
            'messages' => $messages,
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
                'role' => $user->role,
            ],
        ], 200);
    }
}
