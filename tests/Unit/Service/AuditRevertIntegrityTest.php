<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditReverter;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteHandler;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummyEntity;

#[CoversClass(AuditReverter::class)]
#[AllowMockObjectsWithoutExpectations]
class AuditRevertIntegrityTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AuditIntegrityServiceInterface&MockObject $integrityService;
    private SoftDeleteHandler&MockObject $softDeleteHandler;
    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->integrityService = $this->createMock(AuditIntegrityServiceInterface::class);
        $this->softDeleteHandler = $this->createMock(SoftDeleteHandler::class);

        $this->softDeleteHandler->method('disableSoftDeleteFilters')->willReturn([]);

        $this->reverter = new AuditReverter(
            $this->em,
            $this->createMock(ValidatorInterface::class),
            $this->createMock(AuditService::class),
            new RevertValueDenormalizer($this->em),
            $this->softDeleteHandler,
            $this->integrityService
        );
    }

    public function testRevertFailsIfTampered(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setEntityClass(DummyEntity::class);
        $log->setEntityId('1');

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has been tampered with');

        $this->reverter->revert($log);
    }

    public function testRevertSucceedsIfAuthentic(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setEntityClass(DummyEntity::class);
        $log->setEntityId('1');
        $log->setOldValues(['name' => 'John']);

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(true);

        $this->em->method('find')->willReturn(new DummyEntity());
        $meta = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn('different');
        $this->em->method('getClassMetadata')->willReturn($meta);

        $changes = $this->reverter->revert($log, true);
        self::assertNotEmpty($changes);
    }

    public function testRevertSucceedsIfIntegrityDisabled(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_UPDATE);
        $log->setEntityClass(DummyEntity::class);
        $log->setEntityId('1');
        $log->setOldValues(['name' => 'John']);

        $this->integrityService->method('isEnabled')->willReturn(false);
        $this->integrityService->expects($this->never())->method('verifySignature');

        $this->em->method('find')->willReturn(new DummyEntity());
        $meta = $this->createMock(\Doctrine\ORM\Mapping\ClassMetadata::class);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn('different');
        $this->em->method('getClassMetadata')->willReturn($meta);

        $changes = $this->reverter->revert($log, true);
        self::assertNotEmpty($changes);
    }

    public function testRevertSucceedsForRevertActionWithoutSignature(): void
    {
        $log = new AuditLog();
        $log->setAction(AuditLogInterface::ACTION_REVERT);
        $log->setEntityClass(DummyEntity::class);
        $log->setEntityId('1');

        $this->integrityService->method('isEnabled')->willReturn(true);
        $this->integrityService->method('verifySignature')->with($log)->willReturn(true);

        $this->em->method('find')->willReturn(new DummyEntity());

        // It should pass integrity check and fail on unsupported action
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Reverting action "revert" is not supported.');

        $this->reverter->revert($log);
    }
}
