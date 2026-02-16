<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Store;
use Illuminate\Support\Str;

class NormalizeV5DataCommand extends Command
{
    protected $signature = 'pdv:normalize-v5 {--file= : Path to dados_usuarios.json}';
    protected $description = 'Ingest dados_usuarios.json to populate User GUIDs and Mappings';

    public function handle()
    {
        $filePath = $this->option('file') ?? 'C:\Users\Usuario\Desktop\dados_maisCapinhas\dados_usuarios.json';

        if (!file_exists($filePath)) {
            $this->error("File not found: $filePath");
            return 1;
        }

        $json = json_decode(file_get_contents($filePath), true);
        $usersList = $json['Lista'] ?? [];

        $this->info("Found " . count($usersList) . " users to process.");

        // Cache Stores for fuzzy matching
        $stores = DB::table('pdv_store_mappings as m')
            ->join('stores as s', 'm.store_id', '=', 's.id')
            ->select('m.pdv_store_id', 's.name')
            ->get();

        foreach ($usersList as $item) {
            $guid = $item['Id'];
            $pdvId = $item['IdUsuarioHiperOnline'];
            $name = $item['Nome'];
            $login = $item['UserName'];
            $workplace = $item['LocalDeTrabalho']; // "Loja 7 - MC Bombinhas"

            // 1. Find User
            $user = User::where('guid', $guid)->first();

            if (!$user) {
                // Try fuzzy match by name
                $user = User::where('name', 'LIKE', "{$name}%")->first();

                if ($user) {
                    $this->info("Linked '$name' to ID {$user->id} by Name match. Setting GUID $guid.");
                    $user->guid = $guid;
                    $user->save();
                } else {
                    $this->warn("User '$name' (Login: $login) not found in ERP. Skipping User Update.");
                }
            } else {
                $this->line("User '$name' already has GUID {$user->guid}.");
            }

            // 2. Create Mapping if User Exists
            if ($user && $pdvId) {
                // Resolve Store ID
                $storePdvId = 0;
                if ($workplace) {
                    // Try to match workplace string to store name
                    $match = $stores->filter(function ($s) use ($workplace) {
                        return Str::contains(Str::slug($workplace), Str::slug($s->name));
                    })->first();

                    if ($match) {
                        $storePdvId = $match->pdv_store_id;
                    }
                }

                // If storePdvId is 0, we can still save mapping if global... 
                // schema requires store_pdv_id? Usually yes. 
                // But for user mapping it might be specific to that store relationship.
                // Let's assume 0 or nullable if not found? 
                // Checking Schema: pdv_user_mappings.store_pdv_id is integer? 
                // Based on PdvUserResolver, it takes storePdvId.

                // Let's use a default or skip if store not found?
                // Actually, if mapped globally, store_pdv_id might trigger constraint.
                // We'll proceed with upsert.

                DB::table('pdv_user_mappings')->updateOrInsert(
                    [
                        'pdv_user_id' => $pdvId,
                        'store_pdv_id' => $storePdvId, // Composite key? No, logic uses pdv_user_id primarily?
                    ],
                    [
                        'user_id' => $user->id,
                        'pdv_user_login' => $login,
                        'pdv_user_name' => $name,
                        'guid_usuario' => $guid,
                        'active' => true,
                        'updated_at' => now(),
                        'created_at' => now(), // only on insert
                    ]
                );

                $this->info("Mapped PDV User $pdvId to User {$user->id} (Store PDV: $storePdvId).");
            }
        }

        $this->info("Normalization Complete.");
        return 0;
    }
}
