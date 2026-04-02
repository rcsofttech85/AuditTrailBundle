<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\RevertTestUser;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class AuditReverterTest extends AbstractAuditTestCase
{
    private EntityManagerInterface&MockObject $em;

    private ValidatorInterface&Stub $validator;

    private AuditServiceInterface&MockObject $auditService;

    private FilterCollection&Stub $filterCollection;

    private SoftDeleteHandlerInterface&MockObject $softDeleteHandler;

    private AuditIntegrityServiceInterface&Stub $integrityService;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private AuditLogRepositoryInterface&Stub $repository;

    private ScheduledAuditManagerInterface&MockObject $auditManager;

    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->validator = self::createStub(ValidatorInterface::class);
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->filterCollection = self::createStub(FilterCollection::class);
        $this->softDeleteHandler = $this->createMock(SoftDeleteHandlerInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->auditManager = $this->createMock(ScheduledAuditManagerInterface::class);

        $this->em->method('getFilters')->willReturn($this->filterCollection);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $this->reverter = new AuditReverter(
            $this->em,
            $this->validator,
            $this->auditService,
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $this->integrityService,
            $this->dispatcher,
            $serializer,
            $this->auditManager,
            $this->repository
        );
    }

    public function testRevertEntityNotFound(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_UPDATE);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $this->em->expects($this->never())->method('persist');
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->reverter->revert($log);
    }

    public function testRevertDryRun(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'Old']
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturn('New');
        $metadata->expects($this->never())->method('setFieldValue');
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $changes = $this->reverter->revert($log, true);
        self::assertSame(['name' => 'Old'], $changes);
    }

    public function testValidationFailureRestoresManagedEntityState(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'Old']
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $entity = new RevertTestUser();
        $entity->name = 'New';

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturnCallback(static fn (RevertTestUser $entity, string $field): mixed => $field === 'name' ? $entity->name : null);
        $metadata->expects($this->once())
            ->method('setFieldValue')
            ->with($entity, 'name', 'Old')
            ->willReturnCallback(static function (RevertTestUser $entity, string $field, mixed $value): void {
                if ($field === 'name') {
                    $entity->name = (string) $value;
                }
            });
        $this->em->method('getClassMetadata')->willReturn($metadata);
        $this->em->method('isOpen')->willReturn(true);
        $this->em->expects($this->once())->method('refresh')->with($entity)->willReturnCallback(static function (RevertTestUser $entity): void {
            $entity->name = 'New';
        });
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());

        $violations = new ConstraintViolationList();
        $violations->add(new ConstraintViolation('Error', null, [], null, 'path', 'val'));
        $this->validator->method('validate')->willReturn($violations);

        try {
            $this->reverter->revert($log);
            self::fail('Expected RuntimeException to be thrown');
        } catch (RuntimeException) {
            self::assertSame('New', $entity->name);
        }
    }

    public function testRevertSoftDeleteDryRunDoesNotMutateEntity(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);
        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(true);
        $this->softDeleteHandler->expects($this->never())->method('restoreSoftDeleted');
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $changes = $this->reverter->revert($log, true);

        self::assertSame(['action' => 'restore'], $changes);
    }

    public function testRevertUnsupportedAction(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_DELETE);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $this->em->expects($this->never())->method('persist');
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not supported');

        $this->reverter->revert($log);
    }

    public function testRevertUpdateNoOldValues(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: []
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $this->em->expects($this->never())->method('persist');
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No old values');

        $this->reverter->revert($log);
    }

    public function testRevertSoftDeleteNotDeleted(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->em->expects($this->once())->method('flush');
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(false);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $changes = $this->reverter->revert($log);
        self::assertSame(['info' => 'Entity is not soft-deleted.'], $changes);
    }

    public function testApplyChangesSkipping(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['id' => 1, 'unchanged' => 'val', 'changed' => 'old']
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $metadata->method('isIdentifier')->willReturnMap([
            ['id', true],
            ['unchanged', false],
            ['changed', false],
        ]);

        $metadata->method('hasField')->willReturn(true);

        $metadata->method('getFieldValue')->willReturnMap([
            [$entity, 'unchanged', 'val'],
            [$entity, 'changed', 'new'],
        ]);

        $metadata->expects($this->once())->method('setFieldValue')->with($entity, 'changed', 'old');

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT, oldValues: ['changed' => 'old']);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditLogInterface::ACTION_REVERT, ['changed' => 'new'], ['changed' => 'old'])
            ->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $changes = $this->reverter->revert($log);

        self::assertSame(['changed' => 'old'], $changes);
        self::assertArrayNotHasKey('id', $changes);
        self::assertArrayNotHasKey('unchanged', $changes);
    }

    public function testRevertNoisy(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'Old']
        );

        $this->expectSoftDeleteFilterLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT, oldValues: ['name' => 'Old']);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);

        $this->auditManager->expects($this->never())->method('disable');
        $this->auditManager->expects($this->never())->method('enable');

        $this->reverter->revert($log, false, false, [], false);
    }

    public function testRevertCollectionFallsBackWhenMutatorIsNotPublic(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['friends' => ['target']]
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();

        $target = new class {};
        $entity = new class {
            /** @var ArrayCollection<int, object> */
            public ArrayCollection $friends;

            public function __construct()
            {
                $this->friends = new ArrayCollection();
            }

            /** @phpstan-ignore method.unused */
            private function addClass(object $friend): void
            {
                throw new RuntimeException('Private mutator must not be invoked.');
            }
        };

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $targetMetadata = $this->createMock(ClassMetadata::class);
        $this->em->method('getClassMetadata')->willReturnMap([
            [$entity::class, $metadata],
            [$target::class, $targetMetadata],
        ]);

        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->willReturn(true);
        $metadata->method('isCollectionValuedAssociation')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->willReturn($target::class);
        $metadata->method('getFieldValue')->willReturnMap([
            [$entity, 'friends', $entity->friends],
        ]);
        $metadata->method('getAssociationMapping')->willReturn(OneToManyAssociationMapping::fromMappingArray([
            'fieldName' => 'friends',
            'sourceEntity' => $entity::class,
            'targetEntity' => $target::class,
            'mappedBy' => 'owner',
            'isOwningSide' => false,
        ]));
        $metadata->expects($this->never())->method('setFieldValue');

        $targetMetadata->method('getIdentifierValues')->with($target)->willReturn(['id' => 'target']);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $targetMetadata->method('getTypeOfField')->with('id')->willReturn('string');

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog($entity::class, '1', AuditLogInterface::ACTION_REVERT, oldValues: ['friends' => []]);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);

        $this->reverter->revert($log);

        self::assertCount(1, $entity->friends);
    }

    public function testRevertCreateSuccess(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_CREATE);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->em->expects($this->once())->method('remove')->with($entity);
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, 'pending', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with(self::callback(static function (AuditLog $arg) {
            return $arg->action === AuditLogInterface::ACTION_REVERT && $arg->entityId === '1';
        }), $this->em, AuditPhase::PostFlush, null, $entity);

        $changes = $this->reverter->revert($log, false, true);
        self::assertSame(['action' => 'delete'], $changes);
    }

    public function testRevertSoftDeleteSuccess(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $entity->setDeletedAt(new DateTime());

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(true);
        $this->softDeleteHandler->expects($this->once())->method('restoreSoftDeleted')->with($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->em->expects($this->once())->method('flush');
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);

        $changes = $this->reverter->revert($log);
        self::assertSame(['action' => 'restore'], $changes);
    }

    public function testRevertWithCustomContext(): void
    {
        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $id = Uuid::v7();
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'Old']
        );
        $this->setLogId($log, $id);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->em->expects($this->once())->method('flush');
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $entity,
                AuditLogInterface::ACTION_REVERT,
                ['name' => null],
                ['name' => 'Old'],
                ['custom_key' => 'custom_val', 'reverted_log_id' => $id->toRfc4122()]
            )
            ->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);

        $this->reverter->revert($log, false, false, ['custom_key' => 'custom_val']);
    }

    public function testRevertAccessAction(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_ACCESS);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $c) => $c());
        $this->em->expects($this->once())->method('flush');
        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::PostFlush, null, $entity);

        $changes = $this->reverter->revert($log);
        self::assertSame([], $changes);
    }

    public function testRevertAlreadyReverted(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_UPDATE, oldValues: ['name' => 'Old']);

        $this->auditManager->expects($this->never())->method('disable');
        $this->auditManager->expects($this->never())->method('enable');
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->softDeleteHandler->expects($this->never())->method('disableSoftDeleteFilters');
        $this->softDeleteHandler->expects($this->never())->method('enableFilters');
        $this->em->expects($this->never())->method('persist');
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());
        $this->repository->method('isReverted')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been reverted');

        $this->reverter->revert($log);
    }

    private function setLogId(AuditLog $log, Uuid $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, $id);
    }

    private function expectSoftDeleteFilterLifecycle(): void
    {
        $this->softDeleteHandler->expects($this->once())->method('disableSoftDeleteFilters')->willReturn([]);
        $this->softDeleteHandler->expects($this->once())->method('enableFilters')->with([]);
    }

    private function expectAuditManagerLifecycle(): void
    {
        $this->auditManager->expects($this->once())->method('disable');
        $this->auditManager->expects($this->once())->method('enable');
    }

    private function expectNoRevertAudit(): void
    {
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->dispatcher->expects($this->never())->method('dispatch');
    }
}
