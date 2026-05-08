<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\EntityClassResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityManagerResolver;
use RuntimeException;
use stdClass;

final class EntityClassResolverTest extends TestCase
{
    public function testResolveUsesProvidedEntityManagerToNormalizeRuntimeSubclass(): void
    {
        $entity = new class extends stdClass {
        };
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->once())
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willReturn(new ClassMetadata(stdClass::class));

        $resolver = new EntityClassResolver();

        self::assertSame(stdClass::class, $resolver->resolve($entity, $entityManager));
    }

    public function testResolveFallsBackToManagedParentClassWhenRuntimeSubclassIsNotRegistered(): void
    {
        $entity = new class extends stdClass {
        };
        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('getClassMetadata')->willReturnCallback(
            static function (string $class): ClassMetadata {
                if ($class === stdClass::class) {
                    return new ClassMetadata(stdClass::class);
                }

                throw new RuntimeException('Unmapped runtime subclass.');
            }
        );

        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturnCallback(
            static fn (string $class): ?EntityManagerInterface => $class === stdClass::class ? $entityManager : null
        );

        $resolver = new EntityClassResolver(new EntityManagerResolver($registry));

        self::assertSame(stdClass::class, $resolver->resolve($entity));
    }

    public function testResolveFallsBackToRuntimeClassWhenNoEntityManagerCanBeResolved(): void
    {
        $entity = new class extends stdClass {
        };

        $resolver = new EntityClassResolver();

        self::assertSame($entity::class, $resolver->resolve($entity));
    }
}
