<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Iterator;
use IteratorAggregate;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Traversable;

/**
 * @implements IteratorAggregate<int, AuditLog>
 */
final class LimitedAuditExportBatch implements IteratorAggregate
{
    private readonly Iterator $iterator;

    private bool $initialized = false;

    private bool $hasFirstItem = false;

    private ?AuditLog $firstItem = null;

    /**
     * @param iterable<AuditLog> $audits
     */
    public function __construct(
        iterable $audits,
        private readonly int $limit,
    ) {
        $this->iterator = self::toIterator($audits);
    }

    public function hasItems(): bool
    {
        $this->initialize();

        return $this->hasFirstItem;
    }

    /**
     * @return Traversable<int, AuditLog>
     */
    public function getIterator(): Traversable
    {
        $this->initialize();

        if (!$this->hasFirstItem || $this->firstItem === null) {
            return;
        }

        $yielded = 0;

        yield $this->firstItem;
        ++$yielded;
        $this->iterator->next();

        while ($this->iterator->valid() && $yielded < $this->limit) {
            $current = $this->iterator->current();

            if ($current instanceof AuditLog) {
                yield $current;
                ++$yielded;
            }

            $this->iterator->next();
        }
    }

    private function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->iterator->rewind();
        $current = $this->iterator->valid() ? $this->iterator->current() : null;

        $this->hasFirstItem = $current instanceof AuditLog;
        $this->firstItem = $this->hasFirstItem ? $current : null;
        $this->initialized = true;
    }

    /**
     * @param iterable<AuditLog> $audits
     */
    private static function toIterator(iterable $audits): Iterator
    {
        return (static function () use ($audits): Iterator {
            yield from $audits;
        })();
    }
}
