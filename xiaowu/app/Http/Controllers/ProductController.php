<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ConditionLevel;
use App\Models\Dormitory;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductTag;
use App\Models\Tag;
use App\Models\University;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
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

    private function pythonServiceBaseUrl(): string
    {
        return rtrim((string) env('PYTHON_SERVICE_BASE_URL', 'http://127.0.0.1:8001'), '/');
    }

    private function indexProductImagesForVisualSearch(iterable $images, bool $strict = false, array $imageBytesBase64ByImageId = []): array
    {
        if (app()->environment('testing')) {
            return [
                'attempted' => 0,
                'indexed' => 0,
                'failed' => [],
            ];
        }

        $pythonInternalToken = (string) env('PYTHON_INTERNAL_TOKEN', '');
        $timeoutSeconds = (int) env('PYTHON_SERVICE_TIMEOUT_SECONDS', 45);
        $connectTimeoutSeconds = (int) env('PYTHON_SERVICE_CONNECT_TIMEOUT_SECONDS', 5);
        $retryAttempts = max(1, (int) env('VISUAL_SEARCH_INDEX_RETRY_ATTEMPTS', 2));
        $baseUrl = $this->pythonServiceBaseUrl();
        $attempted = 0;
        $indexed = 0;
        $failed = [];

        foreach ($images as $image) {
            if (! $image instanceof ProductImage) {
                continue;
            }
            if (! is_string($image->image_url) || trim($image->image_url) === '') {
                continue;
            }

            $attempted++;
            $indexedThisImage = false;
            $lastError = null;

            for ($attempt = 1; $attempt <= $retryAttempts; $attempt++) {
                try {
                    /** @var \Illuminate\Http\Client\Response $response */
                    $response = Http::connectTimeout($connectTimeoutSeconds)
                        ->timeout($timeoutSeconds)
                        ->acceptJson()
                        ->withHeaders([
                            'X-Internal-Token' => $pythonInternalToken,
                        ])
                        ->post($baseUrl.'/py/api/internal/visual-search/index', [
                            'product_id' => (int) $image->product_id,
                            'product_image_id' => (int) $image->id,
                            'image_url' => $image->image_url,
                            'image_bytes_base64' => $imageBytesBase64ByImageId[(int) $image->id] ?? null,
                        ]);

                    $payload = $response->json();
                    $indexedImageId = is_array($payload) ? (int) data_get($payload, 'indexed.product_image_id', 0) : 0;
                    if ($response->successful() && $indexedImageId === (int) $image->id) {
                        $indexedThisImage = true;
                        $indexed++;
                        break;
                    }

                    $lastError = 'HTTP '.$response->status();
                } catch (\Throwable $e) {
                    $lastError = class_basename($e).': '.$e->getMessage();
                }
            }

            if (! $indexedThisImage) {
                $failed[] = [
                    'product_id' => (int) $image->product_id,
                    'product_image_id' => (int) $image->id,
                    'error' => $lastError ?? 'Unknown indexing failure',
                ];
            }
        }

        if ($strict && count($failed) > 0) {
            $firstFailure = $failed[0];
            throw new \RuntimeException(
                'Visual indexing failed for product_image_id '.$firstFailure['product_image_id'].' ('.$firstFailure['error'].')'
            );
        }

        return [
            'attempted' => $attempted,
            'indexed' => $indexed,
            'failed' => $failed,
        ];
    }

    private function recordProductBehaviorEventBatch(Request $request, User $user, iterable $products, string $eventType): void
    {
        $now = now();
        $rows = [];

        foreach ($products as $product) {
            $rows[] = [
                'user_id' => $user->id,
                'event_type' => $eventType,
                'product_id' => $product->id,
                'category_id' => $product->category_id,
                'seller_id' => $product->seller_id,
                'metadata' => null,
                'occurred_at' => $now,
                'session_id' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (count($rows) === 0) {
            return;
        }

        DB::table('behavioral_events')->insert($rows);
    }

    private function haversineDistanceKm(float $fromLat, float $fromLng, float $toLat, float $toLng): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($toLat - $fromLat);
        $dLng = deg2rad($toLng - $fromLng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($fromLat)) * cos(deg2rad($toLat)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    public function categories(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $categories = Category::query()
            ->select(['id', 'name', 'description', 'logo', 'parent_id'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Categories retrieved successfully',
            'categories' => $categories,
        ], 200);
    }

    public function conditionLevels(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $conditionLevels = ConditionLevel::query()
            ->select(['id', 'name', 'description', 'sort_order', 'level'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Condition levels retrieved successfully',
            'condition_levels' => $conditionLevels,
        ], 200);
    }

    public function tags(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $tags = Tag::query()
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Tags retrieved successfully',
            'tags' => $tags,
        ], 200);
    }

    public function metadataOptions(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $categories = Category::query()
            ->select(['id', 'name', 'description', 'logo', 'parent_id'])
            ->orderBy('id')
            ->get();

        $conditionLevels = ConditionLevel::query()
            ->select(['id', 'name', 'description', 'sort_order', 'level'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $tags = Tag::query()
            ->select(['id', 'name'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Metadata options retrieved successfully',
            'categories' => $categories,
            'condition_levels' => $conditionLevels,
            'tags' => $tags,
        ], 200);
    }

    public function createTag(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:tags,name',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $tag = Tag::create([
            'name' => $validated['name'],
        ]);

        return response()->json([
            'message' => 'Tag created successfully',
            'tag' => $tag,
        ], 201);
    }

    public function dormitories(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $dormitories = Dormitory::query()
            ->select(['id', 'dormitory_name', 'domain', 'latitude', 'longitude', 'address', 'full_capacity', 'is_active', 'university_id'])
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Dormitories retrieved successfully',
            'dormitories' => $dormitories,
        ], 200);
    }

    public function dormitoriesByUserUniversity(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $dormitoryId = $user->dormitory_id;

        if (! $dormitoryId) {
            return response()->json([
                'message' => 'Dormitories retrieved successfully',
                'university_id' => null,
                'dormitories' => [],
            ], 200);
        }

        $userDormitory = Dormitory::query()
            ->select(['id', 'university_id'])
            ->whereKey($dormitoryId)
            ->first();

        if (! $userDormitory || ! $userDormitory->university_id) {
            return response()->json([
                'message' => 'Dormitory not found for the user.',
            ], 404);
        }

        $dormitories = Dormitory::query()
            ->select(['id', 'dormitory_name', 'domain', 'latitude', 'longitude', 'address', 'full_capacity', 'is_active', 'university_id'])
            ->where('university_id', $userDormitory->university_id)
            ->orderBy('id')
            ->get();

        return response()->json([
            'message' => 'Dormitories retrieved successfully',
            'university_id' => $userDormitory->university_id,
            'dormitories' => $dormitories,
        ], 200);
    }

    public function myProducts(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $products = Product::query()
            ->with(['dormitory'])
            ->where('seller_id', $user->id)
            ->orderByDesc('id')
            ->get();

        $productIds = $products->pluck('id')->all();

        $imagesByProductId = [];
        $tagsByProductId = [];

        if (count($productIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();

            $tagRows = DB::table('product_tags')
                ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
                ->whereIn('product_tags.product_id', $productIds)
                ->select([
                    'product_tags.product_id',
                    'tags.id',
                    'tags.name',
                ])
                ->orderBy('tags.id')
                ->get();

            foreach ($tagRows as $row) {
                $tagsByProductId[$row->product_id][] = [
                    'id' => $row->id,
                    'name' => $row->name,
                ];
            }
        }

        $payload = $products
            ->map(function (Product $product) use ($imagesByProductId, $tagsByProductId) {
                $data = $product->toArray();
                $images = $imagesByProductId[$product->id] ?? collect();
                $data['images'] = $images->values();
                $data['tags'] = $tagsByProductId[$product->id] ?? [];

                return $data;
            })
            ->values();

        return response()->json([
            'message' => 'User products retrieved successfully',
            'products' => $payload,
        ], 200);
    }

    public function myProductCards(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = validator($request->query(), [
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:50',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 12);

        $paginator = Product::query()
            ->select([
                'id',
                'seller_id',
                'dormitory_id',
                'category_id',
                'condition_level_id',
                'title',
                'price',
                'currency',
                'status',
                'created_at',
            ])
            ->with(['dormitory:id,dormitory_name,university_id'])
            ->where('seller_id', $user->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $products = $paginator->getCollection();
        $productIds = $products->pluck('id')->all();

        $imagesByProductId = [];

        if (count($productIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();
        }

        $categoryIds = $products->pluck('category_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $conditionLevelIds = $products->pluck('condition_level_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $categoriesById = collect();
        $conditionLevelsById = collect();

        if (count($categoryIds) > 0) {
            $categoriesById = Category::query()
                ->select(['id', 'name', 'description', 'parent_id'])
                ->whereIn('id', $categoryIds)
                ->get()
                ->keyBy('id');
        }

        if (count($conditionLevelIds) > 0) {
            $conditionLevelsById = ConditionLevel::query()
                ->select(['id', 'name', 'description', 'sort_order', 'level'])
                ->whereIn('id', $conditionLevelIds)
                ->get()
                ->keyBy('id');
        }

        $payload = $products
            ->map(function (Product $product) use ($imagesByProductId, $categoriesById, $conditionLevelsById) {
                $images = $imagesByProductId[$product->id] ?? collect();
                $primaryImage = $images->first();
                $imageThumbnailUrl = null;

                if ($primaryImage) {
                    $imageThumbnailUrl = $primaryImage->image_thumbnail_url ?? $primaryImage->image_url;
                }

                $category = $categoriesById->get($product->category_id);
                $conditionLevel = $conditionLevelsById->get($product->condition_level_id);
                $dormitory = $product->dormitory;

                return [
                    'id' => $product->id,
                    'title' => $product->title,
                    'price' => $product->price,
                    'currency' => strtoupper((string) ($product->currency ?? 'CNY')),
                    'status' => $product->status,
                    'created_at' => $product->created_at,
                    'image_thumbnail_url' => $imageThumbnailUrl,
                    'dormitory' => $dormitory ? [
                        'id' => $dormitory->id,
                        'dormitory_name' => $dormitory->dormitory_name,
                        'university_id' => $dormitory->university_id,
                    ] : null,
                    'category' => $category ? $category->toArray() : null,
                    'condition_level' => $conditionLevel ? $conditionLevel->toArray() : null,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'User product cards retrieved successfully',
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'products' => $payload,
        ], 200);
    }

    public function visualSearch(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'image' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:8192',
                'top_k' => 'nullable|integer|min:1|max:50',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $image = $request->file('image');
        if (! $image) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'image' => ['The image field is required.'],
                ],
            ], 422);
        }

        $topK = (int) ($validated['top_k'] ?? 12);
        $authHeader = (string) $request->header('Authorization', '');
        $pythonInternalToken = (string) env('PYTHON_INTERNAL_TOKEN', '');
        $pythonTimeoutSeconds = (int) env('PYTHON_SERVICE_TIMEOUT_SECONDS', 45);
        $pythonConnectTimeoutSeconds = (int) env('PYTHON_SERVICE_CONNECT_TIMEOUT_SECONDS', 5);
        $baseUrl = $this->pythonServiceBaseUrl();

        try {
            /** @var \Illuminate\Http\Client\Response $pythonResponse */
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
                ->attach(
                    'image',
                    $image->get(),
                    $image->getClientOriginalName() ?: 'query-image.jpg'
                )
                ->post($baseUrl.'/py/api/user/search/visual', [
                    'top_k' => $topK,
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'AI visual search service unavailable.',
                'detail' => [
                    'exception' => class_basename($e),
                    'message' => $e->getMessage(),
                ],
            ], 502);
        }

        if (! $pythonResponse->successful()) {
            $body = $pythonResponse->json();

            return response()->json([
                'message' => 'AI visual search service unavailable.',
                'detail' => is_array($body) ? $body : ['raw' => (string) $pythonResponse->body()],
                'upstream_status' => $pythonResponse->status(),
            ], 502);
        }

        $searchPayload = $pythonResponse->json();
        $productIds = is_array($searchPayload['product_ids'] ?? null) ? $searchPayload['product_ids'] : [];
        $matches = is_array($searchPayload['matches'] ?? null) ? $searchPayload['matches'] : [];
        $scoreByProductId = [];

        foreach ($matches as $match) {
            if (! is_array($match)) {
                continue;
            }
            $pid = (int) ($match['product_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $scoreByProductId[$pid] = (float) ($match['score'] ?? 0.0);
        }

        $orderedIds = collect($productIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $products = collect();
        if (count($orderedIds) > 0) {
            $orderMap = array_flip($orderedIds);
            $products = Product::query()
                ->with([
                    'dormitory:id,dormitory_name,university_id',
                ])
                ->whereIn('id', $orderedIds)
                ->where('status', 'available')
                ->whereNull('deleted_at')
                ->get([
                    'id',
                    'seller_id',
                    'dormitory_id',
                    'category_id',
                    'condition_level_id',
                    'title',
                    'price',
                    'currency',
                    'status',
                    'created_at',
                ]);

            $products = $products
                ->sortBy(static function (Product $product) use ($orderMap) {
                    return $orderMap[(int) $product->id] ?? PHP_INT_MAX;
                })
                ->values();
        }

        $productIdsInDb = $products->pluck('id')->map(static fn ($id) => (int) $id)->all();
        $imagesByProductId = [];
        $tagsByProductId = [];
        $categoriesById = collect();
        $conditionLevelsById = collect();

        if (count($productIdsInDb) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIdsInDb)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();

            $tagRows = DB::table('product_tags')
                ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
                ->whereIn('product_tags.product_id', $productIdsInDb)
                ->select([
                    'product_tags.product_id',
                    'tags.id',
                    'tags.name',
                ])
                ->orderBy('tags.id')
                ->get();

            foreach ($tagRows as $row) {
                $tagsByProductId[$row->product_id][] = [
                    'id' => $row->id,
                    'name' => $row->name,
                ];
            }

            $categoryIds = $products->pluck('category_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            $conditionLevelIds = $products->pluck('condition_level_id')
                ->filter()
                ->unique()
                ->values()
                ->all();

            if (count($categoryIds) > 0) {
                $categoriesById = Category::query()
                    ->select(['id', 'name', 'description', 'parent_id'])
                    ->whereIn('id', $categoryIds)
                    ->get()
                    ->keyBy('id');
            }

            if (count($conditionLevelIds) > 0) {
                $conditionLevelsById = ConditionLevel::query()
                    ->select(['id', 'name', 'description', 'sort_order', 'level'])
                    ->whereIn('id', $conditionLevelIds)
                    ->get()
                    ->keyBy('id');
            }
        }

        $payloadProducts = $products
            ->map(function (Product $product) use ($imagesByProductId, $tagsByProductId, $categoriesById, $conditionLevelsById, $scoreByProductId) {
                $images = collect($imagesByProductId[$product->id] ?? [])->values();
                $primaryImage = $images->first();
                $data = [
                    'id' => (int) $product->id,
                    'seller_id' => (int) $product->seller_id,
                    'dormitory_id' => $product->dormitory_id !== null ? (int) $product->dormitory_id : null,
                    'category_id' => $product->category_id !== null ? (int) $product->category_id : null,
                    'condition_level_id' => $product->condition_level_id !== null ? (int) $product->condition_level_id : null,
                    'title' => $product->title,
                    'price' => $product->price !== null ? (float) $product->price : null,
                    'currency' => strtoupper((string) ($product->currency ?? 'CNY')),
                    'status' => $product->status,
                    'created_at' => optional($product->created_at)->toISOString(),
                    'dormitory' => $product->dormitory ? [
                        'id' => $product->dormitory->id,
                        'dormitory_name' => $product->dormitory->dormitory_name,
                        'university_id' => $product->dormitory->university_id,
                    ] : null,
                    'category' => $categoriesById->get($product->category_id),
                    'condition_level' => $conditionLevelsById->get($product->condition_level_id),
                    'image_thumbnail_url' => $primaryImage ? $primaryImage->image_thumbnail_url : null,
                    'images' => $images,
                    'tags' => $tagsByProductId[$product->id] ?? [],
                    'visual_similarity_score' => $scoreByProductId[(int) $product->id] ?? null,
                ];

                return $data;
            })
            ->values();

        return response()->json([
            'message' => 'Visual search completed successfully',
            'query' => [
                'top_k' => $topK,
                'model_name' => $searchPayload['model_name'] ?? null,
                'embedding_dim' => $searchPayload['embedding_dim'] ?? null,
            ],
            'count' => $payloadProducts->count(),
            'products' => $payloadProducts,
        ], 200);
    }

    public function getProduct(Request $request, string $product_id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $productId = trim($product_id);

        try {
            $validated = validator(['product_id' => $productId], [
                'product_id' => 'required|integer|min:1',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $product = Product::query()
            ->with(['dormitory'])
            ->whereKey((int) $validated['product_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $category = Category::query()
            ->select(['id', 'name', 'parent_id'])
            ->whereKey($product->category_id)
            ->first();

        $conditionLevel = ConditionLevel::query()
            ->select(['id', 'name', 'description', 'sort_order', 'level'])
            ->whereKey($product->condition_level_id)
            ->first();

        $seller = User::query()
            ->select(['id', 'full_name', 'username', 'profile_picture'])
            ->whereKey($product->seller_id)
            ->first();

        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->values();

        $tags = DB::table('product_tags')
            ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
            ->where('product_tags.product_id', $product->id)
            ->select([
                'tags.id',
                'tags.name',
            ])
            ->orderBy('tags.id')
            ->get()
            ->values();

        DB::table('behavioral_events')->insert([
            'user_id' => $user->id,
            'event_type' => 'click',
            'product_id' => $product->id,
            'category_id' => $product->category_id,
            'seller_id' => $product->seller_id,
            'metadata' => null,
            'occurred_at' => now(),
            'session_id' => null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $product->toArray();
        $payload['images'] = $images;
        $payload['tags'] = $tags;
        $payload['category'] = $category;
        $payload['condition_level'] = $conditionLevel;
        $payload['seller'] = $seller;

        return response()->json([
            'message' => 'Product retrieved successfully',
            'product' => $payload,
        ], 200);
    }

    public function sellerProfile(Request $request, string $seller_id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $sellerId = trim($seller_id);

        try {
            $validated = validator([
                'seller_id' => $sellerId,
                'page' => $request->input('page'),
                'page_size' => $request->input('page_size'),
            ], [
                'seller_id' => 'required|integer|min:1',
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:50',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $seller = User::query()
            ->select([
                'id',
                'full_name',
                'profile_picture',
                'email_verified',
                'created_at',
                'dormitory_id',
                'bio',
                'language',
                'timezone',
                'last_login_at',
                'role',
            ])
            ->whereKey((int) $validated['seller_id'])
            ->whereNull('deleted_at')
            ->first();

        if (! $seller || ($seller->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Seller not found.',
            ], 404);
        }

        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['page_size'] ?? 10);

        $dormitory = null;
        $university = null;

        if ($seller->dormitory_id) {
            $dormitory = Dormitory::query()
                ->select(['id', 'dormitory_name', 'latitude', 'longitude', 'university_id'])
                ->whereKey($seller->dormitory_id)
                ->first();

            if ($dormitory?->university_id) {
                $university = University::query()
                    ->select(['id', 'name', 'address'])
                    ->whereKey($dormitory->university_id)
                    ->first();
            }
        }

        $listedCount = Product::query()
            ->where('seller_id', $seller->id)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'sold')
            ->count();

        $salesCount = Product::query()
            ->where('seller_id', $seller->id)
            ->whereNull('deleted_at')
            ->where('status', 'sold')
            ->count();

        $conditionStats = Product::query()
            ->join('condition_levels', 'products.condition_level_id', '=', 'condition_levels.id')
            ->where('products.seller_id', $seller->id)
            ->whereNull('products.deleted_at')
            ->select([
                DB::raw('COUNT(products.id) as product_count'),
                DB::raw('SUM(condition_levels.level) as level_sum'),
            ])
            ->first();

        $averageConditionLevel = null;
        $productCount = (int) ($conditionStats->product_count ?? 0);
        $levelSum = $conditionStats->level_sum;
        if ($productCount > 0 && $levelSum !== null) {
            $averageConditionLevel = ($levelSum / $productCount) / 2;
        }

        $paginator = Product::query()
            ->leftJoin('condition_levels', 'products.condition_level_id', '=', 'condition_levels.id')
            ->leftJoin('dormitories', 'products.dormitory_id', '=', 'dormitories.id')
            ->where('products.seller_id', $seller->id)
            ->whereNull('products.deleted_at')
            ->select([
                'products.id',
                'products.title',
                'products.price',
                'products.currency',
                'products.created_at',
                'products.status',
                'condition_levels.name as condition_name',
                'dormitories.dormitory_name as dormitory_name',
                'dormitories.latitude as dormitory_latitude',
                'dormitories.longitude as dormitory_longitude',
            ])
            ->orderByDesc('products.id')
            ->paginate($pageSize, ['*'], 'page', $page);

        $productRows = $paginator->getCollection();
        $productIds = $productRows->pluck('id')->all();
        $imagesByProductId = [];

        if (count($productIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();
        }

        $products = $productRows
            ->map(function ($row) use ($imagesByProductId) {
                $images = $imagesByProductId[$row->id] ?? collect();
                $primaryImage = $images->first();
                $imageThumbnailUrl = $primaryImage?->image_thumbnail_url ?? $primaryImage?->image_url;

                return [
                    'id' => $row->id,
                    'name' => $row->title,
                    'price' => $row->price !== null ? (float) $row->price : null,
                    'currency' => strtoupper((string) ($row->currency ?? 'CNY')),
                    'condition_name' => $row->condition_name,
                    'image_thumbnail_url' => $imageThumbnailUrl,
                    'location' => [
                        'dormitory_name' => $row->dormitory_name,
                        'latitude' => $row->dormitory_latitude,
                        'longitude' => $row->dormitory_longitude,
                    ],
                ];
            })
            ->values();

        $lastLogin = $seller->last_login_at;
        $lastLoginFormatted = null;
        if ($lastLogin) {
            $lastLoginFormatted = $lastLogin instanceof \DateTimeInterface
                ? $lastLogin->format('Y-m-d H:i:s')
                : Carbon::parse($lastLogin)->format('Y-m-d H:i:s');
        }

        return response()->json([
            'message' => 'Seller profile retrieved successfully',
            'seller' => [
                'id' => $seller->id,
                'name' => $seller->full_name,
                'profile_picture' => $seller->profile_picture,
                'email_verified' => (bool) $seller->email_verified,
                'member_since' => $seller->created_at ? $seller->created_at->format('Y.m.d') : null,
                'dorm_name' => $dormitory?->dormitory_name,
                'uni_name' => $university?->name,
                'uni_address' => $university?->address,
                'bio' => $seller->bio,
                'language' => $seller->language,
                'timezone' => $seller->timezone,
                'last_login' => $lastLoginFormatted,
                'listed_products_count' => $listedCount,
                'sales_count' => $salesCount,
                'average_condition_level' => $averageConditionLevel,
            ],
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'total' => $paginator->total(),
            'total_pages' => $paginator->lastPage(),
            'products' => $products,
        ], 200);
    }

    public function getMyProductForEdit(Request $request, string $product_id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $productId = trim($product_id);

        try {
            $validated = validator(['product_id' => $productId], [
                'product_id' => 'required|integer|min:1',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $product = Product::query()
            ->with(['dormitory'])
            ->whereKey((int) $validated['product_id'])
            ->where('seller_id', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $images = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->values();

        $tags = DB::table('product_tags')
            ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
            ->where('product_tags.product_id', $product->id)
            ->select([
                'tags.id',
                'tags.name',
            ])
            ->orderBy('tags.id')
            ->get()
            ->values();

        $tagIds = $tags->pluck('id')->values();

        $payload = $product->toArray();
        $payload['images'] = $images;
        $payload['tags'] = $tags;
        $payload['tag_ids'] = $tagIds;

        return response()->json([
            'message' => 'User product retrieved successfully',
            'product' => $payload,
        ], 200);
    }

    public function markMyProductAsSold(Request $request, string $product_id)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $productId = trim($product_id);

        try {
            $validated = validator(['product_id' => $productId], [
                'product_id' => 'required|integer|min:1',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $product = Product::query()
            ->whereKey((int) $validated['product_id'])
            ->where('seller_id', $user->id)
            ->whereNull('deleted_at')
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if ($product->status !== 'sold') {
            $product->status = 'sold';
            $product->save();
        }

        return response()->json([
            'message' => 'Product marked as sold successfully',
            'product' => [
                'id' => $product->id,
                'status' => $product->status,
            ],
        ], 200);
    }

    public function listProductsByTagName(Request $request, string $tag_name)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $tagName = trim($tag_name);

        try {
            $validated = validator(['tag_name' => $tagName], [
                'tag_name' => 'required|string|max:255',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $tag = Tag::query()
            ->where('name', $validated['tag_name'])
            ->first();

        if (! $tag) {
            return response()->json([
                'message' => 'Tag not found.',
            ], 404);
        }

        $productIds = ProductTag::query()
            ->where('tag_id', $tag->id)
            ->select('product_id')
            ->distinct()
            ->pluck('product_id')
            ->all();

        $products = collect();

        if (count($productIds) > 0) {
            $products = Product::query()
                ->with(['dormitory'])
                ->whereIn('id', $productIds)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->get();
        }

        $this->recordProductBehaviorEventBatch($request, $user, $products, 'view');

        $matchedProductIds = $products->pluck('id')->all();

        $imagesByProductId = [];
        $tagsByProductId = [];

        if (count($matchedProductIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $matchedProductIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();

            $tagRows = DB::table('product_tags')
                ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
                ->whereIn('product_tags.product_id', $matchedProductIds)
                ->select([
                    'product_tags.product_id',
                    'tags.id',
                    'tags.name',
                ])
                ->orderBy('tags.id')
                ->get();

            foreach ($tagRows as $row) {
                $tagsByProductId[$row->product_id][] = [
                    'id' => $row->id,
                    'name' => $row->name,
                ];
            }
        }

        $payload = $products
            ->map(function (Product $product) use ($imagesByProductId, $tagsByProductId) {
                $data = $product->toArray();
                $images = $imagesByProductId[$product->id] ?? collect();
                $data['images'] = $images->values();
                $data['tags'] = $tagsByProductId[$product->id] ?? [];

                return $data;
            })
            ->values();

        return response()->json([
            'message' => 'Products retrieved successfully',
            'tag' => [
                'id' => $tag->id,
                'name' => $tag->name,
            ],
            'products' => $payload,
        ], 200);
    }

    public function listProductsByCategoryName(Request $request, string $category_name)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $categoryName = trim($category_name);

        try {
            $validated = validator(['category_name' => $categoryName], [
                'category_name' => 'required|string|max:255',
            ])->validate();
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $category = Category::query()
            ->where('name', $validated['category_name'])
            ->first();

        if (! $category) {
            return response()->json([
                'message' => 'Category not found.',
            ], 404);
        }

        $products = Product::query()
            ->with(['dormitory'])
            ->where('category_id', $category->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->get();

        $this->recordProductBehaviorEventBatch($request, $user, $products, 'view');

        $productIds = $products->pluck('id')->all();

        $imagesByProductId = [];
        $tagsByProductId = [];

        if (count($productIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();

            $tagRows = DB::table('product_tags')
                ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
                ->whereIn('product_tags.product_id', $productIds)
                ->select([
                    'product_tags.product_id',
                    'tags.id',
                    'tags.name',
                ])
                ->orderBy('tags.id')
                ->get();

            foreach ($tagRows as $row) {
                $tagsByProductId[$row->product_id][] = [
                    'id' => $row->id,
                    'name' => $row->name,
                ];
            }
        }

        $payload = $products
            ->map(function (Product $product) use ($imagesByProductId, $tagsByProductId) {
                $data = $product->toArray();
                $images = $imagesByProductId[$product->id] ?? collect();
                $data['images'] = $images->values();
                $data['tags'] = $tagsByProductId[$product->id] ?? [];

                return $data;
            })
            ->values();

        return response()->json([
            'message' => 'Products retrieved successfully',
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'description' => $category->description,
                'logo' => $category->logo,
                'parent_id' => $category->parent_id,
            ],
            'products' => $payload,
        ], 200);
    }

    public function nearby(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'lat' => 'required|numeric|between:-90,90',
                'lng' => 'required|numeric|between:-180,180',
                'distance_km' => 'nullable|numeric|min:0.1|max:500',
                'category_id' => 'nullable|integer|exists:categories,id',
                'condition_level_id' => 'nullable|integer|exists:condition_levels,id',
                'q' => 'nullable|string|max:255',
                'location_q' => 'nullable|string|max:255',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $centerLat = (float) $validated['lat'];
        $centerLng = (float) $validated['lng'];
        $distanceKm = (float) ($validated['distance_km'] ?? 10);
        $latRadius = $distanceKm / 111.0;
        $cosLat = cos(deg2rad($centerLat));
        $lngRadius = abs($cosLat) < 0.000001 ? 180.0 : $distanceKm / (111.0 * abs($cosLat));

        $query = Product::query()
            ->join('dormitories', 'products.dormitory_id', '=', 'dormitories.id')
            ->where('products.status', 'available')
            ->whereNull('products.deleted_at')
            ->whereNotNull('dormitories.latitude')
            ->whereNotNull('dormitories.longitude')
            ->whereBetween('dormitories.latitude', [$centerLat - $latRadius, $centerLat + $latRadius])
            ->whereBetween('dormitories.longitude', [$centerLng - $lngRadius, $centerLng + $lngRadius])
            ->select([
                'products.id',
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
                'dormitories.latitude as dormitory_latitude',
                'dormitories.longitude as dormitory_longitude',
            ])
            ->orderByDesc('products.id');

        if (! empty($validated['category_id'])) {
            $query->where('products.category_id', (int) $validated['category_id']);
        }

        if (! empty($validated['condition_level_id'])) {
            $query->where('products.condition_level_id', (int) $validated['condition_level_id']);
        }

        if (! empty($validated['q'])) {
            $q = trim((string) $validated['q']);
            $query->where(function ($inner) use ($q) {
                $inner
                    ->where('products.title', 'like', '%'.$q.'%')
                    ->orWhere('products.description', 'like', '%'.$q.'%');
            });
        }

        if (! empty($validated['location_q'])) {
            $locationQ = trim((string) $validated['location_q']);
            $query->where(function ($inner) use ($locationQ) {
                $inner
                    ->where('dormitories.dormitory_name', 'like', '%'.$locationQ.'%')
                    ->orWhere('dormitories.domain', 'like', '%'.$locationQ.'%')
                    ->orWhere('dormitories.address', 'like', '%'.$locationQ.'%');
            });
        }

        $rows = $query->get();

        $nearbyRows = $rows
            ->map(function ($row) use ($centerLat, $centerLng) {
                $distance = $this->haversineDistanceKm(
                    $centerLat,
                    $centerLng,
                    (float) $row->dormitory_latitude,
                    (float) $row->dormitory_longitude
                );
                $row->distance_km = round($distance, 3);

                return $row;
            })
            ->filter(function ($row) use ($distanceKm) {
                return (float) $row->distance_km <= $distanceKm;
            })
            ->sortBy([
                ['distance_km', 'asc'],
                ['id', 'desc'],
            ])
            ->values();

        $productIds = $nearbyRows->pluck('id')->all();
        $sellerIds = $nearbyRows->pluck('seller_id')->filter()->unique()->values()->all();
        $dormitoryIds = $nearbyRows->pluck('dormitory_id')->filter()->unique()->values()->all();
        $categoryIds = $nearbyRows->pluck('category_id')->filter()->unique()->values()->all();
        $conditionLevelIds = $nearbyRows->pluck('condition_level_id')->filter()->unique()->values()->all();

        $sellersById = collect();
        if (count($sellerIds) > 0) {
            $sellersById = User::query()
                ->select(['id', 'full_name', 'username', 'email', 'profile_picture'])
                ->whereIn('id', $sellerIds)
                ->get()
                ->keyBy('id');
        }

        $dormitoriesById = collect();
        if (count($dormitoryIds) > 0) {
            $dormitoriesById = Dormitory::query()
                ->select(['id', 'dormitory_name', 'domain', 'address', 'latitude', 'longitude', 'is_active', 'university_id'])
                ->whereIn('id', $dormitoryIds)
                ->get()
                ->keyBy('id');
        }

        $categoriesById = collect();
        if (count($categoryIds) > 0) {
            $categoriesById = Category::query()
                ->select(['id', 'name', 'parent_id', 'logo'])
                ->whereIn('id', $categoryIds)
                ->get()
                ->keyBy('id');
        }

        $conditionLevelsById = collect();
        if (count($conditionLevelIds) > 0) {
            $conditionLevelsById = ConditionLevel::query()
                ->select(['id', 'name', 'description', 'sort_order'])
                ->whereIn('id', $conditionLevelIds)
                ->get()
                ->keyBy('id');
        }

        $imagesByProductId = [];
        if (count($productIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();
        }

        $tagsByProductId = [];
        if (count($productIds) > 0) {
            $tagRows = DB::table('product_tags')
                ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
                ->whereIn('product_tags.product_id', $productIds)
                ->select([
                    'product_tags.product_id',
                    'tags.id',
                    'tags.name',
                ])
                ->orderBy('tags.id')
                ->get();

            foreach ($tagRows as $row) {
                $tagsByProductId[$row->product_id][] = [
                    'id' => (int) $row->id,
                    'name' => $row->name,
                ];
            }
        }

        $productsPayload = $nearbyRows
            ->map(function ($row) use ($sellersById, $dormitoriesById, $categoriesById, $conditionLevelsById, $imagesByProductId, $tagsByProductId) {
                $seller = $sellersById->get($row->seller_id);
                $dormitory = $dormitoriesById->get($row->dormitory_id);
                $category = $categoriesById->get($row->category_id);
                $conditionLevel = $conditionLevelsById->get($row->condition_level_id);
                $images = ($imagesByProductId[$row->id] ?? collect())
                    ->map(function ($image) {
                        return [
                            'id' => $image->id,
                            'product_id' => $image->product_id,
                            'image_url' => $image->image_url,
                            'image_thumbnail_url' => $image->image_thumbnail_url,
                            'is_primary' => (bool) $image->is_primary,
                        ];
                    })
                    ->values();

                return [
                    'id' => (int) $row->id,
                    'seller_id' => (int) $row->seller_id,
                    'seller' => $seller ? [
                        'id' => (int) $seller->id,
                        'full_name' => $seller->full_name,
                        'username' => $seller->username,
                        'email' => $seller->email,
                        'profile_picture' => $seller->profile_picture,
                    ] : null,
                    'dormitory_id' => (int) $row->dormitory_id,
                    'dormitory' => $dormitory ? [
                        'id' => (int) $dormitory->id,
                        'dormitory_name' => $dormitory->dormitory_name,
                        'domain' => $dormitory->domain,
                        'location' => $dormitory->address,
                        'lat' => $dormitory->latitude !== null ? (float) $dormitory->latitude : null,
                        'lng' => $dormitory->longitude !== null ? (float) $dormitory->longitude : null,
                        'is_active' => (bool) $dormitory->is_active,
                        'university_id' => $dormitory->university_id !== null ? (int) $dormitory->university_id : null,
                    ] : null,
                    'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
                    'category' => $category ? [
                        'id' => (int) $category->id,
                        'name' => $category->name,
                        'parent_id' => $category->parent_id !== null ? (int) $category->parent_id : null,
                        'icon' => $category->logo,
                    ] : null,
                    'condition_level_id' => $row->condition_level_id !== null ? (int) $row->condition_level_id : null,
                    'condition_level' => $conditionLevel ? [
                        'id' => (int) $conditionLevel->id,
                        'name' => $conditionLevel->name,
                        'description' => $conditionLevel->description,
                        'sort_order' => (int) $conditionLevel->sort_order,
                    ] : null,
                    'title' => $row->title,
                    'description' => $row->description,
                    'price' => $row->price !== null ? (float) $row->price : null,
                    'currency' => strtoupper((string) ($row->currency ?? 'CNY')),
                    'status' => $row->status,
                    'is_promoted' => false,
                    'created_at' => $row->created_at,
                    'images' => $images,
                    'tags' => $tagsByProductId[$row->id] ?? [],
                    'distance_km' => (float) $row->distance_km,
                ];
            })
            ->values();

        $metaCategories = $categoriesById
            ->values()
            ->map(function ($category) {
                return [
                    'id' => (int) $category->id,
                    'name' => $category->name,
                    'icon' => $category->logo,
                ];
            })
            ->values();

        $metaConditionLevels = $conditionLevelsById
            ->values()
            ->map(function ($conditionLevel) {
                return [
                    'id' => (int) $conditionLevel->id,
                    'name' => $conditionLevel->name,
                    'description' => $conditionLevel->description,
                    'sort_order' => (int) $conditionLevel->sort_order,
                ];
            })
            ->values();

        return response()->json([
            'message' => 'ok',
            'center' => [
                'lat' => $centerLat,
                'lng' => $centerLng,
            ],
            'distance_km' => $distanceKm,
            'products' => $productsPayload,
            'meta' => [
                'categories' => $metaCategories,
                'condition_levels' => $metaConditionLevels,
            ],
        ], 200);
    }

    public function addProductToFavorites(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        try {
            $validated = $request->validate([
                'product_id' => 'required|integer',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $productId = (int) $validated['product_id'];
        $product = Product::query()
            ->whereKey($productId)
            ->whereNull('deleted_at')
            ->first();

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        $favorite = Favorite::firstOrCreate([
            'user_id' => $user->id,
            'product_id' => $productId,
        ]);

        if ($favorite->wasRecentlyCreated) {
            DB::table('behavioral_events')->insert([
                'user_id' => $user->id,
                'event_type' => 'favorite',
                'product_id' => $product->id,
                'category_id' => $product->category_id,
                'seller_id' => $product->seller_id,
                'metadata' => null,
                'occurred_at' => now(),
                'session_id' => null,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $status = $favorite->wasRecentlyCreated ? 201 : 200;
        $message = $favorite->wasRecentlyCreated
            ? 'Product added to favorites successfully'
            : 'Product is already in favorites';

        return response()->json([
            'message' => $message,
            'favorite' => $favorite,
        ], $status);
    }

    public function myFavorites(Request $request)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $favoriteProductIds = Favorite::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->pluck('product_id')
            ->unique()
            ->values()
            ->all();

        $products = collect();

        if (count($favoriteProductIds) > 0) {
            $products = Product::query()
                ->with(['dormitory'])
                ->whereIn('id', $favoriteProductIds)
                ->whereNull('deleted_at')
                ->orderByDesc('id')
                ->get();
        }

        $productIds = $products->pluck('id')->all();

        $imagesByProductId = [];
        $tagsByProductId = [];

        if (count($productIds) > 0) {
            $imagesByProductId = ProductImage::query()
                ->whereIn('product_id', $productIds)
                ->orderByDesc('is_primary')
                ->orderBy('id')
                ->get()
                ->groupBy('product_id')
                ->all();

            $tagRows = DB::table('product_tags')
                ->join('tags', 'product_tags.tag_id', '=', 'tags.id')
                ->whereIn('product_tags.product_id', $productIds)
                ->select([
                    'product_tags.product_id',
                    'tags.id',
                    'tags.name',
                ])
                ->orderBy('tags.id')
                ->get();

            foreach ($tagRows as $row) {
                $tagsByProductId[$row->product_id][] = [
                    'id' => $row->id,
                    'name' => $row->name,
                ];
            }
        }

        $payload = $products
            ->map(function (Product $product) use ($imagesByProductId, $tagsByProductId) {
                $data = $product->toArray();
                $images = $imagesByProductId[$product->id] ?? collect();
                $data['images'] = $images->values();
                $data['tags'] = $tagsByProductId[$product->id] ?? [];

                return $data;
            })
            ->values();

        return response()->json([
            'message' => 'Favorite products retrieved successfully',
            'products' => $payload,
        ], 200);
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

        $uploadedImages = $request->file('images');
        $uploadedThumbnailImages = $request->file('thumbnail_images');
        $imageUrls = $request->input('image_urls');
        $imageThumbnailUrls = $request->input('image_thumbnail_urls');
        $imageRules = [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:5120',
        ];

        $rules = [
            'category_id' => 'required|integer|exists:categories,id',
            'condition_level_id' => 'required|integer|exists:condition_levels,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0.01',
            'dormitory_id' => $dormitoryRule,
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'primary_image_index' => 'nullable|integer|min:0',
            'image_urls' => 'nullable|array|max:6',
            'image_urls.*' => ['string', 'max:2048', 'regex:/^(https?:\/\/|\/)/'],
            'image_thumbnail_urls' => 'nullable|array|max:6',
            'image_thumbnail_urls.*' => ['nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\/)/'],
        ];

        if (is_array($uploadedImages)) {
            $rules['images'] = 'nullable|array|max:6';
            $rules['images.*'] = implode('|', $imageRules);
        } elseif ($uploadedImages) {
            $rules['images'] = implode('|', $imageRules);
        } else {
            $rules['images'] = 'nullable';
        }

        if (is_array($uploadedThumbnailImages)) {
            $rules['thumbnail_images'] = 'nullable|array|max:6';
            $rules['thumbnail_images.*'] = implode('|', $imageRules);
        } elseif ($uploadedThumbnailImages) {
            $rules['thumbnail_images'] = implode('|', $imageRules);
        } else {
            $rules['thumbnail_images'] = 'nullable';
        }

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $uploadedImages = $request->file('images');
        $uploadedThumbnailImages = $request->file('thumbnail_images');
        $imageUrls = $request->input('image_urls');
        $imageThumbnailUrls = $request->input('image_thumbnail_urls');

        $hasFileImages = $uploadedImages !== null;
        $hasUrlImages = is_array($imageUrls) && count($imageUrls) > 0;

        if ($hasFileImages && $hasUrlImages) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'image_urls' => ['The image_urls field cannot be used when uploading images files.'],
                ],
            ], 422);
        }

        if ($hasUrlImages && $uploadedThumbnailImages !== null) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'thumbnail_images' => ['The thumbnail_images field cannot be used with image_urls.'],
                ],
            ], 422);
        }

        if ($hasFileImages && is_array($imageThumbnailUrls) && count($imageThumbnailUrls) > 0) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'image_thumbnail_urls' => ['The image_thumbnail_urls field cannot be used when uploading images files.'],
                ],
            ], 422);
        }

        $dormitoryId = $user->dormitory_id ?? data_get($validated, 'dormitory_id');

        $primaryIndex = (int) ($validated['primary_image_index'] ?? 0);

        $imagesToStore = [];
        $thumbnailsToStore = [];
        $imageUrlsToStore = [];
        $imageThumbnailUrlsToStore = [];

        if ($hasUrlImages) {
            $imageUrlsToStore = array_values(array_map('strval', $imageUrls));

            if (is_array($imageThumbnailUrls)) {
                $imageThumbnailUrlsToStore = array_values(array_map(static fn ($v) => $v === null ? null : (string) $v, $imageThumbnailUrls));
            }

            if (count($imageThumbnailUrlsToStore) > 0 && count($imageThumbnailUrlsToStore) !== count($imageUrlsToStore)) {
                return response()->json([
                    'message' => 'Validation Error',
                    'errors' => [
                        'image_thumbnail_urls' => ['The image_thumbnail_urls count must match image_urls count.'],
                    ],
                ], 422);
            }

            if ($primaryIndex < 0 || $primaryIndex >= count($imageUrlsToStore)) {
                $primaryIndex = 0;
            }
        } else {
            $imagesToStore = $request->file('images', []);
            $thumbnailsToStore = $request->file('thumbnail_images', []);

            if (! is_array($imagesToStore)) {
                $imagesToStore = [$imagesToStore];
            }

            if (! is_array($thumbnailsToStore)) {
                $thumbnailsToStore = [$thumbnailsToStore];
            }

            $thumbnailsProvided = count(array_filter($thumbnailsToStore)) > 0;

            if ($thumbnailsProvided && count($thumbnailsToStore) !== count($imagesToStore)) {
                return response()->json([
                    'message' => 'Validation Error',
                    'errors' => [
                        'thumbnail_images' => ['The thumbnail_images count must match images count.'],
                    ],
                ], 422);
            }

            if ($primaryIndex < 0 || $primaryIndex >= count($imagesToStore)) {
                $primaryIndex = 0;
            }
        }

        $tagIds = array_values(array_unique(array_map('intval', $validated['tag_ids'] ?? [])));

        $imageBytesBase64ByImageId = [];
        $result = DB::transaction(function () use ($validated, $user, $dormitoryId, $imagesToStore, $thumbnailsToStore, $imageUrlsToStore, $imageThumbnailUrlsToStore, $primaryIndex, $tagIds, &$imageBytesBase64ByImageId) {
            $product = Product::create([
                'seller_id' => $user->id,
                'dormitory_id' => $dormitoryId,
                'category_id' => $validated['category_id'],
                'condition_level_id' => $validated['condition_level_id'],
                'title' => $validated['title'],
                'description' => data_get($validated, 'description'),
                'price' => $validated['price'],
                'status' => 'available',
            ]);

            $images = [];

            if (count($imageUrlsToStore) > 0) {
                foreach ($imageUrlsToStore as $index => $url) {
                    $images[] = ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $url,
                        'image_thumbnail_url' => $imageThumbnailUrlsToStore[$index] ?? null,
                        'is_primary' => $index === $primaryIndex,
                    ]);
                }
            } else {
                foreach ($imagesToStore as $index => $file) {
                    if (! $file) {
                        continue;
                    }

                    $extension = $file->getClientOriginalExtension();
                    $fileName = (string) Str::uuid().($extension ? '.'.$extension : '');
                    $path = $file->storePubliclyAs('products/'.$user->id.'/'.$product->id, $fileName, 'public');

                    $thumbnailUrl = null;
                    $thumbnailFile = $thumbnailsToStore[$index] ?? null;

                    if ($thumbnailFile) {
                        $thumbnailExtension = $thumbnailFile->getClientOriginalExtension();
                        $thumbnailFileName = (string) Str::uuid().($thumbnailExtension ? '.'.$thumbnailExtension : '');
                        $thumbnailPath = $thumbnailFile->storePubliclyAs('products/'.$user->id.'/'.$product->id.'/thumbnails', $thumbnailFileName, 'public');
                        $thumbnailUrl = $this->publicDiskUrl($thumbnailPath);
                    }

                    $image = ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $this->publicDiskUrl($path),
                        'image_thumbnail_url' => $thumbnailUrl,
                        'is_primary' => $index === $primaryIndex,
                    ]);
                    $rawImageBytes = $file->get();
                    if (is_string($rawImageBytes) && $rawImageBytes !== '') {
                        $imageBytesBase64ByImageId[(int) $image->id] = base64_encode($rawImageBytes);
                    }

                    $images[] = $image;
                }
            }

            if (count($tagIds) > 0) {
                foreach ($tagIds as $tagId) {
                    ProductTag::create([
                        'product_id' => $product->id,
                        'tag_id' => $tagId,
                    ]);
                }
            }

            return [
                'product' => $product,
                'images' => $images,
                'tag_ids' => $tagIds,
            ];
        });

        try {
            $indexing = $this->indexProductImagesForVisualSearch($result['images'], true, $imageBytesBase64ByImageId);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => 'Product created but visual indexing failed',
                'product' => $result['product'],
                'images' => $result['images'],
                'tag_ids' => $result['tag_ids'],
                'error' => $e->getMessage(),
            ], 503);
        }

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $result['product'],
            'images' => $result['images'],
            'tag_ids' => $result['tag_ids'],
            'visual_indexing' => $indexing,
        ], 201);
    }

    public function uploadImages(Request $request, string $productId)
    {
        $user = $request->user();

        if (($user->role ?? 'user') !== 'user') {
            return response()->json([
                'message' => 'Unauthorized: Only users can access this endpoint.',
            ], 403);
        }

        $product = Product::find($productId);

        if (! $product) {
            return response()->json([
                'message' => 'Product not found.',
            ], 404);
        }

        if ((int) $product->seller_id !== (int) $user->id) {
            return response()->json([
                'message' => 'Unauthorized: You can only modify your own products.',
            ], 403);
        }

        $uploadedImages = $request->file('images');
        $uploadedThumbnailImages = $request->file('thumbnail_images');
        $imageUrls = $request->input('image_urls');
        $imageThumbnailUrls = $request->input('image_thumbnail_urls');
        $imageRules = [
            'file',
            'image',
            'mimes:jpg,jpeg,png,webp',
            'max:5120',
        ];

        $rules = [
            'primary_image_index' => 'nullable|integer|min:0',
            'image_urls' => 'nullable|array|max:6',
            'image_urls.*' => ['string', 'max:2048', 'regex:/^(https?:\/\/|\/)/'],
            'image_thumbnail_urls' => 'nullable|array|max:6',
            'image_thumbnail_urls.*' => ['nullable', 'string', 'max:2048', 'regex:/^(https?:\/\/|\/)/'],
        ];

        $hasUrlImagesInput = is_array($imageUrls) && count($imageUrls) > 0;

        if ($hasUrlImagesInput) {
            $rules['image_urls'] = 'required|array|min:1|max:6';
            $rules['images'] = 'nullable';
        } else {
            if (is_array($uploadedImages)) {
                $rules['images'] = 'required|array|min:1|max:6';
                $rules['images.*'] = implode('|', $imageRules);
            } elseif ($uploadedImages) {
                $rules['images'] = 'required|'.implode('|', $imageRules);
            } else {
                $rules['images'] = 'required';
            }
        }

        if (is_array($uploadedThumbnailImages)) {
            $rules['thumbnail_images'] = 'nullable|array|max:6';
            $rules['thumbnail_images.*'] = implode('|', $imageRules);
        } elseif ($uploadedThumbnailImages) {
            $rules['thumbnail_images'] = implode('|', $imageRules);
        } else {
            $rules['thumbnail_images'] = 'nullable';
        }

        try {
            $validated = $request->validate($rules);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => $e->errors(),
            ], 422);
        }

        $uploadedImages = $request->file('images');
        $uploadedThumbnailImages = $request->file('thumbnail_images');
        $imageUrls = $request->input('image_urls');
        $imageThumbnailUrls = $request->input('image_thumbnail_urls');

        $hasFileImages = $uploadedImages !== null;
        $hasUrlImages = is_array($imageUrls) && count($imageUrls) > 0;

        if ($hasFileImages && $hasUrlImages) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'image_urls' => ['The image_urls field cannot be used when uploading images files.'],
                ],
            ], 422);
        }

        if ($hasUrlImages && $uploadedThumbnailImages !== null) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'thumbnail_images' => ['The thumbnail_images field cannot be used with image_urls.'],
                ],
            ], 422);
        }

        if ($hasFileImages && is_array($imageThumbnailUrls) && count($imageThumbnailUrls) > 0) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'image_thumbnail_urls' => ['The image_thumbnail_urls field cannot be used when uploading images files.'],
                ],
            ], 422);
        }

        if (! $hasFileImages && ! $hasUrlImages) {
            return response()->json([
                'message' => 'Validation Error',
                'errors' => [
                    'images' => ['The images field is required.'],
                ],
            ], 422);
        }

        $primaryIndex = (int) ($validated['primary_image_index'] ?? 0);

        $imagesToStore = [];
        $thumbnailImagesToStore = [];
        $imageUrlsToStore = [];
        $imageThumbnailUrlsToStore = [];

        if ($hasUrlImages) {
            $imageUrlsToStore = array_values(array_map('strval', $imageUrls));

            if (is_array($imageThumbnailUrls)) {
                $imageThumbnailUrlsToStore = array_values(array_map(static fn ($v) => $v === null ? null : (string) $v, $imageThumbnailUrls));
            }

            if (count($imageThumbnailUrlsToStore) > 0 && count($imageThumbnailUrlsToStore) !== count($imageUrlsToStore)) {
                return response()->json([
                    'message' => 'Validation Error',
                    'errors' => [
                        'image_thumbnail_urls' => ['The image_thumbnail_urls count must match image_urls count.'],
                    ],
                ], 422);
            }

            if ($primaryIndex < 0 || $primaryIndex >= count($imageUrlsToStore)) {
                $primaryIndex = 0;
            }
        } else {
            $imagesToStore = $request->file('images', []);
            $thumbnailImagesToStore = $request->file('thumbnail_images', []);

            if (! is_array($imagesToStore)) {
                $imagesToStore = [$imagesToStore];
            }

            if (! is_array($thumbnailImagesToStore)) {
                $thumbnailImagesToStore = [$thumbnailImagesToStore];
            }

            $thumbnailsProvided = count(array_filter($thumbnailImagesToStore)) > 0;

            if ($thumbnailsProvided && count($thumbnailImagesToStore) !== count($imagesToStore)) {
                return response()->json([
                    'message' => 'Validation Error',
                    'errors' => [
                        'thumbnail_images' => ['The thumbnail_images count must match images count.'],
                    ],
                ], 422);
            }

            if ($primaryIndex < 0 || $primaryIndex >= count($imagesToStore)) {
                $primaryIndex = 0;
            }
        }

        $images = DB::transaction(function () use ($imagesToStore, $thumbnailImagesToStore, $imageUrlsToStore, $imageThumbnailUrlsToStore, $primaryIndex, $product, $user) {
            ProductImage::query()
                ->where('product_id', $product->id)
                ->update(['is_primary' => false]);

            $created = [];

            if (count($imageUrlsToStore) > 0) {
                foreach ($imageUrlsToStore as $index => $url) {
                    $created[] = ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $url,
                        'image_thumbnail_url' => $imageThumbnailUrlsToStore[$index] ?? null,
                        'is_primary' => $index === $primaryIndex,
                    ]);
                }
            } else {
                foreach ($imagesToStore as $index => $file) {
                    if (! $file) {
                        continue;
                    }

                    $extension = $file->getClientOriginalExtension();
                    $fileName = (string) Str::uuid().($extension ? '.'.$extension : '');
                    $path = $file->storePubliclyAs('products/'.$user->id.'/'.$product->id, $fileName, 'public');

                    $thumbnailUrl = null;
                    $thumbnailFile = $thumbnailImagesToStore[$index] ?? null;

                    if ($thumbnailFile) {
                        $thumbnailExtension = $thumbnailFile->getClientOriginalExtension();
                        $thumbnailFileName = (string) Str::uuid().($thumbnailExtension ? '.'.$thumbnailExtension : '');
                        $thumbnailPath = $thumbnailFile->storePubliclyAs('products/'.$user->id.'/'.$product->id.'/thumbnails', $thumbnailFileName, 'public');
                        $thumbnailUrl = $this->publicDiskUrl($thumbnailPath);
                    }

                    $created[] = ProductImage::create([
                        'product_id' => $product->id,
                        'image_url' => $this->publicDiskUrl($path),
                        'image_thumbnail_url' => $thumbnailUrl,
                        'is_primary' => $index === $primaryIndex,
                    ]);
                }
            }

            return $created;
        });

        $this->indexProductImagesForVisualSearch($images);

        return response()->json([
            'message' => 'Product images uploaded successfully',
            'product_id' => $product->id,
            'images' => $images,
        ], 201);
    }
}
