<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\Mapping\MappingException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\DoctrineEntityIdentifierExtractor;
use Rcsofttech\AuditTrailBundle\Service\EntityIdentifierFormatter;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityManagerResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityPayloadIdentifierResolver;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use stdClass;

final class EntityIdResolverTest extends TestCase
{
    private EntityIdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = $this->createEntityIdResolver();
    }

    public function testResolveNotPendingIsInsert(): void
    {
        $log = new AuditLog('App\Entity\User', '123', AuditAction::Create);

        self::assertSame('123', $this->resolver->resolve($log, $this->createContext(AuditPhase::OnFlush, $log)));
    }

    public function testResolveNotPendingNotInsert(): void
    {
        $log = new AuditLog('App\Entity\User', '123', AuditAction::Update);

        self::assertNull($this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log)));
    }

    public function testResolvePendingMissingContext(): void
    {
        $log = new AuditLog('App\Entity\User', null, AuditAction::Create);

        self::assertNull($this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log)));
    }

    public function testResolvePendingSingleId(): void
    {
        $log = new AuditLog(stdClass::class, null, AuditAction::Create);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 123]);

        self::assertSame('123', $this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolvePendingCompositeId(): void
    {
        $log = new AuditLog(stdClass::class, null, AuditAction::Create);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id1' => 1, 'id2' => 2]);

        self::assertSame('["1","2"]', $this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolvePendingCompositeIdWithAssociationIdentifiers(): void
    {
        $log = new AuditLog(stdClass::class, null, AuditAction::Create);

        $entity = new stdClass();
        $user = new class {};
        $workspace = new class {};
        $em = self::createStub(EntityManagerInterface::class);
        $rootMetadata = self::createStub(ClassMetadata::class);
        $userMetadata = self::createStub(ClassMetadata::class);
        $workspaceMetadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => match ($class) {
            $entity::class => $rootMetadata,
            $user::class => $userMetadata,
            $workspace::class => $workspaceMetadata,
            default => throw new LogicException('Unexpected metadata lookup for '.$class),
        });
        $rootMetadata->method('getIdentifierValues')->willReturn(['user' => $user, 'workspace' => $workspace]);
        $userMetadata->method('getIdentifierValues')->willReturn(['id' => 7]);
        $workspaceMetadata->method('getIdentifierValues')->willReturn(['id' => 13]);

        self::assertSame('["7","13"]', $this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolvePendingNoId(): void
    {
        $log = new AuditLog(stdClass::class, null, AuditAction::Create);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn([]);

        self::assertNull($this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolveFromEntity(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 456]);

        $resolver = $this->createEntityIdResolver($em);
        self::assertSame('456', $resolver->resolveFromEntity($entity));
    }

    public function testResolveFromEntityUsesResolvedEntityManagerWhenDefaultIsMissing(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 654]);

        $resolver = $this->createEntityIdResolver(
            null,
            null,
            $this->createResolver($entity::class, $em),
        );

        self::assertSame('654', $resolver->resolveFromEntity($entity));
    }

    public function testResolveFromValues(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);

        self::assertSame('789', $this->resolver->resolveFromValues($entity, ['id' => 789], $em));
    }

    public function testResolveFromValuesNormalizesAssociationIdentifiers(): void
    {
        $entity = new stdClass();
        $user = new class {};
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $userMetadata = self::createStub(ClassMetadata::class);
        $em->method('getClassMetadata')->willReturnCallback(static fn (string $class): ClassMetadata => match ($class) {
            $entity::class => $metadata,
            $user::class => $userMetadata,
            default => throw new LogicException('Unexpected metadata lookup for '.$class),
        });
        $metadata->method('getIdentifierFieldNames')->willReturn(['user', 'id2']);
        $userMetadata->method('getIdentifierValues')->willReturn(['id' => 42]);

        self::assertSame('["42","789"]', $this->resolver->resolveFromValues($entity, ['user' => $user, 'id2' => 789], $em));
    }

    public function testResolveFromEntityReturnsPendingWhenMetadataAndIdentityMapDoNotResolveId(): void
    {
        $entity = new class {};
        $uow = self::createStub(UnitOfWork::class);
        $uow->method('isInIdentityMap')->willReturn(false);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn([]);

        $em = self::createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willReturn($metadata);
        $em->method('getUnitOfWork')->willReturn($uow);

        $resolver = $this->createEntityIdResolver($em);

        self::assertNull($resolver->resolveFromEntity($entity));
    }

    public function testResolveFromEntityReturnsPendingWhenAllStrategiesFail(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $uow = self::createStub(UnitOfWork::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $em->method('getUnitOfWork')->willReturn($uow);
        $metadata->method('getIdentifierValues')->willReturn([]);
        $metadata->method('getIdentifierFieldNames')->willReturn([]);
        $uow->method('isInIdentityMap')->willReturn(false);

        $resolver = $this->createEntityIdResolver($em);

        self::assertNull($resolver->resolveFromEntity($entity));
    }

    public function testResolveFromEntityDoesNotFallbackToArbitraryGetIdMethod(): void
    {
        $entity = new class {
            public function getId(): string
            {
                return 'legacy-id';
            }
        };
        $uow = self::createStub(UnitOfWork::class);
        $uow->method('isInIdentityMap')->willReturn(false);

        $metadata = self::createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn([]);

        $em = self::createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willReturn($metadata);
        $em->method('getUnitOfWork')->willReturn($uow);

        $resolver = $this->createEntityIdResolver($em);

        self::assertNull($resolver->resolveFromEntity($entity));
    }

    public function testResolveFromEntitySkipsLoggingForTransientClasses(): void
    {
        $entity = new stdClass();
        $metadataFactory = self::createMock(ClassMetadataFactory::class);
        $em = self::createMock(EntityManagerInterface::class);
        $logger = self::createMock(LoggerInterface::class);

        $metadataFactory->expects($this->once())
            ->method('isTransient')
            ->with($entity::class)
            ->willReturn(true);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);
        $em->expects($this->never())->method('getClassMetadata');
        $logger->expects($this->never())->method('debug');

        $resolver = $this->createEntityIdResolver($em, $logger);

        self::assertNull($resolver->resolveFromEntity($entity));
    }

    public function testResolveFromEntitySkipsLoggingForMappingExceptions(): void
    {
        $entity = new stdClass();
        $metadataFactory = self::createMock(ClassMetadataFactory::class);
        $em = self::createMock(EntityManagerInterface::class);
        $logger = self::createMock(LoggerInterface::class);

        $metadataFactory->expects($this->once())
            ->method('isTransient')
            ->with($entity::class)
            ->willReturn(false);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);
        $em->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willThrowException(MappingException::nonExistingClass($entity::class));
        $logger->expects($this->never())->method('debug');

        $resolver = $this->createEntityIdResolver($em, $logger);

        self::assertNull($resolver->resolveFromEntity($entity));
    }

    public function testResolveFromEntityLogsDebugWhenMetadataLookupFails(): void
    {
        $entity = new stdClass();
        $metadataFactory = self::createMock(ClassMetadataFactory::class);
        $em = self::createMock(EntityManagerInterface::class);
        $logger = self::createMock(LoggerInterface::class);

        $metadataFactory->expects($this->once())
            ->method('isTransient')
            ->with($entity::class)
            ->willReturn(false);
        $em->method('getMetadataFactory')->willReturn($metadataFactory);
        $em->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willThrowException(new LogicException('metadata unavailable'));

        $logger->expects($this->once())
            ->method('debug')
            ->with(
                'Unable to read Doctrine metadata while resolving audit entity identifier.',
                self::callback(static function (array $context) use ($entity): bool {
                    return $context['entity_class'] === $entity::class
                        && $context['exception'] instanceof LogicException;
                }),
            );

        $resolver = $this->createEntityIdResolver($em, $logger);

        self::assertNull($resolver->resolveFromEntity($entity));
    }

    public function testResolveFromValuesLogsWarningWhenIdentifierValuesCannotBeNormalized(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $logger = self::createMock(LoggerInterface::class);
        $messages = [];

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);

        $logger->expects($this->atLeastOnce())
            ->method('debug')
            ->willReturnCallback(static function (string $message, array $context) use (&$messages): void {
                $messages[] = [$message, $context];
            });

        $resolver = $this->createEntityIdResolver(null, $logger);

        self::assertNull($resolver->resolveFromValues($entity, ['id' => new stdClass()], $em));
        self::assertContains(
            [
                'Unable to resolve identifier values from audit payload.',
                [
                    'entity_class' => stdClass::class,
                    'identifier_fields' => ['id'],
                ],
            ],
            $messages,
        );
    }

    private function createContext(
        AuditPhase $phase,
        AuditLog $log,
        ?EntityManagerInterface $em = null,
        ?object $entity = null,
    ): AuditTransportContext {
        return new AuditTransportContext(
            $phase,
            $em ?? self::createStub(EntityManagerInterface::class),
            $log,
            null,
            $entity,
        );
    }

    private function createResolver(string $class, EntityManagerInterface $entityManager): EntityManagerResolver
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            static fn (string $resolvedClass): ?EntityManagerInterface => $resolvedClass === $class ? $entityManager : null
        );

        return new EntityManagerResolver($registry);
    }

    private function createEntityIdResolver(
        ?EntityManagerInterface $entityManager = null,
        ?LoggerInterface $logger = null,
        ?EntityManagerResolver $entityManagerResolver = null,
    ): EntityIdResolver {
        $identifierExtractor = new DoctrineEntityIdentifierExtractor($logger);
        $identifierFormatter = new EntityIdentifierFormatter($identifierExtractor, $logger);
        $payloadIdentifierResolver = new EntityPayloadIdentifierResolver($identifierExtractor, $identifierFormatter, $logger);

        return new EntityIdResolver(
            $identifierExtractor,
            $identifierFormatter,
            $payloadIdentifierResolver,
            $entityManagerResolver ?? ($entityManager !== null
                ? $this->createResolverForAnyClass($entityManager)
                : new EntityManagerResolver()),
        );
    }

    private function createResolverForAnyClass(EntityManagerInterface $entityManager): EntityManagerResolver
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new EntityManagerResolver($registry);
    }
}
