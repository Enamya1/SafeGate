<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\AuthController;

Route::middleware('api')->get('/health-check', HealthCheckController::class);
Route::post('/user/signup', [AuthController::class, 'signup']); 