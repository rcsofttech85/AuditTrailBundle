<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
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

    private FilterCollection $filterCollection;

    private AuditReverter $reverter;

    protected function setUp(): void
    {
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->softDeleteHandler = self::createStub(SoftDeleteHandlerInterface::class);
        $this->filterCollection = self::createStub(FilterCollection::class);
        $this->filterCollection->method('getEnabledFilters')->willReturn([]);
        $this->em->method('getFilters')->willReturn($this->filterCollection);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $this->reverter = $this->createReverter($this->integrityService, $serializer);
    }

    private function createReverter(
        AuditIntegrityServiceInterface $integrityService,
        ?ValueSerializerInterface $serializer = null,
    ): AuditReverter {
        if ($serializer === null) {
            $serializer = self::createStub(ValueSerializerInterface::class);
            $serializer->method('serialize')->willReturnArgument(0);
        }

        $resolver = $this->createResolver();
        $denormalizer = new RevertValueDenormalizer(
            $resolver,
            new RevertDateTimeValueDenormalizer(),
            new EntityIdentifierNormalizer($resolver),
        );
        $collectionSynchronizer = new RevertCollectionAssociationSynchronizer($resolver, new AssociationMutatorInvoker());

        return new AuditReverter(
            $resolver,
            self::createStub(ValidatorInterface::class),
            $denormalizer,
            new SoftDeleteFilterManager(['softdeleteable']),
            self::createStub(ScheduledAuditManagerInterface::class),
            new RevertGuard($integrityService, self::createStub(AuditLogRepositoryInterface::class)),
            new RevertEntityStateApplier($resolver, $this->softDeleteHandler, $collectionSynchronizer),
            new RevertAuditLogCreator(
                self::createStub(AuditServiceInterface::class),
                $serializer,
            ),
            self::createStub(AuditDispatcherInterface::class),
            [
                new RevertCreateActionHandler(),
                new RevertUpdateActionHandler(
                    new RevertPlanBuilder($resolver, $denormalizer, $serializer, $collectionSynchronizer),
                ),
                new RevertSoftDeleteActionHandler($this->softDeleteHandler),
                new RevertAccessActionHandler(),
            ],
        );
    }

    private function createResolver(): EntityManagerResolver
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($this->em);

        return new EntityManagerResolver($registry);
    }

    public function testRevertFailsIfTampered(): void
    {
        $log = new AuditLog(entityClass: DummyEntity::class, entityId: '1', action: AuditAction::Update);

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())->method('verifySignature')->with($log)->willReturn(false);
        $this->reverter = $this->createReverter($integrityService);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has been tampered with');

        $this->reverter->revert($log);
    }

    public function testRevertSucceedsIfAuthentic(): void
    {
        $log = new AuditLog(
            entityClass: DummyEntity::class,
            entityId: '1',
            action: AuditAction::Update,
            oldValues: ['name' => 'John']
        );

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())->method('verifySignature')->with($log)->willReturn(true);
        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = $this->createReverter($integrityService, $serializer);

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
            action: AuditAction::Update,
            oldValues: ['name' => 'John']
        );

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(false);
        $integrityService->expects($this->never())->method('verifySignature');
        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = $this->createReverter($integrityService, $serializer);

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
        $log = new AuditLog(entityClass: DummyEntity::class, entityId: '1', action: AuditAction::Revert);

        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())->method('verifySignature')->with($log)->willReturn(true);
        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);
        $this->reverter = $this->createReverter($integrityService, $serializer);

        $this->em->method('find')->willReturn(new DummyEntity());

        // It should pass integrity check and fail on unsupported action
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Reverting action "revert" is not supported.');

        $this->reverter->revert($log);
    }
}
