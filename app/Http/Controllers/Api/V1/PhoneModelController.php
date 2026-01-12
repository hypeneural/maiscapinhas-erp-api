<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PhoneCatalog\StorePhoneModelRequest;
use App\Http\Requests\PhoneCatalog\UpdatePhoneModelRequest;
use App\Http\Resources\PhoneModelResource;
use App\Models\PhoneModel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PhoneModelController extends Controller
{
    /**
     * List phone models with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PhoneModel::query()->with('brand');

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        if ($request->filled('brand_id')) {
            $query->byBrand((int) $request->input('brand_id'));
        }

        if ($request->filled('form_factor')) {
            $query->byFormFactor($request->input('form_factor'));
        }

        if ($request->filled('release_year')) {
            $query->byReleaseYear((int) $request->input('release_year'));
        }

        // Sorting
        $sortField = $request->input('sort', 'marketing_name');
        $sortDirection = $request->input('direction', 'asc');
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 50), 100);

        return PhoneModelResource::collection($query->paginate($perPage));
    }

    /**
     * Create a new phone model (admin only).
     */
    public function store(StorePhoneModelRequest $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $model = PhoneModel::create($request->validated());

        return response()->json([
            'message' => 'Modelo criado com sucesso.',
            'data' => new PhoneModelResource($model->load('brand')),
        ], 201);
    }

    /**
     * Show phone model details.
     */
    public function show(PhoneModel $phoneModel): JsonResponse
    {
        return response()->json([
            'data' => new PhoneModelResource($phoneModel->load('brand')),
        ]);
    }

    /**
     * Update phone model (admin only).
     */
    public function update(UpdatePhoneModelRequest $request, PhoneModel $phoneModel): JsonResponse
    {
        $this->authorizeAdmin($request);

        $phoneModel->update($request->validated());

        return response()->json([
            'message' => 'Modelo atualizado com sucesso.',
            'data' => new PhoneModelResource($phoneModel->fresh()->load('brand')),
        ]);
    }

    /**
     * Delete phone model (admin only).
     */
    public function destroy(Request $request, PhoneModel $phoneModel): JsonResponse
    {
        $this->authorizeAdmin($request);

        // Check if model has devices
        if ($phoneModel->devices()->exists()) {
            return response()->json([
                'message' => 'Não é possível excluir modelo com dispositivos vinculados.',
            ], 422);
        }

        $phoneModel->delete();

        return response()->json([
            'message' => 'Modelo excluído com sucesso.',
        ]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (!$user->isSuperAdmin() && !$user->isGlobalAdmin()) {
            abort(403, 'Apenas administradores podem gerenciar modelos.');
        }
    }
}
