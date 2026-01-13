<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class VerifySuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'anderson@maiscapinhas.com.br')->first();

        if (!$user) {
            $this->command->error('Usuário não encontrado!');
            return;
        }

        $this->command->info("ID: {$user->id}");
        $this->command->info("Email: {$user->email}");
        $this->command->info("is_super_admin: " . ($user->is_super_admin ? 'true' : 'false'));
        $this->command->info("isGlobalAdmin(): " . ($user->isGlobalAdmin() ? 'true' : 'false'));
        $this->command->info("hasRole('fabrica'): " . ($user->hasRole('fabrica') ? 'true' : 'false'));
        $this->command->info("Roles: " . $user->getRoleNames()->join(', '));

        // Se não for super admin, vamos corrigir
        if (!$user->is_super_admin) {
            $user->update(['is_super_admin' => true]);
            $this->command->warn("Corrigido: is_super_admin agora é TRUE");
        }
    }
}
