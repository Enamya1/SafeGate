<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;

Route::middleware('api')->get('/health-check', HealthCheckController::class);
Route::post('/user/signup', [AuthController::class, 'signup']);
Route::post('/user/login', [AuthController::class, 'login']);

Route::prefix('admin')->group(function () {
    Route::post('/test', [AdminController::class, 'index']);
    Route::post('/login', [AdminController::class, 'login']);
    Route::post('/set_university', [AdminController::class, 'set_university']);
    Route::post('/set_dormitory', [AdminController::class, 'set_dormitory']);
}); 