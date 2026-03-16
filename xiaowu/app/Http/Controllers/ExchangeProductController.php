<?php

namespace App\Http\Controllers;

use App\Models\ExchangeProduct;
use App\Models\Product;
use App\Models\ProductTag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ExchangeProductController extends Controller
{
    private function pythonServiceBaseUrl(): string
    {
        return rtrim((string) env('PYTHON_SERVICE_BASE_URL', 'http://127.0.0.1:8001'), '/');
    }

    public function index(Request $request)
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
                'q' => 'nullable|string|max:200',
                'category_id' => 'nullable|integer|exists:categories,id',
                'condition_level_id' => 'nullable|integer|exists:condition_levels,id',
                'dormitory_id' => 'nullable|integer|exists:dormitories,id',
                'seller_id' => 'nullable|integer|exists:users,id',
                'exchange_type' => 'nullable|string|max:50',
                'exchange_status' => 'nullable|string|max:40',
                'target_category_id' => 'nullable|integer|exists:categories,id',
                'target_condition_id' => 'nullable|integer|exists:condition_levels,id',
                'min_price' => 'nullable|numeric|min:0',
                'max_price' => 'nullable|numeric|min:0',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 20);

        $query = DB::table('exchange_products')
            ->join('products', 'exchange_products.product_id', '=', 'products.id')
            ->leftJoin('categories as product_categories', 'products.category_id', '=', 'product_categories.id')
            ->leftJoin('condition_levels as product_conditions', 'products.condition_level_id', '=', 'product_conditions.id')
            ->leftJoin('dormitories', 'products.dormitory_id', '=', 'dormitories.id')
            ->leftJoin('categories as target_categories', 'exchange_products.target_product_category_id', '=', 'target_categories.id')
            ->leftJoin('condition_levels as target_conditions', 'exchange_products.target_product_condition_id', '=', 'target_conditions.id')
            ->whereNull('products.deleted_at')
            ->where('products.status', 'available');

        $now = now();
        $query->where(function ($sub) use ($now) {
            $sub->whereNull('exchange_products.expiration_date')
                ->orWhere('exchange_products.expiration_date', '>', $now);
        });

        $exchangeStatus = $validated['exchange_status'] ?? 'open';
        if ($exchangeStatus !== null) {
            $query->where('exchange_products.exchange_status', $exchangeStatus);
        }

        if (! empty($validated['exchange_type'])) {
            $query->where('exchange_products.exchange_type', $validated['exchange_type']);
        }

        if (! empty($validated['category_id'])) {
            $query->where('products.category_id', (int) $validated['category_id']);
        }

        if (! empty($validated['condition_level_id'])) {
            $query->where('products.condition_level_id', (int) $validated['condition_level_id']);
        }

        if (! empty($validated['dormitory_id'])) {
            $query->where('products.dormitory_id', (int) $validated['dormitory_id']);
        }

        if (! empty($validated['seller_id'])) {
            $query->where('products.seller_id', (int) $validated['seller_id']);
        }

        if (! empty($validated['target_category_id'])) {
            $query->where('exchange_products.target_product_category_id', (int) $validated['target_category_id']);
        }

        if (! empty($validated['target_condition_id'])) {
            $query->where('exchange_products.target_product_condition_id', (int) $validated['target_condition_id']);
        }

        if (array_key_exists('min_price', $validated) && $validated['min_price'] !== null) {
            $query->where('products.price', '>=', (float) $validated['min_price']);
        }

        if (array_key_exists('max_price', $validated) && $validated['max_price'] !== null) {
            $query->where('products.price', '<=', (float) $validated['max_price']);
        }

        if (! empty($validated['q'])) {
            $q = '%'.$validated['q'].'%';
            $query->where(function ($sub) use ($q) {
                $sub->where('products.title', 'like', $q)
                    ->orWhere('products.description', 'like', $q)
                    ->orWhere('exchange_products.target_product_title', 'like', $q);
            });
        }

        $total = (clone $query)->count();

        $rows = $query
            ->select([
                'exchange_products.id as exchange_product_id',
                'exchange_products.exchange_type',
                'exchange_products.exchange_status',
                'exchange_products.expiration_date',
                'exchange_products.target_product_title',
                'exchange_products.target_product_category_id',
                'exchange_products.target_product_condition_id',
                'products.id as product_id',
                'products.seller_id',
                'products.dormitory_id',
                'products.category_id',
                'products.condition_level_id',
                'products.title',
                'products.description',
                'products.price',
                'products.currency',
                'products.status',
                'products.created_at',
                'product_categories.name as product_category_name',
                'product_categories.parent_id as product_category_parent_id',
                'product_categories.logo as product_category_icon',
                'product_conditions.name as product_condition_name',
                'product_conditions.sort_order as product_condition_sort_order',
                'dormitories.dormitory_name',
                'dormitories.address as dormitory_address',
                'dormitories.latitude',
                'dormitories.longitude',
                'dormitories.is_active as dormitory_is_active',
                'target_categories.name as target_category_name',
                'target_conditions.name as target_condition_name',
                'target_conditions.sort_order as target_condition_sort_order',
            ])
            ->orderByDesc('exchange_products.id')
            ->forPage($page, $pageSize)
            ->get();

        $productIds = $rows->pluck('product_id')->unique()->values();
        $imagesByProductId = DB::table('product_images')
            ->whereIn('product_id', $productIds->all())
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $payload = $rows->map(function ($row) use ($imagesByProductId) {
            $images = $imagesByProductId->get($row->product_id) ?? collect();

            return [
                'exchange_product' => [
                    'id' => (int) $row->exchange_product_id,
                    'exchange_type' => $row->exchange_type,
                    'exchange_status' => $row->exchange_status,
                    'expiration_date' => $row->expiration_date,
                    'target_product_title' => $row->target_product_title,
                    'target_product_category' => $row->target_product_category_id ? [
                        'id' => (int) $row->target_product_category_id,
                        'name' => $row->target_category_name,
                    ] : null,
                    'target_product_condition' => $row->target_product_condition_id ? [
                        'id' => (int) $row->target_product_condition_id,
                        'name' => $row->target_condition_name,
                        'sort_order' => $row->target_condition_sort_order !== null ? (int) $row->target_condition_sort_order : null,
                    ] : null,
                ],
                'product' => [
                    'id' => (int) $row->product_id,
                    'seller_id' => (int) $row->seller_id,
                    'dormitory_id' => (int) $row->dormitory_id,
                    'title' => $row->title,
                    'description' => $row->description,
                    'price' => $row->price !== null ? (float) $row->price : null,
                    'currency' => strtoupper((string) ($row->currency ?? 'CNY')),
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'dormitory' => $row->dormitory_id ? [
                        'id' => (int) $row->dormitory_id,
                        'dormitory_name' => $row->dormitory_name,
                        'address' => $row->dormitory_address,
                        'lat' => $row->latitude !== null ? (float) $row->latitude : null,
                        'lng' => $row->longitude !== null ? (float) $row->longitude : null,
                        'is_active' => $row->dormitory_is_active !== null ? (bool) $row->dormitory_is_active : null,
                    ] : null,
                    'category' => $row->category_id ? [
                        'id' => (int) $row->category_id,
                        'name' => $row->product_category_name,
                        'parent_id' => $row->product_category_parent_id !== null ? (int) $row->product_category_parent_id : null,
                        'icon' => $row->product_category_icon,
                    ] : null,
                    'condition_level' => $row->condition_level_id ? [
                        'id' => (int) $row->condition_level_id,
                        'name' => $row->product_condition_name,
                        'sort_order' => $row->product_condition_sort_order !== null ? (int) $row->product_condition_sort_order : null,
                    ] : null,
                    'images' => $images->values(),
                ],
            ];
        })->values();

        return response()->json([
            'message' => 'Exchange products retrieved successfully',
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'exchange_products' => $payload,
        ], 200);
    }

    public function recommendations(Request $request)
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
                'random_count' => 'nullable|integer|min:0|max:50',
                'lookback_days' => 'nullable|integer|min:1|max:365',
                'seed' => 'nullable|integer',
                'exchange_type' => 'nullable|string|in:exchange_only,exchange_or_purchase',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $params = array_filter($validated, static fn ($value) => $value !== null && $value !== '');
        $authHeader = (string) $request->header('Authorization', '');
        $pythonInternalToken = (string) env('PYTHON_INTERNAL_TOKEN', '');
        $pythonTimeoutSeconds = (int) env('PYTHON_SERVICE_TIMEOUT_SECONDS', 45);
        $pythonConnectTimeoutSeconds = (int) env('PYTHON_SERVICE_CONNECT_TIMEOUT_SECONDS', 5);
        $baseUrl = $this->pythonServiceBaseUrl();

        try {
            $pythonResponse = Http::connectTimeout($pythonConnectTimeoutSeconds)
                ->timeout($pythonTimeoutSeconds)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => $authHeader,
                    'X-Internal-Token' => $pythonInternalToken,
                    'X-User-Id' => (string) $user->id,
                    'X-User-Role' => (string) ($user->role ?? 'user'),
                    'X-User-Dormitory-Id' => $user->dormitory_id !== null ? (string) $user->dormitory_id : '',
                ])
                ->get($baseUrl.'/py/api/user/recommendations/exchange-products', $params);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Recommendation service unavailable.',
                'detail' => [
                    'exception' => class_basename($e),
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        if (! $pythonResponse->successful()) {
            $body = $pythonResponse->json();

            return response()->json([
                'message' => 'Recommendation service unavailable.',
                'detail' => is_array($body) ? $body : ['raw' => (string) $pythonResponse->body()],
                'upstream_status' => $pythonResponse->status(),
            ], 502);
        }

        $payload = $pythonResponse->json();
        if (! is_array($payload)) {
            return response()->json([
                'message' => 'Recommendation service response invalid.',
                'detail' => ['raw' => (string) $pythonResponse->body()],
            ], 502);
        }

        return response()->json($payload, 200);
    }

    public function store(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $dormitoryRule = ($user->dormitory_id ?? null)
            ? 'nullable|integer'
            : 'required|integer|exists:dormitories,id';

        $rules = [
            'product_id' => 'nullable|integer|exists:products,id',
            'exchange_type' => 'required|string|in:exchange_only,exchange_or_purchase',
            'target_product_category_id' => 'nullable|integer|exists:categories,id',
            'target_product_condition_id' => 'nullable|integer|exists:condition_levels,id',
            'target_product_title' => 'nullable|string|max:255',
            'expiration_date' => 'nullable|date',
            'category_id' => 'required_without:product_id|integer|exists:categories,id',
            'condition_level_id' => 'required_without:product_id|integer|exists:condition_levels,id',
            'title' => 'required_without:product_id|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required_without:product_id|numeric|min:0.01',
            'currency' => 'nullable|string|size:3',
            'dormitory_id' => $dormitoryRule,
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
        ];

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $product = null;
        $productId = $validated['product_id'] ?? null;

        if ($productId !== null) {
            $product = Product::query()
                ->whereKey($productId)
                ->where('seller_id', $user->id)
                ->whereNull('deleted_at')
                ->first();

            if (! $product) {
                return response()->json([
                    'message' => 'Product not found or not owned by user.',
                ], 404);
            }

            if ($product->status !== 'available') {
                return response()->json([
                    'message' => 'Product is not available for exchange.',
                ], 409);
            }
        }

        if ($product === null) {
            $dormitoryId = $user->dormitory_id ?? data_get($validated, 'dormitory_id');
            $tagIds = array_values(array_unique(array_map('intval', $validated['tag_ids'] ?? [])));

            $result = DB::transaction(function () use ($validated, $user, $dormitoryId, $tagIds) {
                $product = Product::create([
                    'seller_id' => $user->id,
                    'dormitory_id' => $dormitoryId,
                    'category_id' => $validated['category_id'],
                    'condition_level_id' => $validated['condition_level_id'],
                    'title' => $validated['title'],
                    'description' => data_get($validated, 'description'),
                    'price' => $validated['price'],
                    'currency' => $validated['currency'] ?? 'CNY',
                    'status' => 'available',
                ]);

                foreach ($tagIds as $tagId) {
                    ProductTag::create([
                        'product_id' => $product->id,
                        'tag_id' => $tagId,
                    ]);
                }

                return [
                    'product' => $product,
                ];
            });

            $product = $result['product'];
        }

        $existing = ExchangeProduct::query()->where('product_id', $product->id)->first();
        if ($existing) {
            return response()->json([
                'message' => 'Exchange listing already exists for this product.',
            ], 409);
        }

        $exchangeProduct = ExchangeProduct::create([
            'product_id' => $product->id,
            'exchange_type' => $validated['exchange_type'],
            'target_product_category_id' => data_get($validated, 'target_product_category_id'),
            'target_product_condition_id' => data_get($validated, 'target_product_condition_id'),
            'target_product_title' => data_get($validated, 'target_product_title'),
            'exchange_status' => 'open',
            'expiration_date' => data_get($validated, 'expiration_date'),
        ]);

        $images = DB::table('product_images')
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Exchange listing created successfully',
            'exchange_product' => $exchangeProduct,
            'product' => $product,
            'images' => $images,
        ], 201);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $row = DB::table('exchange_products')
            ->join('products', 'exchange_products.product_id', '=', 'products.id')
            ->leftJoin('categories as product_categories', 'products.category_id', '=', 'product_categories.id')
            ->leftJoin('condition_levels as product_conditions', 'products.condition_level_id', '=', 'product_conditions.id')
            ->leftJoin('dormitories', 'products.dormitory_id', '=', 'dormitories.id')
            ->leftJoin('categories as target_categories', 'exchange_products.target_product_category_id', '=', 'target_categories.id')
            ->leftJoin('condition_levels as target_conditions', 'exchange_products.target_product_condition_id', '=', 'target_conditions.id')
            ->where('exchange_products.id', (int) $id)
            ->select([
                'exchange_products.id as exchange_product_id',
                'exchange_products.exchange_type',
                'exchange_products.exchange_status',
                'exchange_products.expiration_date',
                'exchange_products.target_product_title',
                'exchange_products.target_product_category_id',
                'exchange_products.target_product_condition_id',
                'products.id as product_id',
                'products.seller_id',
                'products.dormitory_id',
                'products.category_id',
                'products.condition_level_id',
                'products.title',
                'products.description',
                'products.price',
                'products.currency',
                'products.status',
                'products.created_at',
                'product_categories.name as product_category_name',
                'product_categories.parent_id as product_category_parent_id',
                'product_categories.logo as product_category_icon',
                'product_conditions.name as product_condition_name',
                'product_conditions.sort_order as product_condition_sort_order',
                'dormitories.dormitory_name',
                'dormitories.address as dormitory_address',
                'dormitories.latitude',
                'dormitories.longitude',
                'dormitories.is_active as dormitory_is_active',
                'target_categories.name as target_category_name',
                'target_conditions.name as target_condition_name',
                'target_conditions.sort_order as target_condition_sort_order',
            ])
            ->first();

        if (! $row) {
            return response()->json([
                'message' => 'Exchange product not found.',
            ], 404);
        }

        $images = DB::table('product_images')
            ->where('product_id', $row->product_id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Exchange product retrieved successfully',
            'exchange_product' => [
                'id' => (int) $row->exchange_product_id,
                'exchange_type' => $row->exchange_type,
                'exchange_status' => $row->exchange_status,
                'expiration_date' => $row->expiration_date,
                'target_product_title' => $row->target_product_title,
                'target_product_category' => $row->target_product_category_id ? [
                    'id' => (int) $row->target_product_category_id,
                    'name' => $row->target_category_name,
                ] : null,
                'target_product_condition' => $row->target_product_condition_id ? [
                    'id' => (int) $row->target_product_condition_id,
                    'name' => $row->target_condition_name,
                    'sort_order' => $row->target_condition_sort_order !== null ? (int) $row->target_condition_sort_order : null,
                ] : null,
            ],
            'product' => [
                'id' => (int) $row->product_id,
                'seller_id' => (int) $row->seller_id,
                'dormitory_id' => (int) $row->dormitory_id,
                'title' => $row->title,
                'description' => $row->description,
                'price' => $row->price !== null ? (float) $row->price : null,
                'currency' => strtoupper((string) ($row->currency ?? 'CNY')),
                'status' => $row->status,
                'created_at' => $row->created_at,
                'dormitory' => $row->dormitory_id ? [
                    'id' => (int) $row->dormitory_id,
                    'dormitory_name' => $row->dormitory_name,
                    'address' => $row->dormitory_address,
                    'lat' => $row->latitude !== null ? (float) $row->latitude : null,
                    'lng' => $row->longitude !== null ? (float) $row->longitude : null,
                    'is_active' => $row->dormitory_is_active !== null ? (bool) $row->dormitory_is_active : null,
                ] : null,
                'category' => $row->category_id ? [
                    'id' => (int) $row->category_id,
                    'name' => $row->product_category_name,
                    'parent_id' => $row->product_category_parent_id !== null ? (int) $row->product_category_parent_id : null,
                    'icon' => $row->product_category_icon,
                ] : null,
                'condition_level' => $row->condition_level_id ? [
                    'id' => (int) $row->condition_level_id,
                    'name' => $row->product_condition_name,
                    'sort_order' => $row->product_condition_sort_order !== null ? (int) $row->product_condition_sort_order : null,
                ] : null,
                'images' => $images,
            ],
        ], 200);
    }
}
