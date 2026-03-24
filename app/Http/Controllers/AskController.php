<?php

namespace App\Http\Controllers;

use App\Http\Requests\AskRequest;
use App\Services\Chat\AskService;
use Illuminate\Http\JsonResponse;

class AskController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(AskRequest $request, AskService $service): JsonResponse
    {
        $payload = $request->validated();

        $result = $service->answer(
            question: (string) $payload['question'],
            cityId: $payload['city_id'] ?? null,
            citySlug: $payload['city_slug'] ?? null,
            fallbackIntent: $payload['fallback_intent'] ?? null,
        );

        return response()->json($result);
    }
}
