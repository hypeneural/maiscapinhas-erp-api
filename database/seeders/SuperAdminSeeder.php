<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Creates the first Super Admin user with full system access.
     */
    public function run(): void
    {
        // Check if super admin already exists
        $existingSuperAdmin = User::where('is_super_admin', true)->first();

        if ($existingSuperAdmin) {
            $this->command->info("Super Admin already exists: {$existingSuperAdmin->email}");
            return;
        }

        $superAdmin = User::create([
            'name' => 'Super Administrador',
            'email' => 'anderson@maiscapinhas.com.br',
            'password' => Hash::make('password'),
            'is_super_admin' => true,
            'active' => true,
        ]);

        $this->command->info("Super Admin created successfully!");
        $this->command->info("Email: {$superAdmin->email}");
        $this->command->warn("Password: password (please change after first login)");
    }
}
