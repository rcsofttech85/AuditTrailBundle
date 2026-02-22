<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
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
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummyEntity;
use RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[CoversClass(AuditReverter::class)]
#[AllowMockObjectsWithoutExpectations]
class AuditRevertIntegrityTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;

    private AuditIntegrityServiceInterface&MockObject $integrityService;

    private SoftDeleteHandlerInterface&MockObject $softDeleteHandler;

    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->softDeleteHandler = $this->createMock(SoftDeleteHandlerInterface::class);

        $this->softDeleteHandler->method('disableSoftDeleteFilters')->willReturn([]);

        $serializer = $this->createMock(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $this->reverter = new AuditReverter(
            $this->em,
            $this->createMock(ValidatorInterface::class),
            $this->createMock(AuditServiceInterface::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $this->integrityService,
            $this->createMock(AuditDispatcherInterface::class),
            $serializer,
        );
    }

    public function testRevertFailsIfTampered(): void
    {
        $log = new AuditLog(entityClass: DummyEntity::class, entityId: '1', action: AuditLogInterface::ACTION_UPDATE);

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has been tampered with');

        $this->reverter->revert($log);
    }

    public function testRevertSucceedsIfAuthentic(): void
    {
        $log = new AuditLog(
            entityClass: DummyEntity::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'John']
        );

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(true);

        $this->em->method('find')->willReturn(new DummyEntity());
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn('different');
        $this->em->method('getClassMetadata')->willReturn($meta);

        $changes = $this->reverter->revert($log, true);
        self::assertNotEmpty($changes);
    }

    public function testRevertSucceedsIfIntegrityDisabled(): void
    {
        $log = new AuditLog(
            entityClass: DummyEntity::class,
            entityId: '1',
            action: AuditLogInterface::ACTION_UPDATE,
            oldValues: ['name' => 'John']
        );

        $this->integrityService->method('isEnabled')->willReturn(false);
        $this->integrityService->expects($this->never())->method('verifySignature');

        $this->em->method('find')->willReturn(new DummyEntity());
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn('different');
        $this->em->method('getClassMetadata')->willReturn($meta);

        $changes = $this->reverter->revert($log, true);
        self::assertNotEmpty($changes);
    }

    public function testRevertSucceedsForRevertActionWithoutSignature(): void
    {
        $log = new AuditLog(entityClass: DummyEntity::class, entityId: '1', action: AuditLogInterface::ACTION_REVERT);

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(true);

        $this->em->method('find')->willReturn(new DummyEntity());

        // It should pass integrity check and fail on unsupported action
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reverting action "revert" is not supported.');

        $this->reverter->revert($log);
    }
}
