<?php

namespace App\Http\Controllers\Api\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Resources\EntitlementResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EntitlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entitlements = $request->user()->entitlements()
            ->valid()
            ->whereHas('deck', fn ($q) => $q->active())
            ->with('deck')
            ->latest('granted_at')
            ->get();

        return ApiResponse::success([
            'entitlements' => EntitlementResource::collection($entitlements),
        ], 'Entitlements retrieved.');
    }
}
