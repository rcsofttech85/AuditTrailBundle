<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditEntry;
use ReflectionClass;

#[CoversClass(AuditEntry::class)]
#[AllowMockObjectsWithoutExpectations()]
class AuditEntryTest extends TestCase
{
    public function testGettersReturnAuditLogValues(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        self::assertSame(1, $entry->getId());
        self::assertSame('App\\Entity\\User', $entry->getEntityClass());
        self::assertSame('User', $entry->getEntityShortName());
        self::assertSame('123', $entry->getEntityId());
        self::assertSame(AuditLogInterface::ACTION_UPDATE, $entry->getAction());
        self::assertSame('42', $entry->getUserId());
        self::assertSame('admin', $entry->getUsername());
        self::assertSame('127.0.0.1', $entry->getIpAddress());
        self::assertSame('abc123', $entry->getTransactionHash());
    }

    public function testActionHelpers(): void
    {
        $createLog = $this->createAuditLog(AuditLogInterface::ACTION_CREATE);
        $updateLog = $this->createAuditLog(AuditLogInterface::ACTION_UPDATE);
        $deleteLog = $this->createAuditLog(AuditLogInterface::ACTION_DELETE);
        $softDeleteLog = $this->createAuditLog(AuditLogInterface::ACTION_SOFT_DELETE);
        $restoreLog = $this->createAuditLog(AuditLogInterface::ACTION_RESTORE);

        self::assertTrue(new AuditEntry($createLog)->isCreate());
        self::assertFalse(new AuditEntry($createLog)->isUpdate());

        self::assertTrue(new AuditEntry($updateLog)->isUpdate());
        self::assertFalse(new AuditEntry($updateLog)->isDelete());

        self::assertTrue(new AuditEntry($deleteLog)->isDelete());
        self::assertTrue(new AuditEntry($softDeleteLog)->isSoftDelete());
        self::assertTrue(new AuditEntry($restoreLog)->isRestore());
    }

    public function testGetDiffReturnsOldAndNewValues(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        $diff = $entry->getDiff();

        self::assertArrayHasKey('name', $diff);
        self::assertSame('John', $diff['name']['old']);
        self::assertSame('Jane', $diff['name']['new']);

        self::assertArrayHasKey('email', $diff);
        self::assertSame('john@example.com', $diff['email']['old']);
        self::assertSame('jane@example.com', $diff['email']['new']);
    }

    public function testGetChangedFields(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        $changedFields = $entry->getChangedFields();

        self::assertContains('name', $changedFields);
        self::assertContains('email', $changedFields);
    }

    public function testHasFieldChanged(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        self::assertTrue($entry->hasFieldChanged('name'));
        self::assertTrue($entry->hasFieldChanged('email'));
        self::assertFalse($entry->hasFieldChanged('password'));
    }

    public function testGetOldAndNewValue(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        self::assertSame('John', $entry->getOldValue('name'));
        self::assertSame('Jane', $entry->getNewValue('name'));
        self::assertNull($entry->getOldValue('nonexistent'));
        self::assertNull($entry->getNewValue('nonexistent'));
    }

    public function testGetAuditLogReturnsUnderlyingEntity(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        self::assertSame($log, $entry->getAuditLog());
    }

    public function testGetEntityShortNameWithSimpleClass(): void
    {
        $log = new AuditLog();
        $log->setEntityClass('User');
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_CREATE);

        $entry = new AuditEntry($log);

        self::assertSame('User', $entry->getEntityShortName());
    }

    private function createAuditLog(string $action = AuditLogInterface::ACTION_UPDATE): AuditLog
    {
        $log = new AuditLog();
        $log->setEntityClass('App\\Entity\\User');
        $log->setEntityId('123');
        $log->setAction($action);
        $log->setUserId('42');
        $log->setUsername('admin');
        $log->setIpAddress('127.0.0.1');
        $log->setTransactionHash('abc123');
        $log->setOldValues(['name' => 'John', 'email' => 'john@example.com']);
        $log->setNewValues(['name' => 'Jane', 'email' => 'jane@example.com']);
        $log->setChangedFields(['name', 'email']);

        // Use reflection to set the ID
        $reflection = new ReflectionClass($log);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($log, 1);

        return $log;
    }
}
