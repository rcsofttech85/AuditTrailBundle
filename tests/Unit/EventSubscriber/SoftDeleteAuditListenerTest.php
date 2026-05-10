<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\Event\LifecycleEventArgs;
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

final class SoftDeleteAuditListenerTest extends TestCase
{
    /** @var (AuditServiceInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditServiceInterface&MockObject) */
    private AuditServiceInterface $auditService;

    /** @var (ChangeProcessorInterface&\PHPUnit\Framework\MockObject\Stub)|(ChangeProcessorInterface&MockObject) */
    private ChangeProcessorInterface $changeProcessor;

    /** @var (ScheduledAuditManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(ScheduledAuditManagerInterface&MockObject) */
    private ScheduledAuditManagerInterface $auditManager;

    private SoftDeleteAuditListener $listener;

    protected function setUp(): void
    {
        $this->auditService = self::createStub(AuditServiceInterface::class);
        $this->changeProcessor = self::createStub(ChangeProcessorInterface::class);
        $this->auditManager = self::createStub(ScheduledAuditManagerInterface::class);

        $this->rebuildListener();
    }

    /** @return AuditServiceInterface&MockObject */
    private function useAuditServiceMock(): AuditServiceInterface
    {
        $auditService = $this->createMock(AuditServiceInterface::class);
        $this->auditService = $auditService;
        $this->rebuildListener();

        return $auditService;
    }

    /** @return ChangeProcessorInterface&MockObject */
    private function useChangeProcessorMock(): ChangeProcessorInterface
    {
        $changeProcessor = $this->createMock(ChangeProcessorInterface::class);
        $this->changeProcessor = $changeProcessor;
        $this->rebuildListener();

        return $changeProcessor;
    }

    /** @return ScheduledAuditManagerInterface&MockObject */
    private function useAuditManagerMock(): ScheduledAuditManagerInterface
    {
        $auditManager = $this->createMock(ScheduledAuditManagerInterface::class);
        $this->auditManager = $auditManager;
        $this->rebuildListener();

        return $auditManager;
    }

    private function rebuildListener(): void
    {
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
        $em = self::createStub(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $args = self::createStub(LifecycleEventArgs::class);
        $auditManager = $this->useAuditManagerMock();
        $changeProcessor = $this->useChangeProcessorMock();
        $auditService = $this->useAuditServiceMock();

        $args->method('getObject')->willReturn($entity);
        $args->method('getObjectManager')->willReturn($em);

        $auditManager->expects($this->once())
            ->method('isEnabled')
            ->willReturn(true);
        $em->method('getUnitOfWork')->willReturn($uow);
        $uow->expects($this->once())
            ->method('getEntityChangeSet')
            ->with($entity)
            ->willReturn($changeSet);

        $changeProcessor->expects($this->once())
            ->method('determineUpdateAction')
            ->with($changeSet)
            ->willReturn(AuditAction::SoftDelete);
        $changeProcessor->expects($this->once())
            ->method('extractChanges')
            ->with($entity, $changeSet)
            ->willReturn([
                ['deletedAt' => null],
                ['deletedAt' => '2026-04-02T00:00:00+00:00'],
            ]);

        $auditService->expects($this->exactly(2))
            ->method('shouldAudit')
            ->willReturnCallback(static function (object $currentEntity, AuditAction $action = AuditAction::Create, array $changeSet = []): bool {
                return $currentEntity instanceof stdClass
                    && ($action === AuditAction::Create || $action === AuditAction::SoftDelete)
                    && ($changeSet === [] || $changeSet === ['deletedAt' => '2026-04-02T00:00:00+00:00']);
            });
        $auditService->expects($this->once())
            ->method('getEntityData')
            ->with($entity, [], $em)
            ->willReturn(['deletedAt' => '2026-04-02T00:00:00+00:00']);

        $auditManager->expects($this->once())
            ->method('addPendingDeletion')
            ->with($entity, ['deletedAt' => null], AuditAction::SoftDelete);

        $this->listener->postSoftDelete($args);
    }

    public function testPostSoftDeleteSkipsWhenAuditManagerIsDisabled(): void
    {
        $entity = new stdClass();
        $args = self::createStub(LifecycleEventArgs::class);
        $em = self::createStub(EntityManagerInterface::class);
        $auditManager = $this->useAuditManagerMock();
        $changeProcessor = $this->useChangeProcessorMock();
        $auditService = $this->useAuditServiceMock();

        $args->method('getObject')->willReturn($entity);
        $args->method('getObjectManager')->willReturn($em);

        $auditManager->expects($this->once())
            ->method('isEnabled')
            ->willReturn(false);
        $changeProcessor->expects($this->never())->method('determineUpdateAction');
        $auditService->expects($this->never())->method('shouldAudit');
        $auditManager->expects($this->never())->method('addPendingDeletion');

        $this->listener->postSoftDelete($args);
    }

    public function testPostSoftDeleteSkipsAuditLogEntity(): void
    {
        $auditLog = new AuditLog(stdClass::class, '1', AuditAction::SoftDelete);
        $args = self::createStub(LifecycleEventArgs::class);
        $changeProcessor = $this->useChangeProcessorMock();
        $auditManager = $this->useAuditManagerMock();
        $args->method('getObject')->willReturn($auditLog);

        $changeProcessor->expects($this->never())->method('determineUpdateAction');
        $auditManager->expects($this->never())->method('addPendingDeletion');

        $this->listener->postSoftDelete($args);
    }
}
