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

/**
 * @group Clientes
 *
 * Gestão de clientes e seus dispositivos (celulares).
 * Clientes são usados para associar pedidos e capas personalizadas.
 *
 * **Escopo de acesso:**
 * - Super admins e admins globais: acesso a todos os clientes
 * - Outros usuários: apenas clientes que criaram ou possuem pedidos/capas associadas
 *
 * **Permissões:** Todos os usuários autenticados.
 */
class CustomerController extends Controller
{
    /**
     * Listar clientes
     *
     * Retorna lista paginada de clientes com filtros diversos.
     * O escopo de acesso varia conforme o papel do usuário.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @queryParam keyword string Busca unificada (nome, email, telefone). Example: João
     * @queryParam name string Filtrar por nome. Example: João Silva
     * @queryParam email string Filtrar por email. Example: joao@email.com
     * @queryParam phone string Filtrar por telefone. Example: 11999999999
     * @queryParam city string Filtrar por cidade. Example: São Paulo
     * @queryParam state string Filtrar por UF (sigla). Example: SP
     * @queryParam initial_date string Data de cadastro inicial. Example: 2026-01-01
     * @queryParam final_date string Data de cadastro final. Example: 2026-12-31
     * @queryParam has_device boolean Filtrar clientes com/sem dispositivo. Example: true
     * @queryParam brand_id integer Filtrar por marca de dispositivo. Example: 1
     * @queryParam model_id integer Filtrar por modelo de dispositivo. Example: 5
     * @queryParam sort string Campo para ordenação. Example: name
     * @queryParam direction string Direção: `asc` ou `desc`. Example: asc
     * @queryParam per_page integer Itens por página (máx 100). Example: 15
     *
     * @response 200 scenario="Lista de clientes" {
     *   "data": [{
     *     "id": 1,
     *     "name": "João Silva",
     *     "email": "joao@email.com",
     *     "phone": "11999999999",
     *     "city": "São Paulo",
     *     "devices": [{"id": 1, "model": "iPhone 15"}]
     *   }],
     *   "meta": {"current_page": 1, "total": 100}
     * }
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
     * Criar cliente
     *
     * Cria um novo cliente no sistema.
     *
     * **Quem pode usar:** Todos os usuários autenticados.
     *
     * @bodyParam name string required Nome do cliente. Example: João Silva
     * @bodyParam email string Email do cliente. Example: joao@email.com
     * @bodyParam phone string Telefone do cliente. Example: 11999999999
     * @bodyParam city string Cidade. Example: São Paulo
     * @bodyParam state string UF (sigla). Example: SP
     * @bodyParam address string Endereço completo. Example: Rua das Flores, 123
     * @bodyParam notes string Observações. Example: Cliente preferencial
     *
     * @response 201 scenario="Cliente criado" {
     *   "message": "Cliente criado com sucesso.",
     *   "data": {"id": 1, "name": "João Silva", "email": "joao@email.com"}
     * }
     *
     * @response 422 scenario="Validação falhou" {"message": "The name field is required."}
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
     * Detalhes do cliente
     *
     * Retorna detalhes completos do cliente com dispositivos.
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     *
     * @response 200 scenario="Detalhes do cliente" {
     *   "data": {
     *     "id": 1,
     *     "name": "João Silva",
     *     "devices": [{"id": 1, "model": "iPhone 15", "brand": "Apple"}],
     *     "created_by": {"id": 1, "name": "Admin"}
     *   }
     * }
     *
     * @response 403 scenario="Sem permissão" {"message": "Você não tem permissão para acessar este cliente."}
     */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAccess($request, $customer);

        return response()->json([
            'data' => new CustomerResource($customer->load(['devices.phoneModel.brand', 'createdBy'])),
        ]);
    }

    /**
     * Atualizar cliente
     *
     * Atualiza os dados de um cliente existente.
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     * @bodyParam name string Nome do cliente. Example: João Silva Atualizado
     * @bodyParam email string Email do cliente. Example: joao.novo@email.com
     * @bodyParam phone string Telefone do cliente. Example: 11988888888
     *
     * @response 200 scenario="Cliente atualizado" {
     *   "message": "Cliente atualizado com sucesso.",
     *   "data": {"id": 1, "name": "João Silva Atualizado"}
     * }
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
     * Excluir cliente
     *
     * Remove um cliente (soft delete).
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     *
     * @response 200 scenario="Cliente excluído" {"message": "Cliente excluído com sucesso."}
     * @response 403 scenario="Sem permissão" {"message": "Você não tem permissão para acessar este cliente."}
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
     * Listar dispositivos do cliente
     *
     * Retorna todos os dispositivos (celulares) vinculados ao cliente.
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     *
     * @response 200 scenario="Lista de dispositivos" {
     *   "data": [
     *     {"id": 1, "phone_model": {"id": 5, "name": "iPhone 15", "brand": "Apple"}, "is_primary": true}
     *   ]
     * }
     */
    public function devices(Request $request, Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAccess($request, $customer);

        $devices = $customer->devices()->with('phoneModel.brand')->get();

        return CustomerDeviceResource::collection($devices);
    }

    /**
     * Adicionar dispositivo
     *
     * Vincula um novo dispositivo ao cliente.
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     * @bodyParam phone_model_id integer required ID do modelo de telefone. Example: 5
     * @bodyParam is_primary boolean Definir como dispositivo principal. Example: true
     * @bodyParam nickname string Apelido do dispositivo. Example: Celular do trabalho
     *
     * @response 201 scenario="Dispositivo adicionado" {
     *   "message": "Dispositivo vinculado com sucesso.",
     *   "data": {"id": 1, "phone_model": {"name": "iPhone 15"}, "is_primary": true}
     * }
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
     * Atualizar dispositivo
     *
     * Atualiza os dados de um dispositivo do cliente.
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     * @urlParam device integer required ID do dispositivo. Example: 1
     * @bodyParam phone_model_id integer ID do modelo de telefone. Example: 6
     * @bodyParam is_primary boolean Definir como dispositivo principal. Example: false
     * @bodyParam nickname string Apelido do dispositivo. Example: Celular pessoal
     *
     * @response 200 scenario="Dispositivo atualizado" {
     *   "message": "Dispositivo atualizado com sucesso.",
     *   "data": {"id": 1, "is_primary": false}
     * }
     *
     * @response 404 scenario="Não pertence ao cliente" {"message": "Dispositivo não pertence a este cliente."}
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
     * Remover dispositivo
     *
     * Remove um dispositivo do cliente.
     *
     * **Quem pode usar:** Usuário com acesso ao cliente.
     *
     * @urlParam customer integer required ID do cliente. Example: 1
     * @urlParam device integer required ID do dispositivo. Example: 1
     *
     * @response 200 scenario="Dispositivo removido" {"message": "Dispositivo removido com sucesso."}
     * @response 404 scenario="Não pertence ao cliente" {"message": "Dispositivo não pertence a este cliente."}
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

