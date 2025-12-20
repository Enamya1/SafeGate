<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;

Route::middleware('api')->get('/health-check', HealthCheckController::class);
