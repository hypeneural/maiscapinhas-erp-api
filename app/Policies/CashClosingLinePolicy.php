<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashClosingLine;
use App\Models\User;

class CashClosingLinePolicy
{
    /**
     * Determine if the user can view the line.
     */
    public function view(User $user, CashClosingLine $line): bool
    {
        return $user->hasAccessToStore($line->cashClosing->cashShift->store_id);
    }

    /**
     * Determine if the user can update the line.
     * 
     * IMUTABILIDADE: Não permite edição após fechamento aprovado.
     */
    public function update(User $user, CashClosingLine $line): bool
    {
        $closing = $line->cashClosing;

        // Não pode editar linha após approved
        if ($closing->isApproved()) {
            return false;
        }

        return $user->hasAccessToStore($closing->cashShift->store_id);
    }

    /**
     * Determine if the user can delete the line.
     * 
     * Apenas draft permite exclusão de linhas.
     */
    public function delete(User $user, CashClosingLine $line): bool
    {
        $closing = $line->cashClosing;

        // Só pode excluir se estiver em draft
        if (!$closing->isDraft()) {
            return false;
        }

        return $user->hasAccessToStore($closing->cashShift->store_id);
    }
}
