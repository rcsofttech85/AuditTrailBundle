<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\EntityManagerResolver;
use Rcsofttech\AuditTrailBundle\Service\SoftDeleteHandler;

final class SoftDeleteHandlerTest extends TestCase
{
    public function testRestoreSoftDeletedUsesConfiguredFieldMetadata(): void
    {
        $entity = new SoftDeleteFieldEntity();
        $entity->archivedAt = new DateTimeImmutable();

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturnMap([
            ['archivedAt', true],
        ]);
        $metadata->method('hasAssociation')->willReturn(false);
        $metadata->expects($this->exactly(2))
            ->method('getFieldValue')
            ->with($entity, 'archivedAt')
            ->willReturnCallback(static fn (SoftDeleteFieldEntity $resolvedEntity): mixed => $resolvedEntity->archivedAt);
        $metadata->expects($this->once())
            ->method('setFieldValue')
            ->with($entity, 'archivedAt', null)
            ->willReturnCallback(static function (SoftDeleteFieldEntity $resolvedEntity, string $field, mixed $value): void {
                $resolvedEntity->archivedAt = $value;
            });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->exactly(3))
            ->method('getClassMetadata')
            ->with($entity::class)
            ->willReturn($metadata);

        $handler = $this->createHandler($this->createResolver($entityManager), 'archivedAt');

        self::assertTrue($handler->isSoftDeleted($entity));

        $handler->restoreSoftDeleted($entity);

        self::assertNull($entity->archivedAt);
        self::assertFalse($handler->isSoftDeleted($entity));
    }

    private function createHandler(
        ?EntityManagerResolver $resolver = null,
        string $softDeleteField = 'deletedAt',
    ): SoftDeleteHandler {
        return new SoftDeleteHandler(
            $resolver ?? $this->createResolver(),
            $softDeleteField,
        );
    }

    private function createResolver(?EntityManagerInterface $entityManager = null): EntityManagerResolver
    {
        $registry = self::createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        return new EntityManagerResolver($registry);
    }
}

final class SoftDeleteFieldEntity
{
    public ?DateTimeImmutable $archivedAt = null;
}
