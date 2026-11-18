<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class DocumentController extends Controller {
    public function index(): JsonResponse {
        return response()->json(['data' => []]);
    }
    public function store(): JsonResponse {
        return response()->json([
            'message' => 'Document ingestion not implemented.',
        ], 501);
    }
    public function show(string $id): JsonResponse {
        return response()->json([
            'message' => 'Document not found.',
        ], 404);
    }
}
