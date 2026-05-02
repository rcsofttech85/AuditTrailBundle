<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use stdClass;

final class ChangeProcessorTest extends TestCase
{
    public function testExtractChanges(): void
    {
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $processor = $this->createProcessor($metadataManager);
        $entity = new stdClass();
        $changeSet = [
            'name' => ['old', 'new'],
            'age' => [20, 21],
            'ignored' => 'not_array',
            'same' => ['val', 'val'],
            'password' => ['old_pass', 'new_pass'],
        ];

        $metadataManager->method('getSensitiveFields')->willReturn(['password' => '***']);
        $metadataManager->method('getIgnoredProperties')->willReturn([]);

        $changes = $processor->extractChanges($entity, $changeSet);

        self::assertSame([
            'name' => 'old',
            'age' => 20,
            'password' => '***',
        ], $changes[0]);

        self::assertSame([
            'name' => 'new',
            'age' => 21,
            'password' => '***',
        ], $changes[1]);
    }

    public function testExtractChangesFloatPrecision(): void
    {
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $processor = $this->createProcessor($metadataManager);
        $entity = new stdClass();
        $changeSet = [
            'float_diff' => [1.0, 1.1],
            'float_same' => [1.0000000001, 1.0000000002],
            'null_same' => [null, null],
        ];

        $metadataManager->method('getSensitiveFields')->willReturn([]);
        $metadataManager->method('getIgnoredProperties')->willReturn([]);

        $changes = $processor->extractChanges($entity, $changeSet);

        // float_diff should be present
        self::assertArrayHasKey('float_diff', $changes[0]);
        self::assertSame(1.0, $changes[0]['float_diff']);
        self::assertSame(1.1, $changes[1]['float_diff']);

        // float_same should be absent (difference < 1e-9)
        self::assertArrayNotHasKey('float_same', $changes[0]);
        self::assertArrayNotHasKey('float_same', $changes[1]);
    }

    public function testExtractChangesUsesRelativeToleranceForLargeFloats(): void
    {
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $processor = $this->createProcessor($metadataManager);
        $entity = new stdClass();
        $changeSet = [
            'large_float_same' => [1e15, 1e15 + 1],
            'large_float_diff' => [1e15, 1e15 + 1e7],
        ];

        $metadataManager->method('getSensitiveFields')->willReturn([]);
        $metadataManager->method('getIgnoredProperties')->willReturn([]);

        $changes = $processor->extractChanges($entity, $changeSet);

        self::assertArrayNotHasKey('large_float_same', $changes[0]);
        self::assertArrayHasKey('large_float_diff', $changes[0]);
    }

    public function testDetermineUpdateAction(): void
    {
        $processor = $this->createProcessor();
        // Normal update
        self::assertSame(
            AuditAction::Update,
            $processor->determineUpdateAction(['name' => ['old', 'new']])
        );

        // Restore (deletedAt: not null -> null)
        self::assertSame(
            AuditAction::Restore,
            $processor->determineUpdateAction(['deletedAt' => [new DateTime(), null]])
        );
        self::assertSame(
            AuditAction::SoftDelete,
            $processor->determineUpdateAction(['deletedAt' => [null, new DateTime()]])
        );
    }

    public function testDetermineUpdateActionSoftDeleteDisabled(): void
    {
        $processor = $this->createProcessor(enabledSoftDelete: false);

        self::assertSame(
            AuditAction::Update,
            $processor->determineUpdateAction(['deletedAt' => [new DateTime(), null]])
        );
    }

    public function testDetermineDeletionActionSoftDelete(): void
    {
        $processor = $this->createProcessor();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);
        $uow = self::createStub(\Doctrine\ORM\UnitOfWork::class);

        $entity = new class {
            public ?DateTimeInterface $deletedAt = null;
        };

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getUnitOfWork')->willReturn($uow);
        $meta->method('hasField')->willReturn(true);
        $uow->method('getEntityChangeSet')->willReturn([
            'deletedAt' => [null, new DateTime()],
        ]);

        $action = $processor->determineDeletionAction($em, $entity, true);
        self::assertSame(AuditAction::SoftDelete, $action);
    }

    public function testDetermineDeletionActionHardDelete(): void
    {
        $processor = $this->createProcessor();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);
        $uow = self::createStub(\Doctrine\ORM\UnitOfWork::class);

        $entity = new class {
            public ?DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = null;

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getUnitOfWork')->willReturn($uow);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn($entity->deletedAt);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $action = $processor->determineDeletionAction($em, $entity, true);
        self::assertSame(AuditAction::Delete, $action);
    }

    public function testDetermineDeletionActionPurgeYieldsHardDelete(): void
    {
        $processor = $this->createProcessor();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);
        $uow = self::createStub(\Doctrine\ORM\UnitOfWork::class);

        $entity = new class {
            public ?DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = new DateTimeImmutable('2023-01-01');

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getUnitOfWork')->willReturn($uow);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn($entity->deletedAt);
        $uow->method('getEntityChangeSet')->willReturn([]); // Empty changeset because it's a purge (already soft deleted)

        // A purge in Doctrine via remove() should be audited as a Delete, not SoftDelete
        $action = $processor->determineDeletionAction($em, $entity, true);
        self::assertSame(AuditAction::Delete, $action);
    }

    public function testDetermineDeletionActionHardDeleteDisabled(): void
    {
        $processor = $this->createProcessor();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);
        $uow = self::createStub(\Doctrine\ORM\UnitOfWork::class);
        $entity = new class {
            public ?DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = null; // Not soft deleted

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getUnitOfWork')->willReturn($uow);
        $meta->method('hasField')->willReturn(true);
        $meta->method('getFieldValue')->willReturn($entity->deletedAt);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $action = $processor->determineDeletionAction($em, $entity, false);
        self::assertNull($action);
    }

    public function testDetermineDeletionActionPurgeWithHardDeleteDisabledIsSkipped(): void
    {
        $processor = $this->createProcessor();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);
        $uow = self::createStub(\Doctrine\ORM\UnitOfWork::class);
        $entity = new class {
            public ?DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = new DateTimeImmutable('2023-01-01');

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getUnitOfWork')->willReturn($uow);
        $meta->method('hasField')->willReturn(true);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $action = $processor->determineDeletionAction($em, $entity, false);
        self::assertNull($action);
    }

    public function testDetermineDeletionActionNoSoftDeleteField(): void
    {
        $processor = $this->createProcessor();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);
        $entity = new stdClass();
        $uow = self::createStub(\Doctrine\ORM\UnitOfWork::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('getUnitOfWork')->willReturn($uow);
        $meta->method('hasField')->willReturn(false);
        $uow->method('getEntityChangeSet')->willReturn([]);

        $action = $processor->determineDeletionAction($em, $entity, true);
        self::assertSame(AuditAction::Delete, $action);
    }

    public function testExtractChangesSkipsIgnoredFieldsUsingLookup(): void
    {
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $metadataManager->method('getSensitiveFields')->willReturn([]);
        $metadataManager->method('getIgnoredProperties')->willReturn(['ignored']);

        $processor = $this->createProcessor($metadataManager);

        $changes = $processor->extractChanges(new stdClass(), [
            'ignored' => ['old', 'new'],
            'kept' => ['before', 'after'],
        ]);

        self::assertSame(['kept' => 'before'], $changes[0]);
        self::assertSame(['kept' => 'after'], $changes[1]);
    }

    private function createProcessor(?AuditMetadataManagerInterface $metadataManager = null, bool $enabledSoftDelete = true): ChangeProcessor
    {
        return new ChangeProcessor(
            $metadataManager ?? self::createStub(AuditMetadataManagerInterface::class),
            new ValueSerializer(self::createStub(\Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface::class)),
            $enabledSoftDelete,
            'deletedAt'
        );
    }
}
