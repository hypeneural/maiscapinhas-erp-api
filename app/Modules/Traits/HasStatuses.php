<?php

declare(strict_types=1);

namespace App\Modules\Traits;

/**
 * Trait HasStatuses
 *
 * Provides default status management functionality for modules.
 * Use this trait when your module needs standard status handling.
 *
 * Usage:
 *   use HasStatuses;
 *   
 *   // Override if needed:
 *   protected array $statusColors = ['pending' => 'yellow', ...];
 */
trait HasStatuses
{
    /**
     * Default status configuration.
     * Override in your module to customize.
     */
    protected array $defaultStatuses = [
        1 => ['name' => 'pendente', 'label' => 'Pendente', 'color' => 'yellow', 'icon' => 'Clock', 'final' => false],
        2 => ['name' => 'em_andamento', 'label' => 'Em Andamento', 'color' => 'blue', 'icon' => 'RefreshCw', 'final' => false],
        3 => ['name' => 'concluido', 'label' => 'Concluído', 'color' => 'green', 'icon' => 'CheckCircle', 'final' => true],
        4 => ['name' => 'cancelado', 'label' => 'Cancelado', 'color' => 'red', 'icon' => 'XCircle', 'final' => true],
    ];

    /**
     * Default transitions.
     * Override in your module to customize.
     */
    protected array $defaultTransitions = [
        1 => [2, 4],  // Pendente → Em Andamento, Cancelado
        2 => [3, 4],  // Em Andamento → Concluído, Cancelado
        3 => [],      // Concluído (final)
        4 => [],      // Cancelado (final)
    ];

    /**
     * Get statuses - uses $statuses property if defined, otherwise defaults.
     */
    public function getStatuses(): array
    {
        return property_exists($this, 'statuses') ? $this->statuses : $this->defaultStatuses;
    }

    /**
     * Get transitions - uses $transitions property if defined, otherwise defaults.
     */
    public function getTransitions(): array
    {
        return property_exists($this, 'transitions') ? $this->transitions : $this->defaultTransitions;
    }

    /**
     * Get status by key.
     */
    public function getStatus(int $key): ?array
    {
        return $this->getStatuses()[$key] ?? null;
    }

    /**
     * Get status by name.
     */
    public function getStatusByName(string $name): ?array
    {
        foreach ($this->getStatuses() as $key => $status) {
            if ($status['name'] === $name) {
                return array_merge($status, ['key' => $key]);
            }
        }
        return null;
    }

    /**
     * Check if transition is allowed.
     */
    public function canTransition(int $from, int $to): bool
    {
        $transitions = $this->getTransitions();
        return isset($transitions[$from]) && in_array($to, $transitions[$from]);
    }

    /**
     * Get allowed transitions from a status.
     */
    public function getAllowedTransitions(int $from): array
    {
        return $this->getTransitions()[$from] ?? [];
    }

    /**
     * Get final statuses.
     */
    public function getFinalStatuses(): array
    {
        return array_filter($this->getStatuses(), fn($s) => $s['final'] ?? false);
    }

    /**
     * Check if status is final.
     */
    public function isFinalStatus(int $key): bool
    {
        $status = $this->getStatus($key);
        return $status['final'] ?? false;
    }
}
