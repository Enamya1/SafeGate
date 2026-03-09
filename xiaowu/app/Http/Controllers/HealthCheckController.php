<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class HealthCheckController extends Controller
{
    public function __invoke(Request $request)
    {
        return Response::json(['status' => 'ok', 'message' => 'Application is running smoothly!']);
    }
}
