<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SearchController extends Controller {
    public function __invoke(): JsonResponse {
        return response()->json([
            'message' => 'Semantic search not implemented.',
        ], 501);
    }
}
