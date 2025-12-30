<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditEntry;
use Rcsofttech\AuditTrailBundle\Query\AuditEntryCollection;

#[CoversClass(AuditEntryCollection::class)]
#[AllowMockObjectsWithoutExpectations()]
class AuditEntryCollectionTest extends TestCase
{
    public function testCountReturnsNumberOfEntries(): void
    {
        $collection = new AuditEntryCollection([
            $this->createEntry(AuditLogInterface::ACTION_CREATE),
            $this->createEntry(AuditLogInterface::ACTION_UPDATE),
        ]);

        self::assertCount(2, $collection);
    }

    public function testIsEmpty(): void
    {
        $emptyCollection = new AuditEntryCollection([]);
        $nonEmptyCollection = new AuditEntryCollection([
            $this->createEntry(AuditLogInterface::ACTION_CREATE),
        ]);

        self::assertTrue($emptyCollection->isEmpty());
        self::assertFalse($nonEmptyCollection->isEmpty());
    }

    public function testFirstAndLast(): void
    {
        $first = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $last = $this->createEntry(AuditLogInterface::ACTION_DELETE);

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
        $create = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $update = $this->createEntry(AuditLogInterface::ACTION_UPDATE);
        $delete = $this->createEntry(AuditLogInterface::ACTION_DELETE);

        $collection = new AuditEntryCollection([$create, $update, $delete]);

        $filtered = $collection->filter(fn (AuditEntry $e) => $e->isUpdate());

        self::assertCount(1, $filtered);
        $first = $filtered->first();
        self::assertNotNull($first);
        self::assertTrue($first->isUpdate());
    }

    public function testMap(): void
    {
        $collection = new AuditEntryCollection([
            $this->createEntry(AuditLogInterface::ACTION_CREATE),
            $this->createEntry(AuditLogInterface::ACTION_UPDATE),
        ]);

        $actions = $collection->map(fn (AuditEntry $e) => $e->getAction());

        self::assertSame([AuditLogInterface::ACTION_CREATE, AuditLogInterface::ACTION_UPDATE], $actions);
    }

    public function testGroupByAction(): void
    {
        $create1 = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $create2 = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $update = $this->createEntry(AuditLogInterface::ACTION_UPDATE);

        $collection = new AuditEntryCollection([$create1, $create2, $update]);

        $grouped = $collection->groupByAction();

        self::assertArrayHasKey(AuditLogInterface::ACTION_CREATE, $grouped);
        self::assertArrayHasKey(AuditLogInterface::ACTION_UPDATE, $grouped);
        self::assertCount(2, $grouped[AuditLogInterface::ACTION_CREATE]);
        self::assertCount(1, $grouped[AuditLogInterface::ACTION_UPDATE]);
    }

    public function testGroupByEntity(): void
    {
        $user1 = $this->createEntry(AuditLogInterface::ACTION_CREATE, 'App\\Entity\\User');
        $user2 = $this->createEntry(AuditLogInterface::ACTION_UPDATE, 'App\\Entity\\User');
        $product = $this->createEntry(AuditLogInterface::ACTION_CREATE, 'App\\Entity\\Product');

        $collection = new AuditEntryCollection([$user1, $user2, $product]);

        $grouped = $collection->groupByEntity();

        self::assertArrayHasKey('App\\Entity\\User', $grouped);
        self::assertArrayHasKey('App\\Entity\\Product', $grouped);
        self::assertCount(2, $grouped['App\\Entity\\User']);
        self::assertCount(1, $grouped['App\\Entity\\Product']);
    }

    public function testGetCreatesUpdatesDeletes(): void
    {
        $create = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $update = $this->createEntry(AuditLogInterface::ACTION_UPDATE);
        $delete = $this->createEntry(AuditLogInterface::ACTION_DELETE);
        $softDelete = $this->createEntry(AuditLogInterface::ACTION_SOFT_DELETE);

        $collection = new AuditEntryCollection([$create, $update, $delete, $softDelete]);

        self::assertCount(1, $collection->getCreates());
        self::assertCount(1, $collection->getUpdates());
        self::assertCount(2, $collection->getDeletes()); // Includes soft delete
    }

    public function testToArray(): void
    {
        $entry = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $collection = new AuditEntryCollection([$entry]);

        $array = $collection->toArray();

        self::assertCount(1, $array);
        self::assertSame($entry, $array[0]);
    }

    public function testIterable(): void
    {
        $entry1 = $this->createEntry(AuditLogInterface::ACTION_CREATE);
        $entry2 = $this->createEntry(AuditLogInterface::ACTION_UPDATE);

        $collection = new AuditEntryCollection([$entry1, $entry2]);

        $entries = [];
        foreach ($collection as $entry) {
            $entries[] = $entry;
        }

        self::assertCount(2, $entries);
        self::assertSame($entry1, $entries[0]);
        self::assertSame($entry2, $entries[1]);
    }

    private function createEntry(string $action, string $entityClass = 'App\\Entity\\User'): AuditEntry
    {
        $log = new AuditLog();
        $log->setEntityClass($entityClass);
        $log->setEntityId('1');
        $log->setAction($action);

        return new AuditEntry($log);
    }
}
