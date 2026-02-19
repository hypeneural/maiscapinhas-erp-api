<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CashClosing;
use App\Models\CashClosingLine;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashClosingService
{
    /**
     * Submit a cash closing for review.
     *
     * @throws ValidationException
     * @throws ConflictHttpException
     */
    public function submit(CashClosing $closing, User $submittedBy): CashClosing
    {
        return DB::transaction(function () use ($closing, $submittedBy) {
            // Lock the closing for update
            $closing = CashClosing::lockForUpdate()->find($closing->id);

            // Validate status
            if (!$closing->canBeSubmitted()) {
                throw new ConflictHttpException(
                    "Cannot submit closing with status '{$closing->status}'. Must be 'draft' or 'rejected'."
                );
            }

            // Recalculate diff values
            $this->recalculateDiffs($closing);
            $totalDiff = $closing->getTotalDifference();

            // CENÁRIO A: Auto-Aprovação (Diferença Zero)
            if (abs($totalDiff) < 0.01) {
                // Se não houver divergência, aprovamos automaticamente
                $closing->justified = true;
                $closing->status = CashClosing::STATUS_SUBMITTED; // Fix: Must be submitted to be approved
                $closing->save();

                return $this->approve($closing, $submittedBy);
            }

            // CENÁRIO B/C: Divergência
            // Exige justificativa obrigatória
            if (!$closing->justification_text && !$closing->areDivergentLinesJustified()) {
                // Fallback check: ensure strictly one form of justification exists
                // Note: hasJustifiedLines() must be implemented or verified. 
                // Assuming checking justification_text first.
                // If the logic requires justification text on the closing header for ANY divergence:
                throw ValidationException::withMessages([
                    'justification_text' => ['A justificativa é obrigatória quando há divergência de valores.']
                ]);
            }

            // Update status to SUBMITTED (Awaiting Manager)
            $beforeStatus = $closing->status;
            $closing->status = CashClosing::STATUS_SUBMITTED;
            $closing->version++;
            $closing->save();

            // Update shift status
            $closing->cashShift->update(['status' => CashShift::STATUS_PENDING]);

            // Log audit
            AuditLog::logSubmit($closing, [
                'status' => $closing->status,
                'version' => $closing->version,
                'submitted_by' => $submittedBy->id,
                'previous_status' => $beforeStatus,
                'total_diff' => $totalDiff
            ]);

            return $closing->fresh(['lines']);
        });
    }

    /**
     * Approve a submitted cash closing.
     *
     * @throws ConflictHttpException
     */
    public function approve(CashClosing $closing, User $approvedBy): CashClosing
    {
        return DB::transaction(function () use ($closing, $approvedBy) {
            $closing = CashClosing::lockForUpdate()->find($closing->id);

            if (!$closing->canBeApproved()) {
                throw new ConflictHttpException(
                    "Cannot approve closing with status '{$closing->status}'. Must be 'submitted'."
                );
            }

            $closing->status = CashClosing::STATUS_APPROVED;
            $closing->closed_by = $approvedBy->id;
            $closing->closed_at = now();
            $closing->version++;
            $closing->save();

            // Update shift status
            $closing->cashShift->update(['status' => CashShift::STATUS_CLOSED]);

            // Log audit
            AuditLog::logApprove($closing, [
                'status' => $closing->status,
                'version' => $closing->version,
                'approved_by' => $approvedBy->id,
                'closed_at' => $closing->closed_at->toIso8601String(),
            ]);

            return $closing->fresh(['lines', 'closedByUser']);
        });
    }

    /**
     * Reject a submitted cash closing.
     *
     * @throws ConflictHttpException
     */
    public function reject(CashClosing $closing, User $rejectedBy, string $reason): CashClosing
    {
        return DB::transaction(function () use ($closing, $rejectedBy, $reason) {
            $closing = CashClosing::lockForUpdate()->find($closing->id);

            if (!$closing->canBeRejected()) {
                throw new ConflictHttpException(
                    "Cannot reject closing with status '{$closing->status}'. Must be 'submitted'."
                );
            }

            $closing->status = CashClosing::STATUS_REJECTED;
            $closing->version++;
            $closing->save();

            // Update shift status back to open
            $closing->cashShift->update(['status' => CashShift::STATUS_OPEN]);

            // Log audit with reason
            AuditLog::logReject($closing, [
                'status' => $closing->status,
                'version' => $closing->version,
                'rejected_by' => $rejectedBy->id,
                'reason' => $reason,
            ]);

            return $closing->fresh(['lines']);
        });
    }

    /**
     * Create a new cash closing with lines.
     *
     * @param CashShift $shift The shift to create closing for
     * @param array $lines Array of line data (label, system_value, real_value)
     * @param string|null $justificationText Optional justification text for the entire shift
     * @param bool $justified Whether the divergence (if any) is justified
     */
    public function createWithLines(
        CashShift $shift,
        array $lines,
        ?string $justificationText = null,
        bool $justified = false
    ): CashClosing {
        return DB::transaction(function () use ($shift, $lines, $justificationText, $justified) {
            $closing = CashClosing::create([
                'cash_shift_id' => $shift->id,
                'status' => CashClosing::STATUS_DRAFT,
                'version' => 1,
                'justification_text' => $justificationText,
                'justified' => $justified,
            ]);

            $this->createLines($closing, $lines);

            return $closing->fresh(['lines']);
        });
    }

    /**
     * Update an existing cash closing with lines.
     *
     * @throws ConflictHttpException
     */
    public function updateWithLines(
        CashClosing $closing,
        array $lines,
        ?string $justificationText = null,
        bool $justified = false
    ): CashClosing {
        return DB::transaction(function () use ($closing, $lines, $justificationText, $justified) {
            $closing = CashClosing::lockForUpdate()->find($closing->id);

            // Can only update if draft or rejected
            if (!$closing->canBeSubmitted()) {
                throw new ConflictHttpException(
                    "Cannot update closing with status '{$closing->status}'. Must be 'draft' or 'rejected'."
                );
            }

            // Update closing fields
            $closing->justification_text = $justificationText;
            $closing->justified = $justified;
            $closing->version++;
            $closing->save();

            // Delete existing lines and recreate
            $closing->lines()->delete();
            $this->createLines($closing, $lines);

            return $closing->fresh(['lines']);
        });
    }

    /**
     * Create lines for a closing.
     */
    private function createLines(CashClosing $closing, array $lines): void
    {
        foreach ($lines as $lineData) {
            $systemValue = $lineData['system_value'] ?? 0;
            $realValue = $lineData['real_value'] ?? 0;

            CashClosingLine::create([
                'cash_closing_id' => $closing->id,
                'label' => $lineData['label'],
                'system_value' => $systemValue,
                'real_value' => $realValue,
                'diff_value' => bcsub((string) $realValue, (string) $systemValue, 2),
            ]);
        }
    }

    /**
     * Recalculate all diff values for a closing's lines.
     */
    private function recalculateDiffs(CashClosing $closing): void
    {
        foreach ($closing->lines as $line) {
            $line->calculateDiff();
            $line->save();
        }
    }
}
