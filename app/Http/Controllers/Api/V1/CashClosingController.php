<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use App\Models\CashClosing;
use App\Models\CashShift;
use App\Services\CashClosingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CashClosingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private CashClosingService $cashClosingService
    ) {
    }

    /**
     * Submit a cash closing for review.
     *
     * POST /api/v1/cash/closings/{shift}/submit
     */
    public function submit(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        try {
            $closing = $this->cashClosingService->submit($closing, $user);
            return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller']));
        } catch (ValidationException $e) {
            throw $e;
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Approve a submitted cash closing.
     *
     * POST /api/v1/cash/closings/{shift}/approve
     */
    public function approve(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Check if user can approve in this store
        if (!$user->canApproveInStore($shift->store_id)) {
            return $this->forbidden('You do not have permission to approve closings in this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        try {
            $closing = $this->cashClosingService->approve($closing, $user);
            return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller', 'closedByUser']));
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Reject a submitted cash closing.
     *
     * POST /api/v1/cash/closings/{shift}/reject
     */
    public function reject(Request $request, CashShift $shift): JsonResponse
    {
        $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        // Check if user can approve/reject in this store
        if (!$user->canApproveInStore($shift->store_id)) {
            return $this->forbidden('You do not have permission to reject closings in this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        try {
            $closing = $this->cashClosingService->reject($closing, $user, $request->input('reason'));
            return $this->success($closing->load(['lines', 'cashShift.store', 'cashShift.seller']));
        } catch (ConflictHttpException $e) {
            return $this->conflict($e->getMessage());
        }
    }

    /**
     * Get closing details.
     *
     * GET /api/v1/cash/closings/{shift}
     */
    public function show(Request $request, CashShift $shift): JsonResponse
    {
        $user = $request->user();

        if (!$user->hasAccessToStore($shift->store_id)) {
            return $this->forbidden('You do not have access to this store.');
        }

        $closing = $shift->cashClosing;

        if (!$closing) {
            return $this->notFound('No closing found for this shift.');
        }

        return $this->success($closing->load(['lines.divergence', 'cashShift.store', 'cashShift.seller', 'closedByUser']));
    }
}
