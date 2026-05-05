<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use DateTimeInterface;
use InvalidArgumentException;
use LogicException;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Symfony\Component\Uid\Uuid;

use function array_values;
use function sprintf;

/**
 * Fluent, immutable query builder for audit logs.
 *
 * Each method returns a new instance, preserving immutability.
 * Execute the query with getResults(), count(), or getFirstResult().
 *
 * Uses keyset (cursor) pagination for efficient large dataset traversal.
 */
final class AuditQuery
{
    public function __construct(
        private readonly AuditQueryExecutor $executor,
        private readonly AuditQueryState $state = new AuditQueryState(),
    ) {
    }

    private ?AuditQueryPage $page = null;

    /**
     * Filter by entity class and optional ID.
     */
    public function entity(string $class, ?string $id = null): self
    {
        return new self($this->executor, $this->state->withEntity($class, $id));
    }

    /**
     * Filter by entity ID (requires entity class to be set).
     */
    public function entityId(string $id): self
    {
        return new self($this->executor, $this->state->withEntityId($id));
    }

    /**
     * Filter by one or more action types.
     */
    public function action(AuditAction|string ...$actions): self
    {
        return new self(
            $this->executor,
            $this->state->withActions(array_values(array_map(AuditAction::fromScalar(...), $actions))),
        );
    }

    /**
     * Filter for create actions only.
     */
    public function creates(): self
    {
        return $this->action(AuditAction::Create);
    }

    /**
     * Filter for update actions only.
     */
    public function updates(): self
    {
        return $this->action(AuditAction::Update);
    }

    /**
     * Filter for delete actions only.
     */
    public function deletes(): self
    {
        return $this->action(AuditAction::Delete, AuditAction::SoftDelete);
    }

    /**
     * Filter by user ID.
     */
    public function user(string $userId): self
    {
        return new self($this->executor, $this->state->withUserId($userId));
    }

    /**
     * Filter by transaction hash.
     */
    public function transaction(string $hash): self
    {
        return new self($this->executor, $this->state->withTransactionHash($hash));
    }

    /**
     * Filter for logs created on or after the given date.
     */
    public function since(DateTimeInterface $from): self
    {
        return new self($this->executor, $this->state->withSince($from));
    }

    /**
     * Filter for logs created on or before the given date.
     */
    public function until(DateTimeInterface $to): self
    {
        return new self($this->executor, $this->state->withUntil($to));
    }

    /**
     * Filter for logs within a date range.
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return $this->since($from)->until($to);
    }

    /**
     * Filter for logs that changed specific fields.
     */
    public function changedField(string ...$fields): self
    {
        if ($fields !== [] && $this->state->beforeId !== null) {
            throw new LogicException('Reverse pagination with changedField() is not supported.');
        }

        return new self($this->executor, $this->state->withChangedFields(array_values($fields)));
    }

    /**
     * Limit the number of results.
     */
    public function limit(int $limit): self
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Limit must be greater than zero.');
        }

        return new self($this->executor, $this->state->withLimit($limit));
    }

    /**
     * Keyset pagination: Get results after a specific audit log ID (UUID string).
     */
    public function after(string $id): self
    {
        $this->assertValidCursor($id);

        return new self($this->executor, $this->state->withAfterId($id));
    }

    /**
     * Keyset pagination: Get results before a specific audit log ID (UUID string).
     */
    public function before(string $id): self
    {
        if ($this->state->changedFields !== []) {
            throw new LogicException('Reverse pagination with changedField() is not supported.');
        }

        $this->assertValidCursor($id);

        return new self($this->executor, $this->state->withBeforeId($id));
    }

    private function assertValidCursor(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw new InvalidArgumentException(sprintf('Invalid audit cursor "%s". Expected a UUID.', $id));
        }
    }

    /**
     * Execute the query and return results.
     */
    public function getResults(): AuditEntryCollection
    {
        return $this->getPage()->entries;
    }

    /**
     * Execute the query and return a materialized page with its next cursor.
     */
    public function getPage(): AuditQueryPage
    {
        return $this->page ??= $this->executor->getPage($this->state);
    }

    /**
     * Count matching results.
     */
    public function count(): int
    {
        return $this->executor->count($this->state);
    }

    /**
     * Get the first result or null.
     */
    public function getFirstResult(): ?AuditEntry
    {
        return $this->executor->getFirstResult($this->state);
    }

    /**
     * Check if any results exist.
     */
    public function exists(): bool
    {
        return $this->executor->exists($this->state);
    }

    /**
     * Get the cursor (last ID) for pagination.
     */
    public function getNextCursor(): ?string
    {
        return $this->getPage()->nextCursor;
    }
}
