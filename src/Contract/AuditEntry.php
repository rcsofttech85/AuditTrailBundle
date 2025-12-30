<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

/**
 * Rich value object wrapping an AuditLog with diff helpers.
 *
 * Provides a developer-friendly interface for accessing audit data
 * and computing field-level differences.
 */
readonly class AuditEntry
{
    public function __construct(
        private AuditLogInterface $log,
    ) {
    }

    public function getId(): ?int
    {
        return $this->log->getId();
    }

    public function getEntityClass(): string
    {
        return $this->log->getEntityClass();
    }

    /**
     * Get the short entity class name (without namespace).
     */
    public function getEntityShortName(): string
    {
        $parts = explode('\\', $this->log->getEntityClass());

        $shortName = end($parts);

        return ('' !== $shortName) ? $shortName : $this->log->getEntityClass();
    }

    public function getEntityId(): string
    {
        return $this->log->getEntityId();
    }

    public function getAction(): string
    {
        return $this->log->getAction();
    }

    public function getUserId(): ?int
    {
        return $this->log->getUserId();
    }

    public function getUsername(): ?string
    {
        return $this->log->getUsername();
    }

    public function getIpAddress(): ?string
    {
        return $this->log->getIpAddress();
    }

    public function getTransactionHash(): ?string
    {
        return $this->log->getTransactionHash();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->log->getCreatedAt();
    }

    /**
     * Get the underlying AuditLog entity.
     */
    public function getAuditLog(): AuditLogInterface
    {
        return $this->log;
    }

    // ========== Action Helpers ==========

    public function isCreate(): bool
    {
        return AuditLogInterface::ACTION_CREATE === $this->log->getAction();
    }

    public function isUpdate(): bool
    {
        return AuditLogInterface::ACTION_UPDATE === $this->log->getAction();
    }

    public function isDelete(): bool
    {
        return AuditLogInterface::ACTION_DELETE === $this->log->getAction();
    }

    public function isSoftDelete(): bool
    {
        return AuditLogInterface::ACTION_SOFT_DELETE === $this->log->getAction();
    }

    public function isRestore(): bool
    {
        return AuditLogInterface::ACTION_RESTORE === $this->log->getAction();
    }

    // ========== Diff Helpers ==========

    /**
     * Get all changed fields with their old and new values.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function getDiff(): array
    {
        $oldValues = $this->log->getOldValues() ?? [];
        $newValues = $this->log->getNewValues() ?? [];
        $changedFields = $this->log->getChangedFields() ?? array_keys($newValues);

        $diff = [];
        foreach ($changedFields as $field) {
            $diff[$field] = [
                'old' => $oldValues[$field] ?? null,
                'new' => $newValues[$field] ?? null,
            ];
        }

        return $diff;
    }

    /**
     * Get list of fields that changed.
     *
     * @return array<int, string>
     */
    public function getChangedFields(): array
    {
        return $this->log->getChangedFields() ?? [];
    }

    /**
     * Check if a specific field was changed.
     */
    public function hasFieldChanged(string $field): bool
    {
        $changedFields = $this->log->getChangedFields() ?? [];

        return \in_array($field, $changedFields, true);
    }

    /**
     * Get the old value of a specific field.
     */
    public function getOldValue(string $field): mixed
    {
        $oldValues = $this->log->getOldValues();

        return $oldValues[$field] ?? null;
    }

    /**
     * Get the new value of a specific field.
     */
    public function getNewValue(string $field): mixed
    {
        $newValues = $this->log->getNewValues();

        return $newValues[$field] ?? null;
    }

    /**
     * Get all old values.
     *
     * @return array<string, mixed>|null
     */
    public function getOldValues(): ?array
    {
        return $this->log->getOldValues();
    }

    /**
     * Get all new values.
     *
     * @return array<string, mixed>|null
     */
    public function getNewValues(): ?array
    {
        return $this->log->getNewValues();
    }
}
