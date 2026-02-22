<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummySoftDeleteableFilter;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\RevertTestUser;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// Mock class ChangeProcessor implements ChangeProcessorInterface
// Mock class for testing if not present
if (!class_exists('Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter')) {
    class_alias(DummySoftDeleteableFilter::class, 'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter');
}

#[AllowMockObjectsWithoutExpectations()]
class AuditReverterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    private ValidatorInterface&MockObject $validator;

    private AuditServiceInterface&MockObject $auditService;

    private FilterCollection&MockObject $filterCollection;

    private SoftDeleteHandlerInterface&MockObject $softDeleteHandler;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private AuditDispatcherInterface&MockObject $dispatcher;

    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->filterCollection = $this->createMock(FilterCollection::class);
        $this->softDeleteHandler = $this->createMock(SoftDeleteHandlerInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->dispatcher = $this->createMock(AuditDispatcherInterface::class);

        $this->em->method('getFilters')->willReturn($this->filterCollection);

        $serializer = $this->createMock(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $this->reverter = new AuditReverter(
            $this->em,
            $this->validator,
            $this->auditService,
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $this->integrityService,
            $this->dispatcher,
            $serializer
        );
    }

    public function testRevertEntityNotFound(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_UPDATE);

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

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('isIdentifier')->willReturn(false);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getFieldValue')->willReturn('New');
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->expects($this->never())->method('persist');
        $this->em->expects($this->never())->method('flush');

        $changes = $this->reverter->revert($log, true);
        self::assertEquals(['name' => 'Old'], $changes);
    }

    public function testRevertUnsupportedAction(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_DELETE);

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

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No old values');

        $this->reverter->revert($log);
    }

    public function testRevertSoftDeleteNotDeleted(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(false);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, 'post_flush');
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $changes = $this->reverter->revert($log);
        self::assertEquals(['info' => 'Entity is not soft-deleted.'], $changes);
    }

    public function testValidationFailure(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'Old']
        );

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());

        $violations = new ConstraintViolationList();
        $violations->add(new ConstraintViolation('Error', null, [], null, 'path', 'val'));
        $this->validator->method('validate')->willReturn($violations);

        $this->expectException(RuntimeException::class);
        $this->reverter->revert($log);
    }

    public function testApplyChangesSkipping(): void
    {
        $log = new AuditLog(
            entityClass: RevertTestUser::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['id' => 1, 'unchanged' => 'val', 'changed' => 'old']
        );

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

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT, oldValues: ['changed' => 'old']);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditLogInterface::ACTION_REVERT, ['changed' => 'old'], null)
            ->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, 'post_flush');

        $this->em->expects($this->once())->method('persist'); // Only Entity (RevertLog is dispatched)
        $this->em->expects($this->once())->method('flush');

        $changes = $this->reverter->revert($log);

        self::assertEquals(['changed' => 'old'], $changes);
        self::assertArrayNotHasKey('id', $changes);
        self::assertArrayNotHasKey('unchanged', $changes);
    }

    public function testRevertCreateSuccess(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_CREATE);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->em->expects($this->once())->method('remove')->with($entity);

        $this->auditService->method('createAuditLog')->willReturn(new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT));

        $changes = $this->reverter->revert($log, false, true);
        self::assertEquals(['action' => 'delete'], $changes);
    }

    public function testRevertSoftDeleteSuccess(): void
    {
        $log = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_SOFT_DELETE);

        $entity = new RevertTestUser();
        $entity->setDeletedAt(new DateTime());

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(true);
        $this->softDeleteHandler->expects($this->once())->method('restoreSoftDeleted')->with($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->auditService->method('createAuditLog')->willReturn(new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT));

        $changes = $this->reverter->revert($log);
        self::assertEquals(['action' => 'restore'], $changes);
    }

    public function testRevertWithCustomContext(): void
    {
        $id = Uuid::v4();
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

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog(RevertTestUser::class, '1', AuditLogInterface::ACTION_REVERT);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $entity,
                AuditLogInterface::ACTION_REVERT,
                ['name' => 'Old'],
                null,
                ['custom_key' => 'custom_val', 'reverted_log_id' => $id->toRfc4122()]
            )
            ->willReturn($revertLog);

        $this->dispatcher->expects($this->once())->method('dispatch')->with($revertLog, $this->em, 'post_flush');

        $this->reverter->revert($log, false, false, ['custom_key' => 'custom_val']);
    }

    private function setLogId(AuditLog $log, Uuid $id): void
    {
        $reflection = new ReflectionClass($log);
        $property = $reflection->getProperty('id');
        $property->setAccessible(true);
        $property->setValue($log, $id);
    }
}
