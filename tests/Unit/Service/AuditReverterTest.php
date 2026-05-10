<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\OneToManyAssociationMapping;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\AssociationMutatorInvoker;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\EntityIdentifierNormalizer;
use Rcsofttech\AuditTrailBundle\Service\EntityManagerResolver;
use Rcsofttech\AuditTrailBundle\Service\RevertAccessActionHandler;
use Rcsofttech\AuditTrailBundle\Service\RevertAuditLogCreator;
use Rcsofttech\AuditTrailBundle\Service\RevertCollectionAssociationSynchronizer;
use Rcsofttech\AuditTrailBundle\Service\RevertCreateActionHandler;
use Rcsofttech\AuditTrailBundle\Service\RevertDateTimeValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Service\RevertEntityStateApplier;
use Rcsofttech\AuditTrailBundle\Service\RevertGuard;
use Rcsofttech\AuditTrailBundle\Service\RevertPlanBuilder;
use Rcsofttech\AuditTrailBundle\Service\RevertSoftDeleteActionHandler;
use Rcsofttech\AuditTrailBundle\Service\RevertUpdateActionHandler;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteFilterManager;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\RevertTestUser;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuditReverterTest extends AbstractAuditTestCase
{
    private EntityManagerInterface&MockObject $em;

    private ValidatorInterface&Stub $validator;

    private AuditServiceInterface&MockObject $auditService;

    private FilterCollection&Stub $filterCollection;

    /** @var (SoftDeleteHandlerInterface&Stub)|(SoftDeleteHandlerInterface&MockObject) */
    private SoftDeleteHandlerInterface $softDeleteHandler;

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
        $this->softDeleteHandler = self::createStub(SoftDeleteHandlerInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);
        $this->repository = self::createStub(AuditLogRepositoryInterface::class);
        $this->auditManager = $this->createMock(ScheduledAuditManagerInterface::class);

        $this->em->method('getFilters')->willReturn($this->filterCollection);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $this->reverter = $this->createReverter($serializer);
    }

    /** @return SoftDeleteHandlerInterface&MockObject */
    private function useSoftDeleteHandlerMock(): SoftDeleteHandlerInterface
    {
        $softDeleteHandler = $this->createMock(SoftDeleteHandlerInterface::class);
        $this->softDeleteHandler = $softDeleteHandler;

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = $this->createReverter($serializer);

        return $softDeleteHandler;
    }

    private function createReverter(
        ?ValueSerializerInterface $serializer = null,
        ?EntityManagerResolver $resolver = null,
    ): AuditReverter {
        if ($serializer === null) {
            $serializer = self::createStub(ValueSerializerInterface::class);
            $serializer->method('serialize')->willReturnArgument(0);
        }

        $resolver ??= $this->createResolver(defaultManager: $this->em);
        $denormalizer = new RevertValueDenormalizer(
            $resolver,
            new RevertDateTimeValueDenormalizer(),
            new EntityIdentifierNormalizer($resolver),
        );
        $collectionSynchronizer = new RevertCollectionAssociationSynchronizer($resolver, new AssociationMutatorInvoker());

        return new AuditReverter(
            $resolver,
            $this->validator,
            $denormalizer,
            new SoftDeleteFilterManager(['softdeleteable']),
            $this->auditManager,
            new RevertGuard($this->integrityService, $this->repository),
            new RevertEntityStateApplier($resolver, $this->softDeleteHandler, $collectionSynchronizer),
            new RevertAuditLogCreator($this->auditService, $serializer),
            $this->dispatcher,
            [
                new RevertCreateActionHandler(),
                new RevertUpdateActionHandler(new RevertPlanBuilder($resolver, $denormalizer, $serializer, $collectionSynchronizer)),
                new RevertSoftDeleteActionHandler($this->softDeleteHandler),
                new RevertAccessActionHandler(),
            ],
        );
    }

    public function testRevertEntityNotFound(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Update);

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
            action: AuditAction::Update,
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
            action: AuditAction::Update,
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
        $this->mockWrapInTransactionWithoutFlush();

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
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::SoftDelete);
        $softDeleteHandler = $this->useSoftDeleteHandlerMock();

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->expectNoRevertAudit();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);
        $softDeleteHandler->expects($this->once())
            ->method('isSoftDeleted')
            ->with($entity)
            ->willReturn(true);
        $softDeleteHandler->expects($this->never())->method('restoreSoftDeleted');
        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $changes = $this->reverter->revert($log, true);

        self::assertSame(['action' => 'restore'], $changes);
    }

    public function testRevertUnsupportedAction(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Delete);

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
            action: AuditAction::Update,
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
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::SoftDelete);
        $softDeleteHandler = $this->useSoftDeleteHandlerMock();

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $this->em->expects($this->once())->method('flush');
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $softDeleteHandler->expects($this->once())
            ->method('isSoftDeleted')
            ->with($entity)
            ->willReturn(false);

        $this->mockWrapInTransactionWithFlush();

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $changes = $this->reverter->revert($log);
        self::assertSame(['info' => 'Entity is not soft-deleted.'], $changes);
    }

    public function testApplyChangesSkipping(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditAction::Update,
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

        $this->mockWrapInTransactionWithFlush();
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert, oldValues: ['changed' => 'old']);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditAction::Revert, ['changed' => 'new'], ['changed' => 'old'])
            ->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

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
            action: AuditAction::Update,
            oldValues: ['name' => 'Old']
        );

        $this->expectSoftDeleteFilterLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->mockWrapInTransactionWithFlush();
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert, oldValues: ['name' => 'Old']);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

        $this->auditManager->expects($this->never())->method('disable');
        $this->auditManager->expects($this->never())->method('enable');

        $this->reverter->revert($log, false, false, [], false);
    }

    public function testRevertCollectionFallsBackWhenMutatorIsNotPublic(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditAction::Update,
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

        $targetMetadata->method('getIdentifierValues')->willReturn(['id' => 'target']);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $targetMetadata->expects($this->once())
            ->method('getTypeOfField')
            ->with('id')
            ->willReturn('string');

        $this->mockWrapInTransactionWithFlush();
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog($entity::class, '1', AuditAction::Revert, oldValues: ['friends' => []]);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

        $this->reverter->revert($log);

        self::assertCount(1, $entity->friends);
    }

    public function testRevertCreateSuccess(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Create);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->mockWrapInTransactionWithFlush();
        $this->em->expects($this->once())->method('remove')->with($entity);
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, null, AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with(self::callback(static function (AuditLog $arg) {
            return $arg->action === AuditAction::Revert && $arg->entityId === '1';
        }), $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

        $changes = $this->reverter->revert($log, false, true);
        self::assertSame(['action' => 'delete'], $changes);
    }

    public function testRevertSoftDeleteSuccess(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::SoftDelete);
        $softDeleteHandler = $this->useSoftDeleteHandlerMock();

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $entity->setDeletedAt(new DateTime());

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $softDeleteHandler->expects($this->once())
            ->method('isSoftDeleted')
            ->with($entity)
            ->willReturn(true);
        $softDeleteHandler->expects($this->once())->method('restoreSoftDeleted')->with($entity);

        $this->mockWrapInTransactionWithFlush();
        $this->em->expects($this->once())->method('flush');
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

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
            action: AuditAction::Update,
            oldValues: ['name' => 'Old']
        );
        $this->setLogId($log, $id);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->mockWrapInTransactionWithFlush();
        $this->em->expects($this->once())->method('flush');
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $entity,
                AuditAction::Revert,
                ['name' => null],
                ['name' => 'Old'],
                ['custom_key' => 'custom_val', 'reverted_log_id' => $id->toRfc4122()]
            )
            ->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

        $this->reverter->revert($log, false, false, ['custom_key' => 'custom_val']);
    }

    public function testRevertAccessAction(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Access);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->mockWrapInTransactionWithFlush();
        $this->em->expects($this->once())->method('flush');
        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)->willReturn(true);

        $changes = $this->reverter->revert($log);
        self::assertSame([], $changes);
    }

    public function testRevertFallsBackToDeferredDispatchWhenOnFlushTransportSkipsIt(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Create);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $events = [];
        $this->em->method('wrapInTransaction')->willReturnCallback(function (callable $callback) use (&$events): mixed {
            $events[] = 'begin';
            $result = $callback($this->em);
            $events[] = 'flush';
            $this->em->flush();
            $events[] = 'commit';

            return $result;
        });
        $this->em->expects($this->once())->method('remove')->with($entity);
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, null, AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (AuditLog $audit, EntityManagerInterface $entityManager, AuditPhase $phase, mixed $uow, object $resolvedEntity) use (&$events, $revertLog, $entity): bool {
                self::assertSame($revertLog, $audit);
                self::assertSame($entity, $resolvedEntity);

                if ($phase === AuditPhase::OnFlush) {
                    $events[] = 'skip-in-transaction';

                    return false;
                }

                self::assertSame(AuditPhase::PostFlush, $phase);

                $events[] = 'dispatch';

                return true;
            });

        $this->reverter->revert($log, false, true);

        self::assertSame(['begin', 'skip-in-transaction', 'flush', 'commit', 'dispatch'], $events);
    }

    public function testRevertDispatchesWithinTransactionWhenOnFlushTransportSupportsIt(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Create);

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $events = [];
        $this->em->method('wrapInTransaction')->willReturnCallback(function (callable $callback) use (&$events): mixed {
            $events[] = 'begin';
            $result = $callback($this->em);
            $events[] = 'flush';
            $this->em->flush();
            $events[] = 'commit';

            return $result;
        });
        $this->em->expects($this->once())->method('remove')->with($entity);
        $this->em->expects($this->once())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, null, AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)
            ->willReturnCallback(static function () use (&$events): bool {
                $events[] = 'dispatch';

                return true;
            });

        $this->reverter->revert($log, false, true);

        self::assertSame(['begin', 'dispatch', 'flush', 'commit'], $events);
    }

    public function testRevertThrowsWhenCommittedRevertAuditDispatchFails(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditAction::Update,
            oldValues: ['name' => 'Old'],
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();

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
        $this->mockWrapInTransactionWithFlush();
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->em->expects($this->once())->method('flush');
        $this->em->expects($this->never())->method('refresh');

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $call = 0;
        $this->dispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (AuditLog $audit, EntityManagerInterface $entityManager, AuditPhase $phase, mixed $uow, object $resolvedEntity) use ($revertLog, $entity, &$call): bool {
                self::assertSame($revertLog, $audit);
                self::assertSame($entity, $resolvedEntity);
                ++$call;

                if ($call === 1) {
                    self::assertSame(AuditPhase::OnFlush, $phase);

                    return false;
                }

                self::assertSame(AuditPhase::PostFlush, $phase);

                return false;
            });

        try {
            $this->reverter->revert($log);
            self::fail('Expected RuntimeException to be thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('Old', $entity->name);
            self::assertStringContainsString('Revert committed', $exception->getMessage());
        }
    }

    public function testRevertRefreshesEntityWhenInTransactionDispatchFails(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditAction::Update,
            oldValues: ['name' => 'Old'],
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();

        $entity = new RevertTestUser();
        $entity->name = 'New';
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturnCallback(static fn (RevertTestUser $resolvedEntity, string $field): mixed => $field === 'name' ? $resolvedEntity->name : null);
        $metadata->expects($this->once())
            ->method('setFieldValue')
            ->with($entity, 'name', 'Old')
            ->willReturnCallback(static function (RevertTestUser $resolvedEntity, string $field, mixed $value): void {
                if ($field === 'name') {
                    $resolvedEntity->name = (string) $value;
                }
            });
        $this->em->method('getClassMetadata')->willReturn($metadata);
        $this->mockWrapInTransactionWithoutFlush();
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->em->method('isOpen')->willReturn(true);
        $this->em->expects($this->once())->method('refresh')->with($entity)->willReturnCallback(static function (RevertTestUser $resolvedEntity): void {
            $resolvedEntity->name = 'New';
        });
        $this->em->expects($this->never())->method('flush');

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($revertLog, $this->em, AuditPhase::OnFlush, null, $entity)
            ->willThrowException(new RuntimeException('Transport failed intentionally.'));

        try {
            $this->reverter->revert($log);
            self::fail('Expected RuntimeException to be thrown');
        } catch (RuntimeException $exception) {
            self::assertSame('New', $entity->name);
            self::assertSame('Transport failed intentionally.', $exception->getMessage());
        }
    }

    public function testRevertUpdateThrowsWhenEntityAlreadyMatchesTargetState(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditAction::Update,
            oldValues: ['name' => 'Old'],
        );

        $this->expectSoftDeleteFilterLifecycle();
        $this->expectAuditManagerLifecycle();
        $entity = new RevertTestUser();
        $entity->name = 'Old';
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturnCallback(static fn (RevertTestUser $resolvedEntity, string $field): mixed => $field === 'name' ? $resolvedEntity->name : null);
        $metadata->expects($this->never())->method('setFieldValue');
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already matches the target state');

        $this->reverter->revert($log);
    }

    public function testRevertAlreadyReverted(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditAction::Update, oldValues: ['name' => 'Old']);

        $this->auditManager->expects($this->never())->method('disable');
        $this->auditManager->expects($this->never())->method('enable');
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->dispatcher->expects($this->never())->method('dispatch');
        $this->em->expects($this->never())->method('persist');
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());
        $this->repository->method('isReverted')->willReturn(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already been reverted');

        $this->reverter->revert($log);
    }

    public function testRevertUsesResolvedEntityManagerForEntityClass(): void
    {
        $secondaryEntityManager = $this->createMock(EntityManagerInterface::class);
        $secondaryFilters = self::createStub(FilterCollection::class);
        $secondaryFilters->method('getEnabledFilters')->willReturn([]);
        $secondaryEntityManager->method('getFilters')->willReturn($secondaryFilters);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = $this->createReverter(
            $serializer,
            $this->createResolver([RevertTestUser::class => $secondaryEntityManager], $this->em),
        );

        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditAction::Update,
            oldValues: ['name' => 'Old'],
        );

        $entity = new RevertTestUser();
        $entity->name = 'New';
        $secondaryEntityManager->expects($this->once())
            ->method('find')
            ->with(RevertTestUser::class, '1')
            ->willReturn($entity);
        $this->em->expects($this->never())->method('find');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturnCallback(static fn (RevertTestUser $resolvedEntity, string $field): mixed => $field === 'name' ? $resolvedEntity->name : null);
        $metadata->expects($this->once())
            ->method('setFieldValue')
            ->with($entity, 'name', 'Old')
            ->willReturnCallback(static function (RevertTestUser $resolvedEntity, string $field, mixed $value): void {
                if ($field === 'name') {
                    $resolvedEntity->name = (string) $value;
                }
            });
        $secondaryEntityManager->expects($this->atLeast(2))
            ->method('getClassMetadata')
            ->with(RevertTestUser::class)
            ->willReturn($metadata);
        $secondaryEntityManager->method('wrapInTransaction')->willReturnCallback(static function (callable $callback) use ($secondaryEntityManager): mixed {
            $result = $callback($secondaryEntityManager);
            $secondaryEntityManager->flush();

            return $result;
        });
        $secondaryEntityManager->expects($this->once())->method('flush');
        $this->em->expects($this->never())->method('wrapInTransaction');

        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditAction::Revert, oldValues: ['name' => 'Old']);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($revertLog, $secondaryEntityManager, AuditPhase::OnFlush, null, $entity)
            ->willReturn(true);

        $this->expectAuditManagerLifecycle();

        $changes = $this->reverter->revert($log);

        self::assertSame(['name' => 'Old'], $changes);
    }

    private function setLogId(AuditLog $log, Uuid $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setValue($log, $id);
    }

    private function expectSoftDeleteFilterLifecycle(): void
    {
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
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

    private function mockWrapInTransactionWithFlush(): void
    {
        $this->em->method('wrapInTransaction')->willReturnCallback(function (callable $callback): mixed {
            $result = $callback($this->em);
            $this->em->flush();

            return $result;
        });
    }

    private function mockWrapInTransactionWithoutFlush(): void
    {
        $this->em->method('wrapInTransaction')->willReturnCallback(fn (callable $callback): mixed => $callback($this->em));
    }

    /**
     * @param array<class-string<object>, EntityManagerInterface> $managerByClass
     */
    private function createResolver(array $managerByClass = [], ?EntityManagerInterface $defaultManager = null): EntityManagerResolver
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            static fn (string $class): ?EntityManagerInterface => $managerByClass[$class] ?? $defaultManager
        );

        return new EntityManagerResolver($registry);
    }
}
