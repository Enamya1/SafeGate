<?php

namespace App\Http\Controllers;

use App\Models\Dormitory;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
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

        if (! Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid login details',
            ], 401);
        }

        $user = User::where('email', $request->email)->firstOrFail();

        if ($user->role !== 'admin') {
            Auth::logout();

            return response()->json([
                'message' => 'Unauthorized: Only administrators can log in here.',
            ], 403);
        }

        $token = $user->createToken('admin_auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Admin login successful',
            'access_token' => $token,
            'token_type' => 'Bearer',
        ]);
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
}
