<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AdminController extends Controller
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

    private function extractToken(Request $request): ?string
    {
        $authorization = $request->headers->get('Authorization');

        if (is_string($authorization) && $authorization !== '' && str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        $token =
            $request->headers->get('X-Access-Token')
            ?? $request->headers->get('X-Authorization')
            ?? $request->query('access_token')
            ?? $request->input('access_token');

        if (! is_string($token) || $token === '') {
            return null;
        }

        if (str_starts_with($token, 'Bearer ')) {
            return substr($token, 7);
        }

        return $token;
    }

    public function index()
    {
        return response()->json(['message' => 'Admin endpoint reached!']);
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

        if ($user->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can log in here.',
            ], 403);
        }

        if ($user->status === 'inactive') {
            return response()->json([
                'message' => 'Your admin account is deactivated. Please contact support.',
            ], 403);
        }

        $token = $user->createToken('admin_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Admin login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
    }

    public function logout(Request $request)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        $token = $this->extractToken($request);

        if ($token) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken) {
                $accessToken->delete();
            }
        }

        return response()->json([
            'message' => 'Logout successful',
        ], 200);
    }

    public function set_university(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:universities',
                'domain' => 'required|string|max:255|unique:universities',
                'location' => 'nullable|string|max:255',
                'pic' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $university = University::create([
            'name' => $validatedData['name'],
            'domain' => $validatedData['domain'],
            'location' => $validatedData['location'] ?? null,
            'pic' => $validatedData['pic'] ?? null,
        ]);

        return response()->json([
            'message' => 'University created successfully',
            'university' => $university,
        ], 201);
    }

    public function set_dormitory(Request $request)
    {
        try {
            $validatedData = $request->validate([
                'dormitory_name' => 'required|string|max:255|unique:dormitories',
                'domain' => 'required|string|max:255|unique:dormitories',
                'location' => 'nullable|url|max:255',
                'is_active' => 'boolean',
                'university_name' => 'required|string|max:255|exists:universities,name',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $university = University::where('name', $validatedData['university_name'])->first();

        if (! $university) {
            return response()->json([
                'message' => 'University not found.',
            ], 404);
        }

        $dormitory = Dormitory::create([
            'dormitory_name' => $validatedData['dormitory_name'],
            'domain' => $validatedData['domain'],
            'location' => $validatedData['location'] ?? null,
            'is_active' => $validatedData['is_active'] ?? true,
            'university_id' => $university->id,
        ]);

        return response()->json([
            'message' => 'Dormitory created successfully',
            'dormitory' => $dormitory,
        ], 201);
    }

    public function createCategory(Request $request)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255',
                'parent_id' => 'nullable|integer|exists:categories,id',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $category = Category::create([
            'name' => $validatedData['name'],
            'parent_id' => $validatedData['parent_id'] ?? null,
        ]);

        return response()->json([
            'message' => 'Category created successfully',
            'category' => $category,
        ], 201);
    }

    public function createConditionLevel(Request $request)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        try {
            $validatedData = $request->validate([
                'name' => 'required|string|max:255|unique:condition_levels,name',
                'description' => 'nullable|string',
                'sort_order' => 'nullable|integer|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $conditionLevel = ConditionLevel::create([
            'name' => $validatedData['name'],
            'description' => $validatedData['description'] ?? null,
            'sort_order' => $validatedData['sort_order'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Condition level created successfully',
            'condition_level' => $conditionLevel,
        ], 201);
    }

    public function listUniversities()
    {
        $universities = University::all();

        return response()->json([
            'message' => 'Universities retrieved successfully',
            'universities' => $universities,
        ], 200);
    }

    public function listDormitoriesByUniversity(string $university_name)
    {
        $university = University::where('name', $university_name)->first();

        if (! $university) {
            return response()->json([
                'message' => 'University not found.',
            ], 404);
        }

        $dormitories = Dormitory::where('university_id', $university->id)->get();

        return response()->json([
            'message' => 'Dormitories retrieved successfully',
            'university' => $university->name,
            'dormitories' => $dormitories,
        ], 200);
    }

    public function listUsers(Request $request)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'per_page' => 'nullable|integer|min:1|max:100',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $perPage = (int) ($validated['per_page'] ?? 10);

        $users = User::query()
            ->leftJoin('dormitories', 'users.dormitory_id', '=', 'dormitories.id')
            ->leftJoin('universities', 'dormitories.university_id', '=', 'universities.id')
            ->leftJoin('products', function ($join) {
                $join->on('users.id', '=', 'products.seller_id')
                    ->whereNull('products.deleted_at');
            })
            ->select([
                'users.id',
                'users.full_name',
                'users.email',
                'users.role',
                'users.status',
                'users.profile_picture',
                'users.last_login_at',
                'universities.name as university_name',
                DB::raw('COUNT(products.id) as product_count'),
                DB::raw("SUM(CASE WHEN products.status = 'sold' THEN 1 ELSE 0 END) as sold_counter"),
            ])
            ->groupBy([
                'users.id',
                'users.full_name',
                'users.email',
                'users.role',
                'users.status',
                'users.profile_picture',
                'users.last_login_at',
                'universities.name',
            ])
            ->orderBy('users.id')
            ->paginate($perPage);

        return response()->json([
            'message' => 'Users retrieved successfully',
            'users' => $users,
        ], 200);
    }

    public function showUser(Request $request, string $id)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'message' => 'User retrieved successfully',
            'user' => $user,
        ], 200);
    }

    public function updateUser(Request $request, string $id)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        try {
            $rules = [
                'full_name' => 'sometimes|string|max:255',
                'username' => 'sometimes|string|max:255|unique:users,username,'.$id,
                'email' => 'sometimes|string|email|max:255|unique:users,email,'.$id,
                'phone_number' => 'nullable|string|max:20',
                'role' => 'sometimes|string|in:admin,user',
                'dormitory_id' => 'nullable|exists:dormitories,id',
                'status' => 'sometimes|string|in:active,inactive,suspended',
                'profile_picture' => 'nullable|string|max:255',
                'locked_until' => 'nullable|date',
            ];

            if ($request->hasFile('profile_picture')) {
                $rules['profile_picture'] = ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'];
            }

            $validatedData = $request->validate($rules);

            if ($request->hasFile('profile_picture')) {
                $validatedData['profile_picture'] = $this->storeProfilePicture($request->file('profile_picture'), (int) $user->id);
            }
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $user->update($validatedData);

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $user,
        ], 200);
    }

    public function activateUser(Request $request, string $id)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $user->status = 'active';
        $user->save();

        return response()->json([
            'message' => 'User activated successfully',
            'user_id' => $user->id,
        ], 200);
    }

    public function deactivateUser(Request $request, string $id)
    {
        $admin = $request->user();

        if (! $admin || $admin->role !== 'admin') {
            return response()->json([
                'message' => 'Unauthorized: Only administrators can access this endpoint.',
            ], 403);
        }

        $user = User::find($id);

        if (! $user) {
            return response()->json([
                'message' => 'User not found.',
            ], 404);
        }

        $user->status = 'inactive';
        $user->save();

        return response()->json([
            'message' => 'User deactivated successfully',
            'user_id' => $user->id,
        ], 200);
    }
}
