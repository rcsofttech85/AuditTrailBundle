<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

use function in_array;

final class AuditEntry
{
    public function __construct(
        private readonly AuditLog $log,
    ) {
    }

    public ?Uuid $id { get => $this->log->id; }

    public string $entityClass { get => $this->log->entityClass; }

    public string $entityShortName {
        get {
            $parts = explode('\\', $this->log->entityClass);
            $shortName = end($parts);

            return ($shortName !== '') ? $shortName : $this->log->entityClass;
        }
    }

    public string $entityId { get => $this->log->requireEntityId(); }

    public string $action { get => $this->log->action->value; }

    public ?string $userId { get => $this->log->userId; }

    public ?string $username { get => $this->log->username; }

    public ?string $ipAddress { get => $this->log->ipAddress; }

    public ?string $transactionHash { get => $this->log->transactionHash; }

    public ?string $userAgent { get => $this->log->userAgent; }

    public ?string $signature { get => $this->log->signature; }

    public DateTimeImmutable $createdAt { get => $this->log->createdAt; }

    public AuditLog $auditLog { get => $this->log; }

    public bool $isCreate { get => $this->log->action === AuditAction::Create; }

    public bool $isUpdate { get => $this->log->action === AuditAction::Update; }

    public bool $isDelete { get => $this->log->action === AuditAction::Delete; }

    public bool $isSoftDelete { get => $this->log->action === AuditAction::SoftDelete; }

    public bool $isRestore { get => $this->log->action === AuditAction::Restore; }

    /** @var array<string, array{old: mixed, new: mixed}> */
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

    /** @var array<int, string> */
    public array $changedFields { get => $this->log->changedFields ?? []; }

    public function hasFieldChanged(string $field): bool
    {
        $changedFields = $this->log->changedFields ?? [];

        return in_array($field, $changedFields, true);
    }

    public function getOldValue(string $field): mixed
    {
        $oldValues = $this->log->oldValues;

        return $oldValues[$field] ?? null;
    }

    public function getNewValue(string $field): mixed
    {
        $newValues = $this->log->newValues;

        return $newValues[$field] ?? null;
    }

    /** @var array<string, mixed>|null */
    public ?array $oldValues { get => $this->log->oldValues; }

    /** @var array<string, mixed>|null */
    public ?array $newValues { get => $this->log->newValues; }
}
