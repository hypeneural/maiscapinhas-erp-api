<?php

declare(strict_types=1);

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Migrate existing StoreUser roles to UserStoreRole table.
 */
return new class extends Migration {
    /**
     * Role name mapping from StoreUser to new Role names.
     */
    private const ROLE_MAP = [
        'admin' => Role::ADMIN,
        'gerente' => Role::GERENTE,
        'conferente' => Role::CONFERENTE,
        'vendedor' => Role::VENDEDOR,
        'estoquista' => Role::ESTOQUISTA,
    ];

    public function up(): void
    {
        // Load all roles
        $roles = DB::table('roles')->pluck('id', 'name')->toArray();

        // Get all store-user relationships
        $storeUsers = DB::table('store_users')->get();

        foreach ($storeUsers as $storeUser) {
            $roleName = self::ROLE_MAP[$storeUser->role] ?? Role::VENDEDOR;
            $roleId = $roles[$roleName] ?? null;

            if (!$roleId) {
                continue;
            }

            // Check if already exists
            $exists = DB::table('user_store_roles')
                ->where('user_id', $storeUser->user_id)
                ->where('role_id', $roleId)
                ->where('store_id', $storeUser->store_id)
                ->exists();

            if (!$exists) {
                DB::table('user_store_roles')->insert([
                    'user_id' => $storeUser->user_id,
                    'role_id' => $roleId,
                    'store_id' => $storeUser->store_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Clear all store-specific role assignments
        DB::table('user_store_roles')->whereNotNull('store_id')->delete();
    }
};
