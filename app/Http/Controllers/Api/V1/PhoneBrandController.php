<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PhoneCatalog\StorePhoneBrandRequest;
use App\Http\Requests\PhoneCatalog\UpdatePhoneBrandRequest;
use App\Http\Resources\PhoneBrandResource;
use App\Models\PhoneBrand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PhoneBrandController extends Controller
{
    /**
     * List phone brands with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PhoneBrand::query()->withCount('models');

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('slug')) {
            $query->where('brand_slug', $request->input('slug'));
        }

        // Sorting
        $sortField = $request->input('sort', 'brand_name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 50), 100);

        return PhoneBrandResource::collection($query->paginate($perPage));
    }

    /**
     * Create a new phone brand (admin only).
     */
    public function store(StorePhoneBrandRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $brand = PhoneBrand::create($request->validated());

        return response()->json([
            'message' => 'Marca criada com sucesso.',
            'data' => new PhoneBrandResource($brand),
        ], 201);
    }

    /**
     * Show phone brand details.
     */
    public function show(PhoneBrand $phoneBrand): JsonResponse
    {
        return response()->json([
            'data' => new PhoneBrandResource($phoneBrand->loadCount('models')),
        ]);
    }

    /**
     * Update phone brand (admin only).
     */
    public function update(UpdatePhoneBrandRequest $request, PhoneBrand $phoneBrand): JsonResponse
    {
        $this->authorizeAdmin($request);

        $phoneBrand->update($request->validated());

        return response()->json([
            'message' => 'Marca atualizada com sucesso.',
            'data' => new PhoneBrandResource($phoneBrand->fresh()),
        ]);
    }

    /**
     * Delete phone brand (admin only).
     */
    public function destroy(Request $request, PhoneBrand $phoneBrand): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Check if brand has models
        if ($phoneBrand->models()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir marca com modelos vinculados.',
            ], 422);
        }

        $phoneBrand->delete();

        return response()->json([
            'message' => 'Marca excluída com sucesso.',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user->isSuperAdmin() && !$user->isGlobalAdmin()) {
            abort(403, 'Apenas administradores podem gerenciar marcas.');
        }
    }
}
