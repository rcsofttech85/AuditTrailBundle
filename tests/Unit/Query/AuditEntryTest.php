<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Query\AuditEntry;

final class AuditEntryTest extends TestCase
{
    public function testGettersReturnAuditLogValues(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        self::assertSame('App\\Entity\\User', $entry->entityClass);
        self::assertSame('User', $entry->entityShortName);
        self::assertSame('123', $entry->entityId);
        self::assertSame(AuditAction::Update->value, $entry->action);
        self::assertSame('42', $entry->userId);
        self::assertSame('admin', $entry->username);
        self::assertSame('127.0.0.1', $entry->ipAddress);
        self::assertSame('abc123', $entry->transactionHash);
    }

    public function testActionHelpers(): void
    {
        $createLog = $this->createAuditLog(AuditAction::Create);
        $updateLog = $this->createAuditLog(AuditAction::Update);
        $deleteLog = $this->createAuditLog(AuditAction::Delete);
        $softDeleteLog = $this->createAuditLog(AuditAction::SoftDelete);
        $restoreLog = $this->createAuditLog(AuditAction::Restore);

        self::assertTrue(new AuditEntry($createLog)->isCreate);
        self::assertFalse(new AuditEntry($createLog)->isUpdate);

        self::assertTrue(new AuditEntry($updateLog)->isUpdate);
        self::assertFalse(new AuditEntry($updateLog)->isDelete);

        self::assertTrue(new AuditEntry($deleteLog)->isDelete);
        self::assertTrue(new AuditEntry($softDeleteLog)->isSoftDelete);
        self::assertTrue(new AuditEntry($restoreLog)->isRestore);
    }

    public function testGetDiffReturnsOldAndNewValues(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        $diff = $entry->diff;

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

        $changedFields = $entry->changedFields;

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

    public function testLogReturnsUnderlyingEntity(): void
    {
        $log = $this->createAuditLog();
        $entry = new AuditEntry($log);

        self::assertSame($log, $entry->auditLog);
    }

    public function testGetEntityShortNameWithSimpleClass(): void
    {
        $log = new AuditLog('User', '1', AuditAction::Create);

        $entry = new AuditEntry($log);

        self::assertSame('User', $entry->entityShortName);
    }

    private function createAuditLog(AuditAction|string $action = AuditAction::Update): AuditLog
    {
        $log = new AuditLog(
            entityClass: 'App\\Entity\\User',
            entityId: '123',
            action: $action,
            oldValues: ['name' => 'John', 'email' => 'john@example.com'],
            newValues: ['name' => 'Jane', 'email' => 'jane@example.com'],
            changedFields: ['name', 'email'],
            userId: '42',
            username: 'admin',
            ipAddress: '127.0.0.1',
            transactionHash: 'abc123'
        );

        return $log;
    }
}
