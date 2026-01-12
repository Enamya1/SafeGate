<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::middleware('api')->get('/health-check', HealthCheckController::class);
Route::post('/user/signup', [AuthController::class, 'signup']);
Route::post('/user/login', [AuthController::class, 'login']);

Route::prefix('admin')->group(function () {
    Route::post('/test', [AdminController::class, 'index']);
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/set_university', [AdminController::class, 'set_university']);
    Route::post('/set_dormitory', [AdminController::class, 'set_dormitory']);
    Route::get('/universities', [AdminController::class, 'listUniversities']);
    Route::get('/universities/{university_name}/dormitories', [AdminController::class, 'listDormitoriesByUniversity']);
    Route::middleware('token_auth')->get('/users', [AdminController::class, 'listUsers']);
    Route::middleware('token_auth')->get('/users/{id}', [AdminController::class, 'showUser']);
});
