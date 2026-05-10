<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

/**
 * Immutable filter state for AuditQuery.
 *
 * @internal
 */
final readonly class AuditQueryState
{
    public const int DEFAULT_LIMIT = 30;

    /**
     * @param list<AuditAction> $actions
     * @param list<string>      $changedFields
     */
    public function __construct(
        public ?string $entityClass = null,
        public ?string $entityId = null,
        /** @var list<AuditAction> */
        public array $actions = [],
        public ?string $userId = null,
        public ?string $transactionHash = null,
        public ?DateTimeInterface $since = null,
        public ?DateTimeInterface $until = null,
        /** @var list<string> */
        public array $changedFields = [],
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $afterId = null,
        public ?string $beforeId = null,
    ) {
    }

    public function hasChangedFieldFilter(): bool
    {
        return $this->changedFields !== [];
    }

    public function withEntity(string $class, ?string $id = null): self
    {
        return new self(
            entityClass: $class,
            entityId: $id,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withEntityId(string $id): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $id,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    /**
     * @param list<AuditAction> $actions
     */
    public function withActions(array $actions): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withUserId(string $userId): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withTransactionHash(string $transactionHash): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withSince(DateTimeInterface $since): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withUntil(DateTimeInterface $until): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    /**
     * @param list<string> $changedFields
     */
    public function withChangedFields(array $changedFields): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $changedFields,
            limit: $this->limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withLimit(int $limit): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $limit,
            afterId: $this->afterId,
            beforeId: $this->beforeId,
        );
    }

    public function withAfterId(string $afterId): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: $afterId,
            beforeId: null,
        );
    }

    public function withBeforeId(string $beforeId): self
    {
        return new self(
            entityClass: $this->entityClass,
            entityId: $this->entityId,
            actions: $this->actions,
            userId: $this->userId,
            transactionHash: $this->transactionHash,
            since: $this->since,
            until: $this->until,
            changedFields: $this->changedFields,
            limit: $this->limit,
            afterId: null,
            beforeId: $beforeId,
        );
    }
}
