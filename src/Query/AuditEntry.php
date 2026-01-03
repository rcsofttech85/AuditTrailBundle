<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;

/**
 * Rich value object wrapping an AuditLog with diff helpers.
 *
 * Provides a developer-friendly interface for accessing audit data
 * and computing field-level differences.
 */
class AuditEntry
{
    public function __construct(
        private readonly AuditLogInterface $log,
    ) {
    }

    public ?int $id {
        get {
            return $this->log->getId();
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public string $entityClass {
        get {
            return $this->log->getEntityClass();
        }
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    /**
     * Get the short entity class name (without namespace).
     */
    public string $entityShortName {
        get {
            $parts = explode('\\', $this->log->getEntityClass());
            $shortName = end($parts);

            return ('' !== $shortName) ? $shortName : $this->log->getEntityClass();
        }
    }

    public function getEntityShortName(): string
    {
        return $this->entityShortName;
    }

    public string $entityId {
        get {
            return $this->log->getEntityId();
        }
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public string $action {
        get {
            return $this->log->getAction();
        }
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public ?int $userId {
        get {
            return $this->log->getUserId();
        }
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public ?string $username {
        get {
            return $this->log->getUsername();
        }
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public ?string $ipAddress {
        get {
            return $this->log->getIpAddress();
        }
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public ?string $transactionHash {
        get {
            return $this->log->getTransactionHash();
        }
    }

    public function getTransactionHash(): ?string
    {
        return $this->transactionHash;
    }

    public \DateTimeImmutable $createdAt {
        get {
            return $this->log->getCreatedAt();
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @var array<string, mixed>
     */
    public array $context {
        get {
            return $this->log->getContext();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
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
