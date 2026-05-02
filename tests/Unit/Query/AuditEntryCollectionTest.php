<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Query\AuditEntry;
use Rcsofttech\AuditTrailBundle\Query\AuditEntryCollection;

final class AuditEntryCollectionTest extends TestCase
{
    public function testCountReturnsNumberOfEntries(): void
    {
        $collection = new AuditEntryCollection([
            $this->createEntry(AuditAction::Create),
            $this->createEntry(AuditAction::Update),
        ]);

        self::assertCount(2, $collection);
    }

    public function testIsEmpty(): void
    {
        $emptyCollection = new AuditEntryCollection([]);
        $nonEmptyCollection = new AuditEntryCollection([
            $this->createEntry(AuditAction::Create),
        ]);

        self::assertTrue($emptyCollection->isEmpty());
        self::assertFalse($nonEmptyCollection->isEmpty());
    }

    public function testFirstAndLast(): void
    {
        $first = $this->createEntry(AuditAction::Create);
        $last = $this->createEntry(AuditAction::Delete);

        $collection = new AuditEntryCollection([$first, $last]);

        self::assertSame($first, $collection->first());
        self::assertSame($last, $collection->last());
    }

    public function testFirstAndLastReturnNullForEmptyCollection(): void
    {
        $collection = new AuditEntryCollection([]);

        self::assertNull($collection->first());
        self::assertNull($collection->last());
    }

    public function testFilter(): void
    {
        $create = $this->createEntry(AuditAction::Create);
        $update = $this->createEntry(AuditAction::Update);
        $delete = $this->createEntry(AuditAction::Delete);

        $collection = new AuditEntryCollection([$create, $update, $delete]);

        $filtered = $collection->filter(static fn (AuditEntry $e) => $e->isUpdate);

        self::assertCount(1, $filtered);
        $first = $filtered->first();
        self::assertNotNull($first);
        self::assertTrue($first->isUpdate);
    }

    public function testMap(): void
    {
        $collection = new AuditEntryCollection([
            $this->createEntry(AuditAction::Create),
            $this->createEntry(AuditAction::Update),
        ]);

        $actions = $collection->map(static fn (AuditEntry $e) => $e->action);

        self::assertSame([AuditAction::Create->value, AuditAction::Update->value], $actions);
    }

    public function testGroupByAction(): void
    {
        $create1 = $this->createEntry(AuditAction::Create);
        $create2 = $this->createEntry(AuditAction::Create);
        $update = $this->createEntry(AuditAction::Update);

        $collection = new AuditEntryCollection([$create1, $create2, $update]);

        $grouped = $collection->groupByAction();

        self::assertArrayHasKey(AuditAction::Create->value, $grouped);
        self::assertArrayHasKey(AuditAction::Update->value, $grouped);
        self::assertCount(2, $grouped[AuditAction::Create->value]);
        self::assertCount(1, $grouped[AuditAction::Update->value]);
    }

    public function testGroupByEntity(): void
    {
        $user1 = $this->createEntry(AuditAction::Create, 'App\\Entity\\User');
        $user2 = $this->createEntry(AuditAction::Update, 'App\\Entity\\User');
        $product = $this->createEntry(AuditAction::Create, 'App\\Entity\\Product');

        $collection = new AuditEntryCollection([$user1, $user2, $product]);

        $grouped = $collection->groupByEntity();

        self::assertArrayHasKey('App\\Entity\\User', $grouped);
        self::assertArrayHasKey('App\\Entity\\Product', $grouped);
        self::assertCount(2, $grouped['App\\Entity\\User']);
        self::assertCount(1, $grouped['App\\Entity\\Product']);
    }

    public function testGetCreatesUpdatesDeletes(): void
    {
        $create = $this->createEntry(AuditAction::Create);
        $update = $this->createEntry(AuditAction::Update);
        $delete = $this->createEntry(AuditAction::Delete);
        $softDelete = $this->createEntry(AuditAction::SoftDelete);

        $collection = new AuditEntryCollection([$create, $update, $delete, $softDelete]);

        self::assertCount(1, $collection->getCreates());
        self::assertCount(1, $collection->getUpdates());
        self::assertCount(2, $collection->getDeletes()); // Includes soft delete
    }

    public function testToArray(): void
    {
        $entry = $this->createEntry(AuditAction::Create);
        $collection = new AuditEntryCollection([$entry]);

        $array = $collection->toArray();

        self::assertCount(1, $array);
        self::assertSame($entry, $array[0]);
    }

    public function testIterable(): void
    {
        $entry1 = $this->createEntry(AuditAction::Create);
        $entry2 = $this->createEntry(AuditAction::Update);

        $collection = new AuditEntryCollection([$entry1, $entry2]);

        $entries = [];
        foreach ($collection as $entry) {
            $entries[] = $entry;
        }

        self::assertCount(2, $entries);
        self::assertSame($entry1, $entries[0]);
        self::assertSame($entry2, $entries[1]);
    }

    private function createEntry(AuditAction|string $action, string $entityClass = 'App\\Entity\\User'): AuditEntry
    {
        $log = new AuditLog($entityClass, '1', $action);

        return new AuditEntry($log);
    }
}
