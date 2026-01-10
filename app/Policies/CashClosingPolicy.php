<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\User;

class CashClosingPolicy
{
    /**
     * Determine if the user can view the closing.
     */
    public function view(User $user, CashClosing $closing): bool
    {
        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can update the closing.
     * 
     * IMUTABILIDADE: Não permite edição após aprovado.
     */
    public function update(User $user, CashClosing $closing): bool
    {
        // Não pode editar após approved
        if ($closing->isApproved()) {
            return false;
        }

        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can update a closing line.
     * 
     * IMUTABILIDADE: Não permite edição de linhas após aprovado.
     */
    public function updateLine(User $user, CashClosingLine $line): bool
    {
        $closing = $line->cashClosing;

        // Não pode editar linha após approved
        if ($closing->isApproved()) {
            return false;
        }

        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can submit the closing.
     */
    public function submit(User $user, CashClosing $closing): bool
    {
        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can approve the closing.
     * 
     * Apenas conferente, gerente ou admin pode aprovar.
     */
    public function approve(User $user, CashClosing $closing): bool
    {
        $storeId = $closing->cashShift->store_id;
        return $user->hasAccessToStore($storeId) && $user->canApproveInStore($storeId);
    }

    /**
     * Determine if the user can reject the closing.
     * 
     * Apenas conferente, gerente ou admin pode rejeitar.
     */
    public function reject(User $user, CashClosing $closing): bool
    {
        $storeId = $closing->cashShift->store_id;
        return $user->hasAccessToStore($storeId) && $user->canApproveInStore($storeId);
    }

    /**
     * Determine if the user can reopen an approved closing.
     * 
     * Apenas admin pode reabrir fechamento aprovado.
     */
    public function reopen(User $user, CashClosing $closing): bool
    {
        if (!$closing->isApproved()) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $role = $user->roleInStore($closing->cashShift->store_id);
        return $role === 'admin';
    }
}
