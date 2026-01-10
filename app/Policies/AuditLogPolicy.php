<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * Policy para controle de acesso aos logs de auditoria.
 * 
 * Apenas administradores podem visualizar logs.
 */
class AuditLogPolicy
{
    /**
     * Determina se o usuário pode listar logs.
     */
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determina se o usuário pode ver um log específico.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Verifica se o usuário é admin em alguma loja.
     */
    private function isAdmin(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }
        return $user->storeUsers()->where('role', 'admin')->exists();
    }
}
