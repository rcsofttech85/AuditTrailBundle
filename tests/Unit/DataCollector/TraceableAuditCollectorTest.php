<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\DataCollector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\DataCollector\TraceableAuditCollector;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;

#[CoversClass(TraceableAuditCollector::class)]
final class TraceableAuditCollectorTest extends TestCase
{
    private TraceableAuditCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TraceableAuditCollector();
    }

    #[Test]
    public function itStartsEmpty(): void
    {
        self::assertSame([], $this->collector->collectedAudits);
    }

    #[Test]
    public function itCollectsAuditEvents(): void
    {
        $audit = new AuditLog(
            entityClass: 'App\Entity\Product',
            entityId: '42',
            action: 'create',
            changedFields: ['name', 'price'],
            username: 'admin',
            transactionHash: 'abc123def4567890',
        );

        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit));

        $collected = $this->collector->collectedAudits;

        self::assertCount(1, $collected);
        self::assertSame('App\Entity\Product', $collected[0]['entity_class']);
        self::assertSame('42', $collected[0]['entity_id']);
        self::assertSame('create', $collected[0]['action']);
        self::assertSame(['name', 'price'], $collected[0]['changed_fields']);
        self::assertSame('admin', $collected[0]['user']);
        self::assertSame('abc123def4567890', $collected[0]['transaction_hash']);
    }

    #[Test]
    public function itCollectsMultipleEvents(): void
    {
        $audit1 = new AuditLog(entityClass: 'App\Entity\Product', entityId: '1', action: 'create');
        $audit2 = new AuditLog(entityClass: 'App\Entity\Order', entityId: '5', action: 'update', changedFields: ['status']);

        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit1));
        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit2));

        self::assertCount(2, $this->collector->collectedAudits);
    }

    #[Test]
    public function itResetsCollectedAudits(): void
    {
        $audit = new AuditLog(entityClass: 'App\Entity\Product', entityId: '1', action: 'delete');
        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit));

        self::assertCount(1, $this->collector->collectedAudits);

        $this->collector->reset();

        self::assertSame([], $this->collector->collectedAudits);
    }

    #[Test]
    public function itFallsBackToUserIdWhenUsernameIsNull(): void
    {
        $audit = new AuditLog(
            entityClass: 'App\Entity\Product',
            entityId: '1',
            action: 'update',
            userId: 'user-uuid-123',
        );

        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit));

        self::assertSame('user-uuid-123', $this->collector->collectedAudits[0]['user']);
    }

    #[Test]
    public function itSubscribesToAuditLogCreatedEvent(): void
    {
        $events = TraceableAuditCollector::getSubscribedEvents();

        self::assertArrayHasKey(AuditLogCreatedEvent::class, $events);
    }
}
