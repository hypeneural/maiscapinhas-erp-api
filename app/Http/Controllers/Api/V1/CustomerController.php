<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerDeviceRequest;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerDeviceRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CustomerDeviceResource;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    /**
     * List customers with filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Customer::query()
            ->with(['devices.phoneModel.brand']);

        // Apply scoping for non-admin users
        if (!$this->isAdmin($request)) {
            $userId = $request->user()->id;
            $query->where(function ($q) use ($userId) {
                $q->where('created_by_id', $userId)
                    ->orWhereHas('pedidos', fn($pq) => $pq->where('user_id', $userId))
                    ->orWhereHas('capasPersonalizadas', fn($cq) => $cq->where('user_id', $userId));
            });
        }

        // Unified keyword search (name, email, phone)
        if ($request->filled('keyword')) {
            $query->search($request->input('keyword'));
        }

        // Individual field filters (kept for backward compatibility)
        if ($request->filled('name')) {
            $query->where('name', 'like', '%' . $request->input('name') . '%');
        }

        if ($request->filled('email')) {
            $query->where('email', 'like', '%' . $request->input('email') . '%');
        }

        if ($request->filled('phone')) {
            $query->where('phone', 'like', '%' . $request->input('phone') . '%');
        }

        if ($request->filled('city')) {
            $query->where('city', 'like', '%' . $request->input('city') . '%');
        }

        if ($request->filled('state')) {
            $query->where('state', $request->input('state'));
        }

        // Date range filters
        if ($request->filled('initial_date')) {
            $query->where('created_at', '>=', $request->input('initial_date'));
        }

        if ($request->filled('final_date')) {
            $query->where('created_at', '<=', $request->input('final_date') . ' 23:59:59');
        }

        if ($request->filled('has_device')) {
            if ($request->boolean('has_device')) {
                $query->has('devices');
            } else {
                $query->doesntHave('devices');
            }
        }

        if ($request->filled('brand_id')) {
            $query->whereHas(
                'devices.phoneModel',
                fn($q) =>
                $q->where('brand_id', $request->input('brand_id'))
            );
        }

        if ($request->filled('model_id')) {
            $query->whereHas(
                'devices',
                fn($q) =>
                $q->where('phone_model_id', $request->input('model_id'))
            );
        }

        // Sorting with whitelist validation
        $allowedSortFields = ['id', 'name', 'email', 'phone', 'city', 'state', 'created_at', 'updated_at'];
        $sortField = $request->input('sort', 'created_at');
        $sortField = in_array($sortField, $allowedSortFields) ? $sortField : 'created_at';
        $sortDirection = $request->input('direction', 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sortField, $sortDirection);

        // Pagination
        $perPage = min($request->input('per_page', 15), 100);

        return CustomerResource::collection($query->paginate($perPage));
    }

    /**
     * Create a new customer.
     */
    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by_id'] = $request->user()->id;

        $customer = Customer::create($data);

        return response()->json([
            'message' => 'Cliente criado com sucesso.',
            'data' => new CustomerResource($customer->load('devices.phoneModel.brand')),
        ], 201);
    }

    /**
     * Show customer details.
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($request, $customer);

        return response()->json([
            'data' => new CustomerResource($customer->load(['devices.phoneModel.brand', 'createdBy'])),
        ]);
    }

    /**
     * Update customer.
     */
    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($request, $customer);

        $customer->update($request->validated());

        return response()->json([
            'message' => 'Cliente atualizado com sucesso.',
            'data' => new CustomerResource($customer->fresh()->load('devices.phoneModel.brand')),
        ]);
    }

    /**
     * Delete customer (soft delete).
     */
    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($request, $customer);

        $customer->delete();

        return response()->json([
            'message' => 'Cliente excluído com sucesso.',
        ]);
    }

    // ========================================
    // Customer Devices
    // ========================================

    /**
     * List customer devices.
     */
    public function devices(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAccess($request, $customer);

        $devices = $customer->devices()->with('phoneModel.brand')->get();

        return CustomerDeviceResource::collection($devices);
    }

    /**
     * Add device to customer.
     */
    public function storeDevice(StoreCustomerDeviceRequest $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($request, $customer);

        $data = $request->validated();
        $data['customer_id'] = $customer->id;

        // If is_primary, unset other primaries
        if ($request->boolean('is_primary')) {
            $customer->devices()->update(['is_primary' => false]);
        }

        $device = CustomerDevice::create($data);

        return response()->json([
            'message' => 'Dispositivo vinculado com sucesso.',
            'data' => new CustomerDeviceResource($device->load('phoneModel.brand')),
        ], 201);
    }

    /**
     * Update customer device.
     */
    public function updateDevice(
        UpdateCustomerDeviceRequest $request,
        Customer $customer,
        CustomerDevice $device
    ): JsonResponse {
        $this->authorizeAccess($request, $customer);

        // Verify device belongs to customer
        if ($device->customer_id !== $customer->id) {
            return response()->json([
                'message' => 'Dispositivo não pertence a este cliente.',
            ], 404);
        }

        $data = $request->validated();

        // If setting as primary, unset others
        if ($request->boolean('is_primary')) {
            $customer->devices()->where('id', '!=', $device->id)->update(['is_primary' => false]);
        }

        $device->update($data);

        return response()->json([
            'message' => 'Dispositivo atualizado com sucesso.',
            'data' => new CustomerDeviceResource($device->fresh()->load('phoneModel.brand')),
        ]);
    }

    /**
     * Remove device from customer.
     */
    public function destroyDevice(Request $request, Customer $customer, CustomerDevice $device): JsonResponse
    {
        $this->authorizeAccess($request, $customer);

        // Verify device belongs to customer
        if ($device->customer_id !== $customer->id) {
            return response()->json([
                'message' => 'Dispositivo não pertence a este cliente.',
            ], 404);
        }

        $device->delete();

        return response()->json([
            'message' => 'Dispositivo removido com sucesso.',
        ]);
    }

    // ========================================
    // Authorization Helpers
    // ========================================

    private function isAdmin(Request $request): bool
    {
        $user = $request->user();
        return $user->isSuperAdmin() || $user->isGlobalAdmin();
    }

    private function authorizeAccess(Request $request, Customer $customer): void
    {
        if ($this->isAdmin($request)) {
            return;
        }

        $userId = $request->user()->id;

        // Check if user created this customer or has related pedidos/capas
        $hasAccess = $customer->created_by_id === $userId
            || $customer->pedidos()->where('user_id', $userId)->exists()
            || $customer->capasPersonalizadas()->where('user_id', $userId)->exists();

        if (!$hasAccess) {
            abort(403, 'Você não tem permissão para acessar este cliente.');
        }
    }
}
