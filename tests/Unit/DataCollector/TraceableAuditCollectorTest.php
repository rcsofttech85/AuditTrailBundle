<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\DataCollector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\DataCollector\TraceableAuditCollector;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[CoversClass(TraceableAuditCollector::class)]
final class TraceableAuditCollectorTest extends TestCase
{
    private TraceableAuditCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new TraceableAuditCollector();
    }

    public function testStartsEmpty(): void
    {
        self::assertSame([], $this->collector->collectedAudits);
    }

    public function testCollectsAuditEvents(): void
    {
        $audit = new AuditLog(
            entityClass: 'App\Entity\Product',
            entityId: '42',
            action: AuditAction::Create,
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

    public function testCollectsMultipleEvents(): void
    {
        $audit1 = new AuditLog(entityClass: 'App\Entity\Product', entityId: '1', action: AuditAction::Create);
        $audit2 = new AuditLog(entityClass: 'App\Entity\Order', entityId: '5', action: AuditAction::Update, changedFields: ['status']);

        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit1));
        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit2));

        self::assertCount(2, $this->collector->collectedAudits);
    }

    public function testResetsCollectedAudits(): void
    {
        $audit = new AuditLog(entityClass: 'App\Entity\Product', entityId: '1', action: AuditAction::Delete);
        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit));

        self::assertCount(1, $this->collector->collectedAudits);

        $this->collector->reset();

        self::assertSame([], $this->collector->collectedAudits);
    }

    public function testFallsBackToUserIdWhenUsernameIsNull(): void
    {
        $audit = new AuditLog(
            entityClass: 'App\Entity\Product',
            entityId: '1',
            action: AuditAction::Update,
            userId: 'user-uuid-123',
        );

        $this->collector->onAuditLogCreated(new AuditLogCreatedEvent($audit));

        self::assertSame('user-uuid-123', $this->collector->collectedAudits[0]['user']);
    }

    public function testIsRegisteredAsEventListener(): void
    {
        $attributes = new ReflectionClass(TraceableAuditCollector::class)
            ->getMethod('onAuditLogCreated')
            ->getAttributes(AsEventListener::class);

        self::assertCount(1, $attributes);
        self::assertSame(AuditLogCreatedEvent::class, $attributes[0]->getArguments()['event'] ?? null);
    }
}
