<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use App\Models\Store;
use App\Models\User;
use Carbon\Carbon;

class NormalizeV5DataCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'normalize:v5-data {--stores-path=} {--users-path=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Normalize Stores and Users data from V5 JSON files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $storesPath = $this->option('stores-path') ?? 'C:\Users\Usuario\Desktop\dados_maisCapinhas\dados_de_loja.json';
        $usersPath = $this->option('users-path') ?? 'C:\Users\Usuario\Desktop\dados_maisCapinhas\dados_usuarios.json';

        $this->info("Starting V5 Normalization...");
        $this->info("Stores Path: $storesPath");
        $this->info("Users Path: $usersPath");

        if (File::exists($storesPath)) {
            $this->normalizeStores($storesPath);
        } else {
            $this->error("Stores file not found!");
        }

        if (File::exists($usersPath)) {
            $this->normalizeUsers($usersPath);
        } else {
            $this->error("Users file not found!");
        }

        $this->info("Normalization Complete!");
    }

    private function normalizeStores(string $path)
    {
        $this->info("Processing Stores...");
        $json = File::json($path);

        foreach ($json as $item) {
            $guid = $item['LojaId'] ?? null;
            $name = $item['Nome'] ?? null;
            $cnpj = $item['CnpjDaLoja'] ?? null;
            $razaoSocial = $item['RazaoSocial'] ?? null;
            $nomeFantasia = $item['NomeFantasia'] ?? null;
            $pdvId = $item['Id'] ?? null; // External PDV ID

            if (!$guid || !$name)
                continue;

            $this->line("Processing Store: $name ($guid)");

            // Update or Create Store by GUID
            $store = Store::where('guid', $guid)->first();

            if (!$store) {
                // Try finding by CNPJ or Name if GUID didn't match
                if ($cnpj) {
                    $store = Store::where('cnpj', $cnpj)->first();
                }
                if (!$store) {
                    $store = Store::where('name', $name)->first();
                }
            }

            if ($store) {
                $store->update([
                    'guid' => $guid,
                    'cnpj' => $cnpj,
                    'razao_social' => $razaoSocial,
                    'nome_fantasia' => $nomeFantasia,
                    'name' => $item['Apelido'] ?? $name, // Prefer nickname for internal display? Or keep original?
                ]);
            } else {
                $store = Store::create([
                    'guid' => $guid,
                    'name' => $item['Apelido'] ?? $name,
                    'cnpj' => $cnpj,
                    'razao_social' => $razaoSocial,
                    'nome_fantasia' => $nomeFantasia,
                    'city' => 'Unknown', // Default
                    'active' => true
                ]);
            }

            // Sync with pdv_lojas
            if ($pdvId) {
                DB::table('pdv_lojas')->updateOrInsert(
                    ['id_ponto_venda' => $pdvId],
                    [
                        'guid_loja' => $guid,
                        'nome_padronizado' => $name,
                        'nome_hiper' => $item['NomeFantasia'] ?? $name,
                    ]
                );

                // Ensure Mapping Exists
                DB::table('pdv_store_mappings')->updateOrInsert(
                    ['pdv_store_id' => $pdvId],
                    ['store_id' => $store->id, 'alias' => $item['Apelido']]
                );
            }
        }
    }

    private function normalizeUsers(string $path)
    {
        $this->info("Processing Users...");
        $json = File::json($path);

        $list = $json['Lista'] ?? $json; // Handle different structures if needed

        foreach ($list as $item) {
            $guid = $item['Id'] ?? null;
            $erpId = $item['IdUsuarioHiperOnline'] ?? null;
            $name = $item['Nome'] ?? null;
            $email = $item['UserName'] . '@maiscapinhas.com.br'; // Fallback email generation
            // Note: Data doesn't have real emails, only UserNames usually.

            if (!$guid)
                continue;

            $this->line("Processing User: $name ($guid)");

            // Try to find existing User
            $user = User::where('guid', $guid)->first();

            if (!$user && $erpId) {
                $user = User::where('erp_id', $erpId)->first();
            }

            if (!$user) {
                // Try by name (risky but needed if no other ID)
                $user = User::where('name', $name)->first();
            }

            if ($user) {
                $user->update([
                    'guid' => $guid,
                    'erp_id' => $erpId
                ]);
            } else {
                // Create new user (Stub)
                // We might need a randomized email if not provided
                $user = User::create([
                    'name' => $name,
                    'email' => $email, // Placeholder
                    'password' => bcrypt('password'), // Default password
                    'guid' => $guid,
                    'erp_id' => $erpId,
                    'active' => !($item['Bloqueado'] ?? false)
                ]);
            }

            // Sync pdv_usuarios
            if ($erpId) {
                DB::table('pdv_usuarios')->updateOrInsert(
                    ['id_usuario_hiper' => $erpId],
                    [
                        'guid_usuario' => $guid,
                        'nome_padronizado' => $name,
                        'login_hiper' => $item['UserName'] ?? null,
                        'papel' => $item['PerfilUsuarioNome'] ?? 'Vendedor',
                        'ativo' => !($item['Bloqueado'] ?? false)
                    ]
                );

                // Retrieve pdv_user_id (id from pdv_usuarios table, not erp_id)
                $pdvUser = DB::table('pdv_usuarios')->where('id_usuario_hiper', $erpId)->first();

                if ($pdvUser) {
                    // Mapping
                    // NOTE: Mapping usually requires a store context. 
                    // But here we are just linking User -> PDV User globally if possible.
                    // The pdv_user_mappings table requires `store_pdv_id`. 
                    // The JSON has `LocalDeTrabalho`, but that's a string name.
                    // We can skip mapping creation here and let it happen on demand, 
                    // OR try to resolve the store name to an ID.
                }
            }
        }
    }
}
