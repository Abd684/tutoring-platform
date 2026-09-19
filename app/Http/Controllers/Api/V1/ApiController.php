<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function perPage(Request $request): int
    {
        return min(max($request->integer('per_page', 20), 1), 100);
    }

    protected function paginated(LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'status' => true,
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    protected function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['status' => true];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }

    protected function businessError(string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'status' => false,
            'message' => $message,
        ], $status);
    }
}
