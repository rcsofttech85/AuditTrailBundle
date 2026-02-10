<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Query;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function count;

/**
 * Iterable collection of AuditEntry objects with utility methods.
 *
 * @implements IteratorAggregate<int, AuditEntry>
 */
readonly class AuditEntryCollection implements IteratorAggregate, Countable
{
    /**
     * @param list<AuditEntry> $entries
     */
    public function __construct(
        private array $entries,
    ) {
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function first(): ?AuditEntry
    {
        return $this->entries[0] ?? null;
    }

    public function last(): ?AuditEntry
    {
        if ($this->entries === []) {
            return null;
        }

        return $this->entries[count($this->entries) - 1];
    }

    /**
     * Filter entries using a predicate.
     *
     * @param callable(AuditEntry): bool $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->entries, $predicate)));
    }

    /**
     * Map entries to a new array.
     *
     * @template T
     *
     * @param callable(AuditEntry): T $callback
     *
     * @return array<int, T>
     */
    public function map(callable $callback): array
    {
        return array_map($callback, $this->entries);
    }

    /**
     * Group entries by action type.
     *
     * @return array<string, list<AuditEntry>>
     */
    public function groupByAction(): array
    {
        $grouped = [];
        foreach ($this->entries as $entry) {
            $action = $entry->getAction();
            if (!isset($grouped[$action])) {
                $grouped[$action] = [];
            }
            $grouped[$action][] = $entry;
        }

        return $grouped;
    }

    /**
     * Group entries by entity class.
     *
     * @return array<string, list<AuditEntry>>
     */
    public function groupByEntity(): array
    {
        $grouped = [];
        foreach ($this->entries as $entry) {
            $entityClass = $entry->getEntityClass();
            if (!isset($grouped[$entityClass])) {
                $grouped[$entityClass] = [];
            }
            $grouped[$entityClass][] = $entry;
        }

        return $grouped;
    }

    /**
     * Group entries by entity ID.
     *
     * @return array<string, list<AuditEntry>>
     */
    public function groupByEntityId(): array
    {
        $grouped = [];
        foreach ($this->entries as $entry) {
            $key = $entry->getEntityClass().':'.$entry->getEntityId();
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $entry;
        }

        return $grouped;
    }

    /**
     * Get only create actions.
     */
    public function getCreates(): self
    {
        return $this->filter(fn (AuditEntry $e) => $e->isCreate());
    }

    /**
     * Get only update actions.
     */
    public function getUpdates(): self
    {
        return $this->filter(fn (AuditEntry $e) => $e->isUpdate());
    }

    /**
     * Get only delete actions.
     */
    public function getDeletes(): self
    {
        return $this->filter(fn (AuditEntry $e) => $e->isDelete() || $e->isSoftDelete());
    }

    /**
     * Convert to array.
     *
     * @return list<AuditEntry>
     */
    public function toArray(): array
    {
        return $this->entries;
    }

    /**
     * Find the first entry matching a predicate.
     *
     * @param callable(AuditEntry): bool $predicate
     */
    public function find(callable $predicate): ?AuditEntry
    {
        return array_find($this->entries, $predicate);
    }

    /**
     * Check if any entry matches a predicate.
     *
     * @param callable(AuditEntry): bool $predicate
     */
    public function any(callable $predicate): bool
    {
        return array_any($this->entries, $predicate);
    }

    /**
     * Check if all entries match a predicate.
     *
     * @param callable(AuditEntry): bool $predicate
     */
    public function all(callable $predicate): bool
    {
        return array_all($this->entries, $predicate);
    }

    /**
     * @return Traversable<int, AuditEntry>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->entries);
    }
}
