<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use OverflowException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use stdClass;

final class ScheduledAuditManagerTest extends TestCase
{
    public function testSchedule(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $log = $this->createAuditLog();

        $manager->schedule($entity, $log, true);

        $audits = $manager->getScheduledAudits();

        self::assertCount(1, $audits);
        self::assertSame($entity, $audits[0]['entity']);
        self::assertSame($log, $audits[0]['audit']);
        self::assertTrue($audits[0]['is_insert']);
    }

    public function testScheduleOverflow(): void
    {
        $manager = new ScheduledAuditManager(maxScheduledAudits: 1000);
        $entity = new stdClass();
        $log = $this->createAuditLog();

        for ($i = 0; $i < 1000; ++$i) {
            $manager->schedule($entity, $log, true);
        }

        self::expectException(OverflowException::class);
        $manager->schedule($entity, $log, true);
    }

    public function testScheduleIsUnlimitedByDefault(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $log = $this->createAuditLog();

        for ($i = 0; $i < 1001; ++$i) {
            $manager->schedule($entity, $log, true);
        }

        self::assertCount(1001, $manager->getScheduledAudits());
    }

    public function testScheduleDoesNotDispatchEvent(): void
    {
        $manager = new ScheduledAuditManager();
        $manager->schedule(new stdClass(), $this->createAuditLog(), false);

        self::assertCount(1, $manager->getScheduledAudits());
        self::assertFalse($manager->getScheduledAudits()[0]['is_insert']);
    }

    public function testPendingDeletions(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $data = ['id' => 1];

        $manager->addPendingDeletion($entity, $data, true);

        $deletions = $manager->getPendingDeletions();
        self::assertCount(1, $deletions);
        self::assertSame($entity, $deletions[0]['entity']);
        self::assertSame($data, $deletions[0]['data']);
        self::assertTrue($deletions[0]['is_managed']);
    }

    public function testClear(): void
    {
        $manager = new ScheduledAuditManager();
        $manager->schedule(new stdClass(), $this->createAuditLog(), true);
        $manager->addPendingDeletion(new stdClass(), [], true);

        $manager->clear();

        self::assertEmpty($manager->getScheduledAudits());
        self::assertEmpty($manager->getPendingDeletions());
    }

    private function createAuditLog(): AuditLog
    {
        return new AuditLog(stdClass::class, '1', AuditLogInterface::ACTION_CREATE);
    }
}
