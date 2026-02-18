<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill closure_uuid into pdv_turno_pagamentos from pdv_turnos.
 *
 * Run after the migration that adds pdv_turno_pagamentos.closure_uuid.
 * Safe to re-run (idempotent) — only updates NULL rows.
 */
class BackfillClosureUuidOnPagamentos extends Command
{
    protected $signature = 'pdv:backfill-closure-uuid
        {--chunk=1000 : Number of rows per batch}
        {--dry-run : Show counts without updating}';

    protected $description = 'Backfill closure_uuid from pdv_turnos into pdv_turno_pagamentos';

    public function handle(): int
    {
        $chunkSize = (int) $this->option('chunk');
        $dryRun = (bool) $this->option('dry-run');

        // Count rows to update
        $totalNull = DB::table('pdv_turno_pagamentos')
            ->whereNull('closure_uuid')
            ->count();

        $this->info("Rows with closure_uuid=NULL: {$totalNull}");

        if ($totalNull === 0) {
            $this->info('Nothing to do.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('Dry-run mode — no changes made.');
            return Command::SUCCESS;
        }

        $bar = $this->output->createProgressBar($totalNull);
        $bar->start();

        $updated = 0;

        // Process in chunks using a subquery-based UPDATE
        // This joins pdv_turno_pagamentos with pdv_turnos on (store_pdv_id, canal, id_turno)
        // and copies closure_uuid from the turno.
        do {
            $affected = DB::update("
                UPDATE pdv_turno_pagamentos p
                JOIN pdv_turnos t
                    ON p.store_pdv_id = t.store_pdv_id
                    AND p.canal = t.canal
                    AND p.id_turno = t.id_turno
                SET p.closure_uuid = t.closure_uuid
                WHERE p.closure_uuid IS NULL
                    AND t.closure_uuid IS NOT NULL
                LIMIT {$chunkSize}
            ");

            $updated += $affected;
            $bar->advance($affected);
        } while ($affected > 0);

        $bar->finish();
        $this->newLine();
        $this->info("Done. Updated {$updated} rows.");

        return Command::SUCCESS;
    }
}
