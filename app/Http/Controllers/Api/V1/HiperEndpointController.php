<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HiperEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HiperEndpointController extends Controller
{
    /**
     * List all endpoints (catalog).
     */
    public function index(): JsonResponse
    {
        $endpoints = HiperEndpoint::orderBy('key')->get();

        return response()->json(['ok' => true, 'endpoints' => $endpoints]);
    }

    /**
     * Show a single endpoint.
     */
    public function show(HiperEndpoint $endpoint): JsonResponse
    {
        return response()->json(['ok' => true, 'endpoint' => $endpoint]);
    }

    /**
     * Create or update an endpoint.
     */
    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => 'nullable|integer|exists:hiper_endpoints,id',
            'key' => 'required|string|max:100',
            'method' => 'required|string|in:GET,POST',
            'path' => 'required|string|max:500',
            'headers' => 'nullable|array',
            'query_template' => 'nullable|array',
            'body_template' => 'nullable|array',
        ]);

        $endpoint = HiperEndpoint::updateOrCreate(
            $validated['id'] ? ['id' => $validated['id']] : ['key' => $validated['key']],
            [
                'key' => $validated['key'],
                'method' => strtoupper($validated['method']),
                'path' => $validated['path'],
                'headers' => $validated['headers'],
                'query_template' => $validated['query_template'],
                'body_template' => $validated['body_template'],
            ]
        );

        return response()->json([
            'ok' => true,
            'endpoint' => $endpoint,
        ], $request->filled('id') ? 200 : 201);
    }

    /**
     * Delete an endpoint.
     */
    public function destroy(HiperEndpoint $endpoint): JsonResponse
    {
        $endpoint->delete();

        return response()->json(['ok' => true, 'deleted' => $endpoint->key]);
    }
}
