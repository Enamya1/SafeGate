<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BehavioralEventController extends Controller
{
    public function store(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'event_type' => 'required|string|max:50',
                'product_id' => 'nullable|integer|min:1',
                'category_id' => 'nullable|integer|min:1',
                'seller_id' => 'nullable|integer|min:1',
                'metadata' => 'nullable|array',
                'occurred_at' => 'nullable|date',
                'session_id' => 'nullable|string|max:100',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        if (array_key_exists('product_id', $validated) && $validated['product_id'] !== null) {
            $productExists = Product::query()
                ->whereKey((int) $validated['product_id'])
                ->whereNull('deleted_at')
                ->exists();

            if (! $productExists) {
                return response()->json([
                    'message' => 'Product not found.',
                ], 404);
            }
        }

        $id = DB::table('behavioral_events')->insertGetId([
            'user_id' => $user->id,
            'event_type' => $validated['event_type'],
            'product_id' => $validated['product_id'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'seller_id' => $validated['seller_id'] ?? null,
            'metadata' => array_key_exists('metadata', $validated) ? json_encode($validated['metadata']) : null,
            'occurred_at' => $validated['occurred_at'] ?? now(),
            'session_id' => $validated['session_id'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = DB::table('behavioral_events')->where('id', $id)->first();

        return response()->json([
            'message' => 'Behavioral event stored successfully',
            'event' => $event,
        ], 201);
    }
}
