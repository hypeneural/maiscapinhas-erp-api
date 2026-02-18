<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Models\PdvTurno;
use App\Services\Pdv\PdvClosureUnifiedService;

class DiagnoseClosure extends Command
{
    protected $signature = 'debug:diagnose-closure {store_guid} {date} {sequencial}';
    protected $description = 'Diagnose closure data by store GUID';

    public function handle(PdvClosureUnifiedService $service)
    {
        $guid = $this->argument('store_guid');
        $date = $this->argument('date');
        $seq = $this->argument('sequencial');

        $this->info("Looking up store: $guid");
        $store = DB::table('stores')->where('guid', $guid)->first();

        if (!$store) {
            $this->error("Store not found for GUID: $guid");
            return;
        }

        $this->info("Store raw: " . json_encode($store));
        $storePdvId = $store->store_pdv_id ?? $store->id ?? 0;
        $this->info("Using Store PDV ID: $storePdvId (derived)");

        $this->info("Looking for turnos with store_id: {$store->id} (Guid: $guid)");

        $turnos = PdvTurno::where('store_id', $store->id)
            ->whereDate('data_hora_inicio', $date)
            ->where('sequencial', $seq)
            ->get();

        $this->info("Turnos found: " . $turnos->count());

        foreach ($turnos as $t) {
            $this->info("ID: {$t->id}, PDV ID: {$t->store_pdv_id}, TurnoID: {$t->id_turno}, Fechado: {$t->fechado}, UUID: {$t->closure_uuid}, SyncID: {$t->last_sync_id}");
        }

        if ($turnos->isEmpty()) {
            $this->info("No turnos found.");
            return;
        }

        $lastSyncId = $turnos->first()->last_sync_id;
        $this->info("Analyzing Sync ID: $lastSyncId");

        $sync = DB::table('pdv_syncs')->where('sync_id', $lastSyncId)->first();
        if (!$sync) {
            $this->error("Sync record not found for $lastSyncId");
            return;
        }

        $syncPayload = DB::table('pdv_sync_payloads')->where('pdv_sync_id', $sync->id)->first();
        if (!$syncPayload) {
            $this->error("Sync payload not found for PdvSync ID {$sync->id}");
            return;
        }

        $data = json_decode($syncPayload->payload, true);

        $startTurnos = $data['turnos'] ?? [];
        $tAb = $data['turnos_abertos'] ?? [];
        $tFec = $data['turnos_fechados'] ?? [];

        $rawTurnos = array_merge(
            is_array($startTurnos) ? $startTurnos : [],
            is_array($tAb) ? $tAb : [],
            is_array($tFec) ? $tFec : []
        );

        $this->info("Raw Turnos in Payload (Merged): " . count($rawTurnos));

        foreach ($rawTurnos as $index => $rt) {
            $id = $rt['IdTurno'] ?? $rt['id_turno'] ?? '?';
            $fechado = $rt['Fechado'] ?? $rt['fechado'] ?? 'N/A';

            // Check PascalCase too
            $closureUuid = $rt['fechamento_declarado']['Id'] ?? $rt['FechamentoDeclarado']['Id'] ?? 'N/A';

            $this->info("Raw Turno $id | Fechado=$fechado | ClosureUUID=$closureUuid");

            if ($index === 0) {
                $this->info("First Turno Dump: " . json_encode($rt));
            }
        }

        // Skip service diagnostic if UUID is missing
        $closureUuid = $turnos->first()->closure_uuid;
        if (!$closureUuid) {
            return;
        }

        $unified = $service->getUnifiedByClosureUuid($closureUuid);
        $json = json_encode($unified, JSON_PRETTY_PRINT);

        $filename = "loja1_seq{$seq}.json";
        file_put_contents($filename, $json);
        $this->info("Unified data saved to $filename");

        $this->line($json);
    }
}
