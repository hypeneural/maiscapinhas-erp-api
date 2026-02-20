<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BonusRule;
use App\Models\CommissionRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RulesService
{
    /**
     * Create a new bonus rule with versioning.
     */
    public function createBonusRule(array $data, User $actor): BonusRule
    {
        return DB::transaction(function () use ($data, $actor) {
            $version = $this->getNextBonusVersion($data['store_id'] ?? null);

            $rule = BonusRule::create([
                'store_id' => $data['store_id'] ?? null,
                'effective_from' => $data['effective_from'],
                'config_json' => $this->sortTiers($data['config_json'], 'min_sales'),
                'version' => $version,
            ]);

            AuditLog::log('bonus_rule.created', $rule, null, [
                'store_id' => $rule->store_id,
                'effective_from' => $rule->effective_from,
                'version' => $version,
            ], $actor->id);

            return $rule;
        });
    }

    /**
     * Update a bonus rule (creates new version).
     */
    public function updateBonusRule(BonusRule $rule, array $data, User $actor): BonusRule
    {
        return DB::transaction(function () use ($rule, $data, $actor) {
            $before = $rule->toArray();
            $version = $this->getNextBonusVersion($rule->store_id);

            $rule->update([
                'effective_from' => $data['effective_from'] ?? $rule->effective_from,
                'config_json' => isset($data['config_json'])
                    ? $this->sortTiers($data['config_json'], 'min_sales')
                    : $rule->config_json,
                'version' => $version,
            ]);

            AuditLog::log('bonus_rule.updated', $rule, $before, $rule->toArray(), $actor->id);

            return $rule->fresh();
        });
    }

    /**
     * Create a new commission rule with versioning.
     */
    public function createCommissionRule(array $data, User $actor): CommissionRule
    {
        return DB::transaction(function () use ($data, $actor) {
            $version = $this->getNextCommissionVersion($data['store_id'] ?? null);

            $rule = CommissionRule::create([
                'store_id' => $data['store_id'] ?? null,
                'effective_from' => $data['effective_from'],
                'config_json' => $this->sortTiers($data['config_json'], 'min_attainment'),
                'version' => $version,
            ]);

            AuditLog::log('commission_rule.created', $rule, null, [
                'store_id' => $rule->store_id,
                'effective_from' => $rule->effective_from,
                'version' => $version,
            ], $actor->id);

            return $rule;
        });
    }

    /**
     * Update a commission rule (creates new version).
     */
    public function updateCommissionRule(CommissionRule $rule, array $data, User $actor): CommissionRule
    {
        return DB::transaction(function () use ($rule, $data, $actor) {
            $before = $rule->toArray();
            $version = $this->getNextCommissionVersion($rule->store_id);

            $rule->update([
                'effective_from' => $data['effective_from'] ?? $rule->effective_from,
                'config_json' => isset($data['config_json'])
                    ? $this->sortTiers($data['config_json'], 'min_attainment')
                    : $rule->config_json,
                'version' => $version,
            ]);

            AuditLog::log('commission_rule.updated', $rule, $before, $rule->toArray(), $actor->id);

            return $rule->fresh();
        });
    }

    /**
     * Get applicable bonus rule for a store and date.
     */
    public function getApplicableBonusRule(?int $storeId, Carbon $date): ?BonusRule
    {
        // Try store-specific first
        if ($storeId) {
            $storeRule = BonusRule::where('store_id', $storeId)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();

            if ($storeRule) {
                return $storeRule;
            }
        }

        // Fall back to global
        return BonusRule::whereNull('store_id')
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Get applicable commission rule for a store and month.
     */
    public function getApplicableCommissionRule(?int $storeId, string $month): ?CommissionRule
    {
        $date = Carbon::parse($month . '-01');

        if ($storeId) {
            $storeRule = CommissionRule::where('store_id', $storeId)
                ->where('effective_from', '<=', $date)
                ->orderByDesc('effective_from')
                ->orderByDesc('version')
                ->first();

            if ($storeRule) {
                return $storeRule;
            }
        }

        return CommissionRule::whereNull('store_id')
            ->where('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Calculate bonus amount from rule and sales.
     */
    public function calculateBonusFromRule(BonusRule $rule, float $salesTotal): float
    {
        $tiers = $rule->config_json;
        $bonus = 0;

        foreach ($tiers as $tier) {
            if ($salesTotal >= $tier['min_sales']) {
                $bonus = (float) $tier['bonus'];
            }
        }

        return $bonus;
    }

    /**
     * Calculate commission rate from rule and attainment.
     */
    public function calculateCommissionRateFromRule(CommissionRule $rule, float $attainmentPercent): float
    {
        $tiers = $rule->config_json;
        $rate = 0;

        foreach ($tiers as $tier) {
            if ($attainmentPercent >= $tier['min_attainment']) {
                $rate = (float) $tier['rate'];
            }
        }

        return $rate;
    }

    private function getNextBonusVersion(?int $storeId): int
    {
        $query = BonusRule::query();

        if ($storeId) {
            $query->where('store_id', $storeId);
        } else {
            $query->whereNull('store_id');
        }

        return ($query->max('version') ?? 0) + 1;
    }

    private function getNextCommissionVersion(?int $storeId): int
    {
        $query = CommissionRule::query();

        if ($storeId) {
            $query->where('store_id', $storeId);
        } else {
            $query->whereNull('store_id');
        }

        return ($query->max('version') ?? 0) + 1;
    }

    private function sortTiers(array $tiers, string $key): array
    {
        usort($tiers, fn($a, $b) => $a[$key] <=> $b[$key]);
        return $tiers;
    }
}
