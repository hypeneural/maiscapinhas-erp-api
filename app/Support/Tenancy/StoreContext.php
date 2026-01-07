<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Enums\StoreUserRole;
use App\Models\Store;
use App\Models\StoreUser;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StoreContext
{
    private ?Store $currentStore = null;
    private ?StoreUser $currentStoreUser = null;

    /**
     * Resolve store from request and validate user access.
     */
    public function resolveFromRequest(Request $request, ?User $user = null): self
    {
        $user = $user ?? $request->user();
        $storeId = $this->extractStoreId($request);

        if ($storeId === null) {
            return $this;
        }

        $store = Store::find($storeId);

        if (!$store) {
            throw new NotFoundHttpException('Store not found.');
        }

        if (!$store->active) {
            throw new AccessDeniedHttpException('Store is inactive.');
        }

        $storeUser = StoreUser::where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->first();

        if (!$storeUser) {
            throw new AccessDeniedHttpException('You do not have access to this store.');
        }

        $this->currentStore = $store;
        $this->currentStoreUser = $storeUser;

        return $this;
    }

    /**
     * Validate that user has access to store and return context.
     */
    public function validateAccess(int $storeId, User $user): self
    {
        $store = Store::find($storeId);

        if (!$store) {
            throw new NotFoundHttpException('Store not found.');
        }

        $storeUser = StoreUser::where('store_id', $storeId)
            ->where('user_id', $user->id)
            ->first();

        if (!$storeUser) {
            throw new AccessDeniedHttpException('You do not have access to this store.');
        }

        $this->currentStore = $store;
        $this->currentStoreUser = $storeUser;

        return $this;
    }

    /**
     * Require a specific role or higher in the current store.
     */
    public function requireRole(array $allowedRoles): self
    {
        if (!$this->currentStoreUser) {
            throw new AccessDeniedHttpException('No store context.');
        }

        $userRole = StoreUserRole::tryFrom($this->currentStoreUser->role);

        if (!$userRole || !in_array($userRole, $allowedRoles)) {
            throw new AccessDeniedHttpException('Insufficient role for this action.');
        }

        return $this;
    }

    /**
     * Require manager role (admin or gerente).
     */
    public function requireManager(): self
    {
        return $this->requireRole([StoreUserRole::ADMIN, StoreUserRole::GERENTE]);
    }

    /**
     * Require approver role (admin, gerente, or conferente).
     */
    public function requireApprover(): self
    {
        return $this->requireRole([
            StoreUserRole::ADMIN,
            StoreUserRole::GERENTE,
            StoreUserRole::CONFERENTE,
        ]);
    }

    public function getStore(): ?Store
    {
        return $this->currentStore;
    }

    public function getStoreId(): ?int
    {
        return $this->currentStore?->id;
    }

    public function getStoreUser(): ?StoreUser
    {
        return $this->currentStoreUser;
    }

    public function getRole(): ?StoreUserRole
    {
        if (!$this->currentStoreUser) {
            return null;
        }
        return StoreUserRole::tryFrom($this->currentStoreUser->role);
    }

    public function isManager(): bool
    {
        return $this->getRole()?->isManager() ?? false;
    }

    public function canApproveClosings(): bool
    {
        return $this->getRole()?->canApproveClosings() ?? false;
    }

    private function extractStoreId(Request $request): ?int
    {
        // Try route parameter first
        $storeId = $request->route('store') ?? $request->route('store_id');

        if ($storeId !== null) {
            return (int) $storeId;
        }

        // Try query/body parameter
        $storeId = $request->input('store_id');

        if ($storeId !== null) {
            return (int) $storeId;
        }

        return null;
    }

    /**
     * Get all store IDs the user has access to.
     */
    public static function getUserStoreIds(User $user): array
    {
        return $user->storeUsers()->pluck('store_id')->toArray();
    }
}
