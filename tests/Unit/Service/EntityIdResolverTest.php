<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use ReflectionProperty;
use stdClass;

final class EntityIdResolverTest extends TestCase
{
    private EntityIdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EntityIdResolver();
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
        $log = new AuditLog('App\Entity\User', 'pending', AuditAction::Create);

        self::assertNull($this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log)));
    }

    public function testResolvePendingSingleId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditAction::Create);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 123]);

        self::assertSame('123', $this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolvePendingCompositeId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditAction::Create);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id1' => 1, 'id2' => 2]);

        self::assertSame('["1","2"]', $this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolvePendingCompositeIdWithAssociationIdentifiers(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditAction::Create);

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
        $log = new AuditLog(stdClass::class, 'pending', AuditAction::Create);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn([]);

        self::assertSame('pending', $this->resolver->resolve($log, $this->createContext(AuditPhase::PostFlush, $log, $em, $entity)));
    }

    public function testResolveFromEntity(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 456]);

        $resolver = new EntityIdResolver($em);
        self::assertSame('456', $resolver->resolveFromEntity($entity));
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

    public function testResolveFromEntityReusesMetadataForReflectionFallback(): void
    {
        $entity = new class {
            private int $id = 123;

            public function hasIdentifier(): bool
            {
                return $this->id > 0;
            }
        };
        $uow = self::createStub(UnitOfWork::class);
        $uow->method('isInIdentityMap')->willReturn(false);

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->expects($this->once())
            ->method('getIdentifierValues')
            ->with($entity)
            ->willReturn([]);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $metadata->expects($this->once())
            ->method('getReflectionProperty')
            ->with('id')
            ->willReturn(new ReflectionProperty($entity, 'id'));

        $em = self::createMock(EntityManagerInterface::class);
        $em->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willReturn($metadata);
        $em->method('getUnitOfWork')->willReturn($uow);

        $resolver = new EntityIdResolver($em);

        self::assertTrue($entity->hasIdentifier());
        self::assertSame('123', $resolver->resolveFromEntity($entity));
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
}
