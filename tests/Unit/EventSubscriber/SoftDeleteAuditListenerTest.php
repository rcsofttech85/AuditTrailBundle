<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\EventSubscriber\SoftDeleteAuditListener;
use ReflectionClass;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
final class SoftDeleteAuditListenerTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private ChangeProcessorInterface&MockObject $changeProcessor;

    private ScheduledAuditManagerInterface&MockObject $auditManager;

    private SoftDeleteAuditListener $listener;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->changeProcessor = $this->createMock(ChangeProcessorInterface::class);
        $this->auditManager = $this->createMock(ScheduledAuditManagerInterface::class);

        $this->listener = new SoftDeleteAuditListener(
            $this->auditService,
            $this->changeProcessor,
            $this->auditManager,
        );
    }

    public function testDoctrineListenerAttributeRegistersPostSoftDelete(): void
    {
        $attributes = new ReflectionClass(SoftDeleteAuditListener::class)->getAttributes(AsDoctrineListener::class);

        self::assertCount(1, $attributes);
        self::assertSame(['event' => 'postSoftDelete'], $attributes[0]->getArguments());
    }

    public function testPostSoftDeleteQueuesPendingSoftDeleteAudit(): void
    {
        $entity = new stdClass();
        $changeSet = ['deletedAt' => [null, '2026-04-02T00:00:00+00:00']];
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = $this->createMock(LifecycleEventArgs::class);

        $args->method('getObject')->willReturn($entity);
        $args->method('getObjectManager')->willReturn($em);

        $this->auditManager->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects($this->once())
            ->method('contains')
            ->with($entity)
            ->willReturn(true);
        $uow->expects($this->once())
            ->method('getEntityChangeSet')
            ->with($entity)
            ->willReturn($changeSet);

        $this->changeProcessor->expects($this->once())
            ->method('determineUpdateAction')
            ->with($changeSet)
            ->willReturn(AuditAction::SoftDelete);
        $this->changeProcessor->expects($this->once())
            ->method('extractChanges')
            ->with($entity, $changeSet)
            ->willReturn([
                ['deletedAt' => null],
                ['deletedAt' => '2026-04-02T00:00:00+00:00'],
            ]);

        $this->auditService->expects($this->exactly(2))
            ->method('shouldAudit')
            ->willReturnCallback(static function (object $currentEntity, AuditAction $action = AuditAction::Create, array $changeSet = []): bool {
                return $currentEntity instanceof stdClass
                    && ($action === AuditAction::Create || $action === AuditAction::SoftDelete)
                    && ($changeSet === [] || $changeSet === ['deletedAt' => '2026-04-02T00:00:00+00:00']);
            });
        $this->auditService->expects($this->once())
            ->method('getEntityData')
            ->with($entity, [], $em)
            ->willReturn(['deletedAt' => '2026-04-02T00:00:00+00:00']);

        $this->auditManager->expects($this->once())
            ->method('addPendingDeletion')
            ->with($entity, ['deletedAt' => null], true, AuditAction::SoftDelete);

        $this->listener->postSoftDelete($args);
    }

    public function testPostSoftDeleteSkipsWhenAuditManagerIsDisabled(): void
    {
        $entity = new stdClass();
        $args = $this->createMock(LifecycleEventArgs::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $args->method('getObject')->willReturn($entity);
        $args->method('getObjectManager')->willReturn($em);

        $this->auditManager->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);
        $this->changeProcessor->expects($this->never())->method('determineUpdateAction');
        $this->auditService->expects($this->never())->method('shouldAudit');
        $this->auditManager->expects($this->never())->method('addPendingDeletion');

        $this->listener->postSoftDelete($args);
    }

    public function testPostSoftDeleteSkipsAuditLogEntity(): void
    {
        $auditLog = new AuditLog(stdClass::class, '1', AuditAction::SoftDelete);
        $args = $this->createMock(LifecycleEventArgs::class);
        $args->method('getObject')->willReturn($auditLog);

        $this->changeProcessor->expects($this->never())->method('determineUpdateAction');
        $this->auditManager->expects($this->never())->method('addPendingDeletion');

        $this->listener->postSoftDelete($args);
    }
}
