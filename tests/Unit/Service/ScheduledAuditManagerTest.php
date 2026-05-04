<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use OverflowException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
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
        self::assertSame($entity, $audits[0]->entity);
        self::assertSame($log, $audits[0]->audit);
        self::assertTrue($audits[0]->isInsert);
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
        self::assertFalse($manager->getScheduledAudits()[0]->isInsert);
    }

    public function testPendingDeletions(): void
    {
        $manager = new ScheduledAuditManager();
        $entity = new stdClass();
        $data = ['id' => 1];

        $manager->addPendingDeletion($entity, $data, true, AuditAction::Delete);

        $deletions = $manager->getPendingDeletions();
        self::assertCount(1, $deletions);
        self::assertSame($entity, $deletions[0]->entity);
        self::assertSame($data, $deletions[0]->data);
        self::assertTrue($deletions[0]->isManaged);
        self::assertSame(AuditAction::Delete, $deletions[0]->action);
    }

    public function testPendingDeletionOverflow(): void
    {
        $manager = new ScheduledAuditManager(maxPendingDeletions: 1);
        $manager->addPendingDeletion(new stdClass(), ['id' => 1], true, AuditAction::Delete);

        self::expectException(OverflowException::class);
        $manager->addPendingDeletion(new stdClass(), ['id' => 2], true, AuditAction::Delete);
    }

    public function testPendingAuditPlanOverflow(): void
    {
        $manager = new ScheduledAuditManager(maxPendingAuditPlans: 1);
        $manager->schedulePendingAuditPlan(PendingAuditPlan::forEntityRefresh(new stdClass(), AuditAction::Create));

        self::expectException(OverflowException::class);
        $manager->schedulePendingAuditPlan(PendingAuditPlan::forEntityRefresh(new stdClass(), AuditAction::Create));
    }

    public function testClear(): void
    {
        $manager = new ScheduledAuditManager();
        $manager->schedule(new stdClass(), $this->createAuditLog(), true);
        $manager->addPendingDeletion(new stdClass(), [], true, AuditAction::Delete);

        $manager->clear();

        self::assertEmpty($manager->getScheduledAudits());
        self::assertEmpty($manager->getPendingDeletions());
    }

    private function createAuditLog(): AuditLog
    {
        return new AuditLog(stdClass::class, '1', AuditAction::Create);
    }
}
