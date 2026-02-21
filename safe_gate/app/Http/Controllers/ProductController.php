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
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $result = DB::transaction(function () use ($validated, $user, $dormitoryId, $imagesToStore, $thumbnailsToStore, $imageUrlsToStore, $imageThumbnailUrlsToStore, $primaryIndex, $tagIds) {
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

        return response()->json([
            'message' => 'Product created successfully',
            'product' => $result['product'],
            'images' => $result['images'],
            'tag_ids' => $result['tag_ids'],
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

        return response()->json([
            'message' => 'Product images uploaded successfully',
            'product_id' => $product->id,
            'images' => $images,
        ], 201);
    }
}
