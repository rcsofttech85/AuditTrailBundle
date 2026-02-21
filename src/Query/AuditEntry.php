<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Uid\Uuid;

use function in_array;

/**
 * Rich value object wrapping an AuditLog with diff helpers.
 *
 * Provides a developer-friendly interface for accessing audit data
 * and computing field-level differences.
 */
class AuditEntry
{
    public function __construct(
        private readonly AuditLog $log,
    ) {
    }

    public ?Uuid $id { get => $this->log->id; }

    public string $entityClass { get => $this->log->entityClass; }

    /**
     * Get the short entity class name (without namespace).
     */
    public string $entityShortName {
        get {
            $parts = explode('\\', $this->log->entityClass);
            $shortName = end($parts);

            return ($shortName !== '') ? $shortName : $this->log->entityClass;
        }
    }

    public string $entityId { get => $this->log->entityId; }

    public string $action { get => $this->log->action; }

    public ?string $userId { get => $this->log->userId; }

    public ?string $username { get => $this->log->username; }

    public ?string $ipAddress { get => $this->log->ipAddress; }

    public ?string $transactionHash { get => $this->log->transactionHash; }

    public ?string $userAgent { get => $this->log->userAgent; }

    public ?string $signature { get => $this->log->signature; }

    public DateTimeImmutable $createdAt { get => $this->log->createdAt; }

    /**
     * The underlying AuditLog entity.
     */
    public AuditLog $auditLog { get => $this->log; }

    // ========== Action Helpers ==========

    public bool $isCreate { get => $this->log->action === 'create'; }

    public bool $isUpdate { get => $this->log->action === 'update'; }

    public bool $isDelete { get => $this->log->action === 'delete'; }

    public bool $isSoftDelete { get => $this->log->action === 'soft_delete'; }

    public bool $isRestore { get => $this->log->action === 'restore'; }

    // ========== Diff Helpers ==========

    /**
     * All changed fields with their old and new values.
     *
     * @var array<string, array{old: mixed, new: mixed}>
     */
    public array $diff {
        get {
            $oldValues = $this->log->oldValues ?? [];
            $newValues = $this->log->newValues ?? [];
            $changedFields = $this->log->changedFields ?? array_keys($newValues);

            $diff = [];
            foreach ($changedFields as $field) {
                $diff[$field] = [
                    'old' => $oldValues[$field] ?? null,
                    'new' => $newValues[$field] ?? null,
                ];
            }

            return $diff;
        }
    }

    /**
     * List of fields that changed.
     *
     * @var array<int, string>
     */
    public array $changedFields { get => $this->log->changedFields ?? []; }

    /**
     * Check if a specific field was changed.
     */
    public function hasFieldChanged(string $field): bool
    {
        $changedFields = $this->log->changedFields ?? [];

        return in_array($field, $changedFields, true);
    }

    /**
     * Get the old value of a specific field.
     */
    public function getOldValue(string $field): mixed
    {
        $oldValues = $this->log->oldValues;

        return $oldValues[$field] ?? null;
    }

    /**
     * Get the new value of a specific field.
     */
    public function getNewValue(string $field): mixed
    {
        $newValues = $this->log->newValues;

        return $newValues[$field] ?? null;
    }

    /**
     * All old values.
     *
     * @var array<string, mixed>|null
     */
    public ?array $oldValues { get => $this->log->oldValues; }

    /**
     * All new values.
     *
     * @var array<string, mixed>|null
     */
    public ?array $newValues { get => $this->log->newValues; }
}
