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
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteHandler;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummySoftDeleteableFilter;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\RevertTestUser;
use RuntimeException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

// Mock class for testing if not present
if (!class_exists('Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter')) {
    class_alias(DummySoftDeleteableFilter::class, 'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter');
}

#[AllowMockObjectsWithoutExpectations()]
class AuditReverterTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    private ValidatorInterface&MockObject $validator;

    private AuditService&MockObject $auditService;

    private FilterCollection&MockObject $filterCollection;

    private SoftDeleteHandler&MockObject $softDeleteHandler;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->filterCollection = $this->createMock(FilterCollection::class);
        $this->softDeleteHandler = $this->createMock(SoftDeleteHandler::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);

        $this->em->method('getFilters')->willReturn($this->filterCollection);

        $this->reverter = new AuditReverter(
            $this->em,
            $this->validator,
            $this->auditService,
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $this->integrityService
        );
    }

    public function testRevertEntityNotFound(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->reverter->revert($log);
    }

    public function testRevertDryRun(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setOldValues(['name' => 'Old']);

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
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_DELETE);

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not supported');

        $this->reverter->revert($log);
    }

    public function testRevertUpdateNoOldValues(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setOldValues([]);

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn(new RevertTestUser());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No old values');

        $this->reverter->revert($log);
    }

    public function testRevertSoftDeleteNotDeleted(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_SOFT_DELETE);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(false);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());

        $revertLog = new AuditLog();
        $this->auditService->expects($this->once())->method('createAuditLog')->willReturn($revertLog);
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $changes = $this->reverter->revert($log);
        self::assertEquals(['info' => 'Entity is not soft-deleted.'], $changes);
    }

    public function testValidationFailure(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setOldValues(['name' => 'Old']);

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
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setOldValues(['id' => 1, 'unchanged' => 'val', 'changed' => 'old']);

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

        $revertLog = new AuditLog();
        $this->auditService->method('createAuditLog')->willReturn($revertLog);

        $this->em->expects($this->exactly(2))->method('persist'); // Entity and RevertLog
        $this->em->expects($this->exactly(2))->method('flush');

        $changes = $this->reverter->revert($log);

        self::assertEquals(['changed' => 'old'], $changes);
        self::assertArrayNotHasKey('id', $changes);
        self::assertArrayNotHasKey('unchanged', $changes);

        // Verify revert log content
        self::assertEquals(['changed' => 'old'], $revertLog->getOldValues());
        self::assertEquals(RevertTestUser::class, $revertLog->getEntityClass());
        self::assertEquals('1', $revertLog->getEntityId());
    }

    public function testRevertCreateSuccess(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_CREATE);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->em->expects($this->once())->method('remove')->with($entity);

        $this->auditService->method('createAuditLog')->willReturn(new AuditLog());

        $changes = $this->reverter->revert($log, false, true);
        self::assertEquals(['action' => 'delete'], $changes);
    }

    public function testRevertSoftDeleteSuccess(): void
    {
        $log = new AuditLog();
        $log->setEntityClass(RevertTestUser::class);
        $log->setEntityId('1');
        $log->setAction(AuditLogInterface::ACTION_SOFT_DELETE);

        $entity = new RevertTestUser();
        $entity->setDeletedAt(new DateTime());

        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $this->softDeleteHandler->method('isSoftDeleted')->with($entity)->willReturn(true);
        $this->softDeleteHandler->expects($this->once())->method('restoreSoftDeleted')->with($entity);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());
        $this->auditService->method('createAuditLog')->willReturn(new AuditLog());

        $changes = $this->reverter->revert($log);
        self::assertEquals(['action' => 'restore'], $changes);
    }

    public function testRevertWithCustomContext(): void
    {
        $log = $this->createMock(AuditLogInterface::class);
        $log->method('getEntityClass')->willReturn(RevertTestUser::class);
        $log->method('getEntityId')->willReturn('1');
        $log->method('getAction')->willReturn(AuditLogInterface::ACTION_UPDATE);
        $log->method('getOldValues')->willReturn(['name' => 'Old']);
        $log->method('getId')->willReturn(123);

        $entity = new RevertTestUser();
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('find')->willReturn($entity);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $this->em->method('getClassMetadata')->willReturn($metadata);

        $this->em->method('wrapInTransaction')->willReturnCallback(static fn ($c) => $c());
        $this->validator->method('validate')->willReturn(new ConstraintViolationList());

        $revertLog = new AuditLog();
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with(
                $entity,
                AuditLogInterface::ACTION_REVERT,
                ['name' => 'Old'],
                null,
                ['custom_key' => 'custom_val', 'reverted_log_id' => 123]
            )
            ->willReturn($revertLog);

        $this->reverter->revert($log, false, false, ['custom_key' => 'custom_val']);
    }
}
