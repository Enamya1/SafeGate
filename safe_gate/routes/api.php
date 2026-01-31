<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BehavioralEventController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->get('/health-check', HealthCheckController::class);
Route::post('/user/signup', [AuthController::class, 'signup']);
Route::post('/user/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->get('/user/me', [AuthController::class, 'me']);
Route::middleware('auth:sanctum')->patch('/user/settings', [AuthController::class, 'updateProfile']);
Route::middleware('auth:sanctum')->get('/user/settings/language', [AuthController::class, 'language']);
Route::middleware('auth:sanctum')->get('/user/settings/university-options', [AuthController::class, 'settingsUniversityOptions']);
Route::middleware('auth:sanctum')->patch('/user/settings/university', [AuthController::class, 'updateUniversitySettings']);
Route::middleware('auth:sanctum')->post('/user/products', [ProductController::class, 'store']);
Route::middleware('auth:sanctum')->get('/user/products', [ProductController::class, 'myProducts']);
Route::middleware('auth:sanctum')->get('/user/products/cards', [ProductController::class, 'myProductCards']);
Route::middleware('auth:sanctum')->get('/user/get_product/{product_id}', [ProductController::class, 'getProduct']);
Route::middleware('auth:sanctum')->get('/user/products/{product_id}/edit', [ProductController::class, 'getMyProductForEdit']);
Route::middleware('auth:sanctum')->patch('/user/products/{product_id}/mark-sold', [ProductController::class, 'markMyProductAsSold']);
Route::middleware('auth:sanctum')->get('/user/products/by-tag/{tag_name}', [ProductController::class, 'listProductsByTagName']);
Route::middleware('auth:sanctum')->get('/user/products/by-category/{category_name}', [ProductController::class, 'listProductsByCategoryName']);
Route::middleware('auth:sanctum')->get('/user/get_favorites', [ProductController::class, 'myFavorites']);
Route::middleware('auth:sanctum')->post('/user/favorites', [ProductController::class, 'addProductToFavorites']);
Route::middleware('auth:sanctum')->post('/user/behavioral_events', [BehavioralEventController::class, 'store']);
Route::middleware('auth:sanctum')->post('/user/products/{product_id}/images', [ProductController::class, 'uploadImages']);
Route::middleware('auth:sanctum')->get('/user/meta/categories', [ProductController::class, 'categories']);
Route::middleware('auth:sanctum')->get('/user/meta/condition-levels', [ProductController::class, 'conditionLevels']);
Route::middleware('auth:sanctum')->get('/user/meta/tags', [ProductController::class, 'tags']);
Route::middleware('auth:sanctum')->get('/user/meta/options', [ProductController::class, 'metadataOptions']);
Route::middleware('auth:sanctum')->get('/user/meta/dormitories', [ProductController::class, 'dormitories']);
Route::middleware('auth:sanctum')->get('/user/meta/dormitories/by-university', [ProductController::class, 'dormitoriesByUserUniversity']);
Route::middleware('auth:sanctum')->post('/user/tags', [ProductController::class, 'createTag']);

Route::prefix('admin')->group(function () {
    Route::post('/test', [AdminController::class, 'index']);
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/set_university', [AdminController::class, 'set_university']);
    Route::post('/set_dormitory', [AdminController::class, 'set_dormitory']);
    Route::get('/universities', [AdminController::class, 'listUniversities']);
    Route::get('/universities/{university_name}/dormitories', [AdminController::class, 'listDormitoriesByUniversity']);
    Route::middleware('token_auth')->post('/categories', [AdminController::class, 'createCategory']);
    Route::middleware('token_auth')->post('/condition-levels', [AdminController::class, 'createConditionLevel']);
    Route::middleware('token_auth')->get('/users', [AdminController::class, 'listUsers']);
    Route::middleware('token_auth')->get('/users/{id}', [AdminController::class, 'showUser']);
    Route::middleware('token_auth')->patch('/users/{id}', [AdminController::class, 'updateUser']);
    Route::middleware('token_auth')->patch('/users/{id}/activate', [AdminController::class, 'activateUser']);
    Route::middleware('token_auth')->patch('/users/{id}/deactivate', [AdminController::class, 'deactivateUser']);
});
