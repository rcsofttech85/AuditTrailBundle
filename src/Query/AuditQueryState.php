<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

use function array_key_exists;

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
        public array $actions = [],
        public ?string $userId = null,
        public ?string $transactionHash = null,
        public ?DateTimeInterface $since = null,
        public ?DateTimeInterface $until = null,
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
        return $this->copy([
            'entityClass' => $class,
            'entityId' => $id,
        ]);
    }

    public function withEntityId(string $id): self
    {
        return $this->copy([
            'entityId' => $id,
        ]);
    }

    /**
     * @param list<AuditAction> $actions
     */
    public function withActions(array $actions): self
    {
        return $this->copy([
            'actions' => $actions,
        ]);
    }

    public function withUserId(string $userId): self
    {
        return $this->copy([
            'userId' => $userId,
        ]);
    }

    public function withTransactionHash(string $transactionHash): self
    {
        return $this->copy([
            'transactionHash' => $transactionHash,
        ]);
    }

    public function withSince(DateTimeInterface $since): self
    {
        return $this->copy([
            'since' => $since,
        ]);
    }

    public function withUntil(DateTimeInterface $until): self
    {
        return $this->copy([
            'until' => $until,
        ]);
    }

    /**
     * @param list<string> $changedFields
     */
    public function withChangedFields(array $changedFields): self
    {
        return $this->copy([
            'changedFields' => $changedFields,
        ]);
    }

    public function withLimit(int $limit): self
    {
        return $this->copy([
            'limit' => $limit,
        ]);
    }

    public function withAfterId(string $afterId): self
    {
        return $this->copy([
            'afterId' => $afterId,
            'beforeId' => null,
        ]);
    }

    public function withBeforeId(string $beforeId): self
    {
        return $this->copy([
            'afterId' => null,
            'beforeId' => $beforeId,
        ]);
    }

    /**
     * @param array{
     *     entityClass?: ?string,
     *     entityId?: ?string,
     *     actions?: list<AuditAction>,
     *     userId?: ?string,
     *     transactionHash?: ?string,
     *     since?: ?DateTimeInterface,
     *     until?: ?DateTimeInterface,
     *     changedFields?: list<string>,
     *     limit?: int,
     *     afterId?: ?string,
     *     beforeId?: ?string
     * } $overrides
     */
    private function copy(array $overrides): self
    {
        return new self(
            entityClass: $this->value('entityClass', $this->entityClass, $overrides),
            entityId: $this->value('entityId', $this->entityId, $overrides),
            actions: $this->value('actions', $this->actions, $overrides),
            userId: $this->value('userId', $this->userId, $overrides),
            transactionHash: $this->value('transactionHash', $this->transactionHash, $overrides),
            since: $this->value('since', $this->since, $overrides),
            until: $this->value('until', $this->until, $overrides),
            changedFields: $this->value('changedFields', $this->changedFields, $overrides),
            limit: $this->value('limit', $this->limit, $overrides),
            afterId: $this->value('afterId', $this->afterId, $overrides),
            beforeId: $this->value('beforeId', $this->beforeId, $overrides),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function value(string $key, mixed $current, array $overrides): mixed
    {
        return array_key_exists($key, $overrides) ? $overrides[$key] : $current;
    }
}
