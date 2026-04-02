<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummyEntity;
use RuntimeException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class AuditRevertIntegrityTest extends TestCase
{
    /** @var (EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(EntityManagerInterface&MockObject) */
    private EntityManagerInterface $em;

    /** @var (AuditIntegrityServiceInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditIntegrityServiceInterface&MockObject) */
    private AuditIntegrityServiceInterface $integrityService;

    /** @var SoftDeleteHandlerInterface&\PHPUnit\Framework\MockObject\Stub */
    private SoftDeleteHandlerInterface $softDeleteHandler;

    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->softDeleteHandler = self::createStub(SoftDeleteHandlerInterface::class);

        $this->softDeleteHandler->method('disableSoftDeleteFilters')->willReturn([]);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $this->reverter = new AuditReverter(
            $this->em,
            self::createStub(ValidatorInterface::class),
            self::createStub(AuditServiceInterface::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $this->integrityService,
            self::createStub(AuditDispatcherInterface::class),
            $serializer,
            self::createStub(ScheduledAuditManagerInterface::class),
            self::createStub(AuditLogRepositoryInterface::class)
        );
    }

    public function testRevertFailsIfTampered(): void
    {
        $log = new AuditLog(entityClass: DummyEntity::class, entityId: '1', action: AuditLogInterface::ACTION_UPDATE);

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())->method('verifySignature')->with($log)->willReturn(false);
        $this->reverter = new AuditReverter(
            $this->em,
            self::createStub(ValidatorInterface::class),
            self::createStub(AuditServiceInterface::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $integrityService,
            self::createStub(AuditDispatcherInterface::class),
            self::createStub(ValueSerializerInterface::class),
            self::createStub(ScheduledAuditManagerInterface::class),
            self::createStub(AuditLogRepositoryInterface::class)
        );

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

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())->method('verifySignature')->with($log)->willReturn(true);
        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = new AuditReverter(
            $this->em,
            self::createStub(ValidatorInterface::class),
            self::createStub(AuditServiceInterface::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $integrityService,
            self::createStub(AuditDispatcherInterface::class),
            $serializer,
            self::createStub(ScheduledAuditManagerInterface::class),
            self::createStub(AuditLogRepositoryInterface::class)
        );

        $this->em->method('find')->willReturn(new DummyEntity());
        $meta = self::createStub(ClassMetadata::class);
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

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(false);
        $integrityService->expects($this->never())->method('verifySignature');
        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = new AuditReverter(
            $this->em,
            self::createStub(ValidatorInterface::class),
            self::createStub(AuditServiceInterface::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $integrityService,
            self::createStub(AuditDispatcherInterface::class),
            $serializer,
            self::createStub(ScheduledAuditManagerInterface::class),
            self::createStub(AuditLogRepositoryInterface::class)
        );

        $this->em->method('find')->willReturn(new DummyEntity());
        $meta = self::createStub(ClassMetadata::class);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn('different');
        $this->em->method('getClassMetadata')->willReturn($meta);

        $changes = $this->reverter->revert($log, true);
        self::assertNotEmpty($changes);
    }

    public function testRevertSucceedsForRevertActionWithoutSignature(): void
    {
        $log = new AuditLog(entityClass: DummyEntity::class, entityId: '1', action: AuditLogInterface::ACTION_REVERT);

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())->method('verifySignature')->with($log)->willReturn(true);
        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = new AuditReverter(
            $this->em,
            self::createStub(ValidatorInterface::class),
            self::createStub(AuditServiceInterface::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $integrityService,
            self::createStub(AuditDispatcherInterface::class),
            $serializer,
            self::createStub(ScheduledAuditManagerInterface::class),
            self::createStub(AuditLogRepositoryInterface::class)
        );

        $this->em->method('find')->willReturn(new DummyEntity());

        // It should pass integrity check and fail on unsupported action
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reverting action "revert" is not supported.');

        $this->reverter->revert($log);
    }
}
