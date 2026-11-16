<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HealthController
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'service' => 'mediflow-platform-api',
                'status' => 'available',
                'version' => 'v1',
            ],
        ]);
    }
}
