<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Rcsofttech\AuditTrailBundle\MessageHandler\PersistAuditLogHandler;

#[AllowMockObjectsWithoutExpectations]
class PersistAuditLogHandlerTest extends TestCase
{
    private PersistAuditLogHandler $handler;

    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->handler = new PersistAuditLogHandler($this->em);
    }

    public function testInvokePersistsAuditLog(): void
    {
        $message = new PersistAuditLogMessage(
            entityClass: 'App\Entity\Product',
            entityId: '42',
            action: 'create',
            oldValues: null,
            newValues: ['name' => 'Widget'],
            changedFields: ['name'],
            userId: 'user-1',
            username: 'admin',
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla/5.0',
            transactionHash: 'abc123',
            createdAt: '2025-01-01T12:00:00+00:00',
            signature: 'sig-hash',
            context: ['source' => 'test'],
        );

        $this->em->expects($this->once())
            ->method('persist')
            ->with(self::callback(static function (AuditLog $log) {
                return $log->entityClass === 'App\Entity\Product'
                    && $log->entityId === '42'
                    && $log->action === 'create'
                    && $log->oldValues === null
                    && $log->newValues === ['name' => 'Widget']
                    && $log->changedFields === ['name']
                    && $log->userId === 'user-1'
                    && $log->username === 'admin'
                    && $log->ipAddress === '127.0.0.1'
                    && $log->userAgent === 'Mozilla/5.0'
                    && $log->transactionHash === 'abc123'
                    && $log->signature === 'sig-hash'
                    && $log->context === ['source' => 'test'];
            }));

        $this->em->expects($this->once())->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeWithMinimalMessage(): void
    {
        $message = new PersistAuditLogMessage(
            entityClass: 'App\Entity\Order',
            entityId: '99',
            action: 'delete',
            oldValues: ['status' => 'active'],
            newValues: null,
            changedFields: null,
            userId: null,
            username: null,
            ipAddress: null,
            userAgent: null,
            transactionHash: null,
            createdAt: '2025-06-15T08:00:00+00:00',
        );

        $this->em->expects($this->once())
            ->method('persist')
            ->with(self::callback(static function (AuditLog $log) {
                return $log->entityClass === 'App\Entity\Order'
                    && $log->entityId === '99'
                    && $log->action === 'delete'
                    && $log->userId === null
                    && $log->signature === null
                    && $log->context === [];
            }));

        $this->em->expects($this->once())->method('flush');

        ($this->handler)($message);
    }
}
