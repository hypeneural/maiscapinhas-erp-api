<?php

declare(strict_types=1);

namespace App\Modules\Traits;

use App\Models\Module;
use Illuminate\Support\Facades\Auth;

/**
 * Trait HasAuditLog
 *
 * Provides audit logging functionality for modules.
 * Logs all changes made to module records.
 *
 * Usage:
 *   use HasAuditLog;
 *   
 *   // In your service:
 *   $this->module->logAction('status_changed', ['from' => 1, 'to' => 2], $record);
 */
trait HasAuditLog
{
    /**
     * Log an action for this module.
     */
    public function logAction(string $action, array $data = [], $record = null, $user = null): void
    {
        $user = $user ?? Auth::user();
        $module = $this->getDbModule();

        if (!$module) {
            return;
        }

        $auditLog = $module->audit_log ?? [];

        $entry = [
            'action' => $action,
            'data' => $data,
            'record_id' => $record?->id,
            'record_type' => $record ? get_class($record) : null,
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'Sistema',
            'user_email' => $user?->email,
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ];

        // Prepend to keep most recent first
        array_unshift($auditLog, $entry);

        // Keep only last 1000 entries
        $auditLog = array_slice($auditLog, 0, 1000);

        $module->update(['audit_log' => $auditLog]);
    }

    /**
     * Log status change.
     */
    public function logStatusChange($record, int $from, int $to, ?string $reason = null): void
    {
        $fromStatus = method_exists($this, 'getStatus') ? $this->getStatus($from) : null;
        $toStatus = method_exists($this, 'getStatus') ? $this->getStatus($to) : null;

        $this->logAction('status_changed', [
            'from' => $from,
            'from_label' => $fromStatus['label'] ?? 'Unknown',
            'to' => $to,
            'to_label' => $toStatus['label'] ?? 'Unknown',
            'reason' => $reason,
        ], $record);
    }

    /**
     * Log record creation.
     */
    public function logCreated($record): void
    {
        $this->logAction('record_created', [
            'fields' => $record->toArray(),
        ], $record);
    }

    /**
     * Log record update.
     */
    public function logUpdated($record, array $changes): void
    {
        $this->logAction('record_updated', [
            'changes' => $changes,
        ], $record);
    }

    /**
     * Log record deletion.
     */
    public function logDeleted($record): void
    {
        $this->logAction('record_deleted', [
            'deleted_data' => $record->toArray(),
        ], $record);
    }

    /**
     * Log config change.
     */
    public function logConfigChange(array $old, array $new): void
    {
        $this->logAction('config_updated', [
            'old' => $old,
            'new' => $new,
            'changed_keys' => array_keys(array_diff_assoc($new, $old)),
        ]);
    }

    /**
     * Log text override change.
     */
    public function logTextsChange(array $changes): void
    {
        $this->logAction('texts_updated', [
            'changes' => $changes,
        ]);
    }

    /**
     * Get audit log entries.
     */
    public function getAuditLog(int $limit = 50): array
    {
        $module = $this->getDbModule();
        if (!$module) {
            return [];
        }

        $log = $module->audit_log ?? [];
        return array_slice($log, 0, $limit);
    }

    /**
     * Get audit log for specific record.
     */
    public function getRecordAuditLog($record, int $limit = 20): array
    {
        $module = $this->getDbModule();
        if (!$module) {
            return [];
        }

        $log = $module->audit_log ?? [];
        $filtered = array_filter(
            $log,
            fn($entry) =>
            isset($entry['record_id']) && $entry['record_id'] == $record->id
        );

        return array_slice(array_values($filtered), 0, $limit);
    }

    /**
     * Get database module model.
     */
    protected function getDbModule(): ?Module
    {
        return Module::find($this->getId());
    }

    /**
     * Clear old audit log entries.
     */
    public function pruneAuditLog(int $keepDays = 90): int
    {
        $module = $this->getDbModule();
        if (!$module) {
            return 0;
        }

        $log = $module->audit_log ?? [];
        $cutoff = now()->subDays($keepDays);

        $pruned = array_filter($log, function ($entry) use ($cutoff) {
            $timestamp = $entry['timestamp'] ?? null;
            return $timestamp && now()->parse($timestamp)->isAfter($cutoff);
        });

        $removed = count($log) - count($pruned);
        $module->update(['audit_log' => array_values($pruned)]);

        return $removed;
    }
}
