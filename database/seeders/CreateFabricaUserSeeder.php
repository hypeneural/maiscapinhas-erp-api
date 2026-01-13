<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class CreateFabricaUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create role if it doesn't exist
        Role::firstOrCreate(['name' => 'fabrica', 'guard_name' => 'web']);

        $user = User::firstOrCreate(
            ['email' => 'fabrica@maiscapinhas.com.br'],
            [
                'name' => 'Fábrica',
                'password' => bcrypt('password'),
                'active' => true,
            ]
        );

        $user->assignRole('fabrica');

        $this->command->info("User created/updated with ID: {$user->id}");
    }
}
