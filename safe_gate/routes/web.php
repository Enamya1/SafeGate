<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\HealthCheckController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/health-check', HealthCheckController::class);
