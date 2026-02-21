<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use OverflowException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use stdClass;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
class ScheduledAuditManagerTest extends TestCase
{
    public function testSchedule(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);

        $manager->schedule($entity, $log, true);

        self::assertNotEmpty($manager->scheduledAudits);
        self::assertCount(1, $manager->scheduledAudits);

        $audits = $manager->scheduledAudits;
        self::assertCount(1, $audits);
        self::assertSame($entity, $audits[0]['entity']);
        self::assertSame($log, $audits[0]['audit']);
        self::assertTrue($audits[0]['is_insert']);
    }

    public function testScheduleOverflow(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $log = new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);

        // Fill up to max (1000)
        for ($i = 0; $i < 1000; ++$i) {
            $manager->schedule($entity, $log, true);
        }

        $this->expectException(OverflowException::class);
        $manager->schedule($entity, $log, true);
    }

    public function testScheduleWithDispatcher(): void
    {
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::isInstanceOf(AuditLogCreatedEvent::class), AuditLogCreatedEvent::NAME);

        $manager = new ScheduledAuditManager($dispatcher);
        $manager->schedule(new stdClass(), new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE), false);
    }

    public function testPendingDeletions(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $data = ['id' => 1];

        $manager->addPendingDeletion($entity, $data, true);

        $deletions = $manager->pendingDeletions;
        self::assertCount(1, $deletions);
        self::assertSame($entity, $deletions[0]['entity']);
        self::assertSame($data, $deletions[0]['data']);
        self::assertTrue($deletions[0]['is_managed']);
    }

    public function testClear(): void
    {
        $manager = new ScheduledAuditManager();
        $manager->schedule(new stdClass(), new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE), true);
        $manager->addPendingDeletion(new stdClass(), [], true);

        $manager->clear();

        self::assertEmpty($manager->scheduledAudits);
        self::assertEmpty($manager->pendingDeletions);
    }
}
