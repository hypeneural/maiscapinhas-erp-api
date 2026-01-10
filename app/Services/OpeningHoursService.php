<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonTimeZone;

/**
 * Service to calculate human-readable opening hours information.
 * 
 * Handles timezone-aware calculations for:
 * - Current open/closed status
 * - Human-readable labels
 * - Next change times
 */
class OpeningHoursService
{
    private const DAY_MAP = [
        0 => 'sun',
        1 => 'mon',
        2 => 'tue',
        3 => 'wed',
        4 => 'thu',
        5 => 'fri',
        6 => 'sat',
    ];

    private const DAY_LABELS = [
        'mon' => 'Seg',
        'tue' => 'Ter',
        'wed' => 'Qua',
        'thu' => 'Qui',
        'fri' => 'Sex',
        'sat' => 'Sáb',
        'sun' => 'Dom',
    ];

    private const DEFAULT_TIMEZONE = 'America/Sao_Paulo';

    /**
     * Calculate human-readable hours information for a store.
     *
     * @param array|null $openingHours The opening_hours JSON from the store
     * @return array The hours_human array for API response
     */
    public function calculate(?array $openingHours): array
    {
        // Handle null/invalid opening_hours
        if (!$openingHours || !isset($openingHours['weekly'])) {
            return $this->unknownStatus();
        }

        $timezone = $openingHours['tz'] ?? self::DEFAULT_TIMEZONE;
        
        try {
            $tz = new CarbonTimeZone($timezone);
            $now = Carbon::now($tz);
        } catch (\Exception $e) {
            return $this->unknownStatus();
        }

        $dayKey = self::DAY_MAP[$now->dayOfWeek];
        $todayIntervals = $this->getTodayIntervals($openingHours, $now, $dayKey);

        // Calculate current status
        $currentTime = $now->format('H:i');
        $isOpen = false;
        $closesAt = null;
        $opensAt = null;
        $currentInterval = null;

        foreach ($todayIntervals as $interval) {
            if (!isset($interval['start'], $interval['end'])) {
                continue;
            }
            
            if ($currentTime >= $interval['start'] && $currentTime < $interval['end']) {
                $isOpen = true;
                $closesAt = $interval['end'];
                $currentInterval = $interval;
                break;
            }
        }

        // If closed, find next opening time today
        if (!$isOpen) {
            foreach ($todayIntervals as $interval) {
                if (!isset($interval['start'])) {
                    continue;
                }
                if ($interval['start'] > $currentTime) {
                    $opensAt = $interval['start'];
                    break;
                }
            }
        }

        // Build status label
        $statusLabel = $this->buildStatusLabel($isOpen, $closesAt, $opensAt, $todayIntervals);
        
        // Build today hours label
        $todayHoursLabel = $this->buildTodayHoursLabel($todayIntervals);
        
        // Build weekly label
        $weeklyLabel = $this->buildWeeklyLabel($openingHours['weekly'] ?? []);

        // Calculate next change timestamp
        $nextChangeAt = $this->calculateNextChange($now, $isOpen, $closesAt, $opensAt, $timezone);

        return [
            'timezone' => $timezone,
            'is_open_now' => $isOpen,
            'status' => $isOpen ? 'open' : 'closed',
            'status_label' => $statusLabel,
            'today_hours_label' => $todayHoursLabel,
            'opens_at' => $opensAt,
            'closes_at' => $closesAt,
            'next_change_at' => $nextChangeAt,
            'weekly_label' => $weeklyLabel,
        ];
    }

    /**
     * Get today's intervals, considering exceptions.
     */
    private function getTodayIntervals(array $openingHours, Carbon $now, string $dayKey): array
    {
        $dateStr = $now->format('Y-m-d');
        
        // Check for exceptions first
        if (isset($openingHours['exceptions']) && is_array($openingHours['exceptions'])) {
            foreach ($openingHours['exceptions'] as $exception) {
                if (($exception['date'] ?? null) === $dateStr) {
                    if (!empty($exception['closed'])) {
                        return []; // Closed due to exception
                    }
                    if (isset($exception['hours']) && is_array($exception['hours'])) {
                        return $exception['hours'];
                    }
                }
            }
        }

        // Use weekly schedule
        return $openingHours['weekly'][$dayKey] ?? [];
    }

    /**
     * Build the status label.
     */
    private function buildStatusLabel(bool $isOpen, ?string $closesAt, ?string $opensAt, array $todayIntervals): string
    {
        if ($isOpen && $closesAt) {
            return "Aberto agora • Fecha às {$closesAt}";
        }

        if (!$isOpen && $opensAt) {
            return "Fechado • Abre às {$opensAt}";
        }

        if (!$isOpen && empty($todayIntervals)) {
            return "Fechado hoje";
        }

        if (!$isOpen) {
            return "Fechado • Não abre mais hoje";
        }

        return "Aberto agora";
    }

    /**
     * Build today's hours label.
     */
    private function buildTodayHoursLabel(array $intervals): string
    {
        if (empty($intervals)) {
            return "Hoje: Fechado";
        }

        $parts = [];
        foreach ($intervals as $interval) {
            if (isset($interval['start'], $interval['end'])) {
                $parts[] = "{$interval['start']}–{$interval['end']}";
            }
        }

        if (empty($parts)) {
            return "Hoje: Fechado";
        }

        return "Hoje: " . implode(', ', $parts);
    }

    /**
     * Build weekly summary label.
     */
    private function buildWeeklyLabel(array $weekly): string
    {
        if (empty($weekly)) {
            return "Horário não informado";
        }

        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $patterns = [];
        
        // Group days by their schedule pattern
        foreach ($days as $day) {
            $intervals = $weekly[$day] ?? [];
            $pattern = $this->intervalsToPattern($intervals);
            $patterns[$day] = $pattern;
        }

        // Group consecutive days with same pattern
        $groups = [];
        $currentGroup = null;
        $currentPattern = null;

        foreach ($days as $day) {
            $pattern = $patterns[$day];
            
            if ($currentPattern === null || $pattern !== $currentPattern) {
                if ($currentGroup !== null) {
                    $groups[] = $currentGroup;
                }
                $currentGroup = [
                    'days' => [$day],
                    'pattern' => $pattern,
                ];
                $currentPattern = $pattern;
            } else {
                $currentGroup['days'][] = $day;
            }
        }
        
        if ($currentGroup !== null) {
            $groups[] = $currentGroup;
        }

        // Build label from groups
        $parts = [];
        foreach ($groups as $group) {
            $dayRange = $this->formatDayRange($group['days']);
            $parts[] = "{$dayRange} {$group['pattern']}";
        }

        return implode(' | ', $parts);
    }

    /**
     * Convert intervals to a pattern string.
     */
    private function intervalsToPattern(array $intervals): string
    {
        if (empty($intervals)) {
            return "Fechado";
        }

        $parts = [];
        foreach ($intervals as $interval) {
            if (isset($interval['start'], $interval['end'])) {
                $parts[] = "{$interval['start']}–{$interval['end']}";
            }
        }

        return empty($parts) ? "Fechado" : implode(', ', $parts);
    }

    /**
     * Format a range of days (e.g., "Seg–Sex" or "Seg").
     */
    private function formatDayRange(array $days): string
    {
        if (count($days) === 1) {
            return self::DAY_LABELS[$days[0]] ?? $days[0];
        }

        $first = self::DAY_LABELS[$days[0]] ?? $days[0];
        $last = self::DAY_LABELS[end($days)] ?? end($days);
        
        return "{$first}–{$last}";
    }

    /**
     * Calculate the next status change timestamp.
     */
    private function calculateNextChange(
        Carbon $now,
        bool $isOpen,
        ?string $closesAt,
        ?string $opensAt,
        string $timezone
    ): ?string {
        try {
            if ($isOpen && $closesAt) {
                [$hour, $minute] = explode(':', $closesAt);
                $change = $now->copy()->setTime((int) $hour, (int) $minute, 0);
                return $change->toIso8601String();
            }

            if (!$isOpen && $opensAt) {
                [$hour, $minute] = explode(':', $opensAt);
                $change = $now->copy()->setTime((int) $hour, (int) $minute, 0);
                return $change->toIso8601String();
            }
        } catch (\Exception $e) {
            // Ignore parsing errors
        }

        return null;
    }

    /**
     * Return unknown status response.
     */
    private function unknownStatus(): array
    {
        return [
            'timezone' => self::DEFAULT_TIMEZONE,
            'is_open_now' => false,
            'status' => 'unknown',
            'status_label' => 'Horário não informado',
            'today_hours_label' => null,
            'opens_at' => null,
            'closes_at' => null,
            'next_change_at' => null,
            'weekly_label' => null,
        ];
    }

    /**
     * Validate opening hours structure.
     *
     * @param array $openingHours
     * @return array List of validation errors, empty if valid
     */
    public function validate(array $openingHours): array
    {
        $errors = [];

        if (!isset($openingHours['weekly'])) {
            $errors[] = 'Campo "weekly" é obrigatório';
            return $errors;
        }

        $validDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        
        foreach ($openingHours['weekly'] as $day => $intervals) {
            if (!in_array($day, $validDays)) {
                $errors[] = "Dia inválido: {$day}";
                continue;
            }

            if (!is_array($intervals)) {
                $errors[] = "Intervalos para {$day} devem ser um array";
                continue;
            }

            foreach ($intervals as $index => $interval) {
                $prefix = "Intervalo {$index} de {$day}";
                
                if (!isset($interval['start']) || !isset($interval['end'])) {
                    $errors[] = "{$prefix}: start e end são obrigatórios";
                    continue;
                }

                if (!preg_match('/^\d{2}:\d{2}$/', $interval['start'])) {
                    $errors[] = "{$prefix}: start deve estar no formato HH:MM";
                }

                if (!preg_match('/^\d{2}:\d{2}$/', $interval['end'])) {
                    $errors[] = "{$prefix}: end deve estar no formato HH:MM";
                }

                if ($interval['start'] >= $interval['end']) {
                    $errors[] = "{$prefix}: start deve ser menor que end";
                }
            }

            // Check for overlapping intervals
            $sortedIntervals = $intervals;
            usort($sortedIntervals, fn($a, $b) => ($a['start'] ?? '') <=> ($b['start'] ?? ''));
            
            for ($i = 1; $i < count($sortedIntervals); $i++) {
                $prev = $sortedIntervals[$i - 1];
                $curr = $sortedIntervals[$i];
                
                if (isset($prev['end'], $curr['start']) && $prev['end'] > $curr['start']) {
                    $errors[] = "Intervalos sobrepostos em {$day}";
                }
            }
        }

        return $errors;
    }
}
