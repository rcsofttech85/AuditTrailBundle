<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\DataCollector;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\DataCollector\AuditDataCollector;
use Rcsofttech\AuditTrailBundle\DataCollector\TraceableAuditCollector;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[CoversClass(AuditDataCollector::class)]
final class AuditDataCollectorTest extends TestCase
{
    private TraceableAuditCollector $traceableCollector;

    private AuditDataCollector $dataCollector;

    protected function setUp(): void
    {
        $this->traceableCollector = new TraceableAuditCollector();
        $this->dataCollector = new AuditDataCollector($this->traceableCollector);
    }

    #[Test]
    public function itReturnsZeroCountWhenNoAudits(): void
    {
        $this->dataCollector->collect(new Request(), new Response());

        self::assertSame(0, $this->dataCollector->getAuditCount());
        self::assertSame([], $this->dataCollector->getAudits());
        self::assertSame([], $this->dataCollector->getActionBreakdown());
    }

    #[Test]
    public function itCollectsAuditData(): void
    {
        $audit = new AuditLog(entityClass: 'App\Entity\User', entityId: '7', action: 'create');
        $this->traceableCollector->onAuditLogCreated(new AuditLogCreatedEvent($audit));

        $this->dataCollector->collect(new Request(), new Response());

        self::assertSame(1, $this->dataCollector->getAuditCount());
        self::assertCount(1, $this->dataCollector->getAudits());
        self::assertSame('App\Entity\User', $this->dataCollector->getAudits()[0]['entity_class']);
    }

    #[Test]
    public function itReturnsActionBreakdown(): void
    {
        $this->traceableCollector->onAuditLogCreated(
            new AuditLogCreatedEvent(new AuditLog(entityClass: 'App\Entity\User', entityId: '1', action: 'create'))
        );
        $this->traceableCollector->onAuditLogCreated(
            new AuditLogCreatedEvent(new AuditLog(entityClass: 'App\Entity\User', entityId: '2', action: 'update'))
        );
        $this->traceableCollector->onAuditLogCreated(
            new AuditLogCreatedEvent(new AuditLog(entityClass: 'App\Entity\Order', entityId: '1', action: 'create'))
        );

        $this->dataCollector->collect(new Request(), new Response());

        $breakdown = $this->dataCollector->getActionBreakdown();
        self::assertSame(2, $breakdown['create']);
        self::assertSame(1, $breakdown['update']);
    }

    #[Test]
    public function itHasCorrectName(): void
    {
        self::assertSame('audit_trail', $this->dataCollector->getName());
    }

    #[Test]
    public function itHasTemplate(): void
    {
        self::assertSame('@AuditTrail/Collector/audit.html.twig', AuditDataCollector::getTemplate());
    }
}
