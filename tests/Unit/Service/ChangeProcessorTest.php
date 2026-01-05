<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;

#[AllowMockObjectsWithoutExpectations]
class ChangeProcessorTest extends TestCase
{
    private AuditService&MockObject $auditService;
    private ChangeProcessor $processor;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditService::class);
        $this->processor = new ChangeProcessor($this->auditService, true, 'deletedAt');
    }

    public function testExtractChanges(): void
    {
        $entity = new \stdClass();
        $changeSet = [
            'name' => ['old', 'new'],
            'age' => [20, 21],
            'ignored' => 'not_array',
            'same' => ['val', 'val'],
            'password' => ['old_pass', 'new_pass'],
        ];

        $this->auditService->method('getSensitiveFields')->with($entity)->willReturn(['password' => '***']);
        $this->auditService->method('getIgnoredProperties')->with($entity)->willReturn([]);

        $changes = $this->processor->extractChanges($entity, $changeSet);

        self::assertEquals([
            'name' => 'old',
            'age' => 20,
            'password' => '***',
        ], $changes[0]);

        self::assertEquals([
            'name' => 'new',
            'age' => 21,
            'password' => '***',
        ], $changes[1]);
    }

    public function testExtractChangesFloatPrecision(): void
    {
        $entity = new \stdClass();
        $changeSet = [
            'float_diff' => [1.0, 1.1],
            'float_same' => [1.0000000001, 1.0000000002],
            'null_same' => [null, null],
        ];

        $this->auditService->method('getSensitiveFields')->willReturn([]);
        $this->auditService->method('getIgnoredProperties')->willReturn([]);

        $changes = $this->processor->extractChanges($entity, $changeSet);

        // float_diff should be present
        self::assertArrayHasKey('float_diff', $changes[0]);
        self::assertEquals(1.0, $changes[0]['float_diff']);
        self::assertEquals(1.1, $changes[1]['float_diff']);

        // float_same should be absent (difference < 1e-9)
        self::assertArrayNotHasKey('float_same', $changes[0]);
        self::assertArrayNotHasKey('float_same', $changes[1]);
    }

    public function testDetermineUpdateAction(): void
    {
        // Normal update
        self::assertEquals(
            AuditLogInterface::ACTION_UPDATE,
            $this->processor->determineUpdateAction(['name' => ['old', 'new']])
        );

        // Restore (deletedAt: not null -> null)
        self::assertEquals(
            AuditLogInterface::ACTION_RESTORE,
            $this->processor->determineUpdateAction(['deletedAt' => [new \DateTime(), null]])
        );
        self::assertEquals(
            AuditLogInterface::ACTION_UPDATE,
            $this->processor->determineUpdateAction(['deletedAt' => [null, new \DateTime()]])
        );
    }

    public function testDetermineUpdateActionSoftDeleteDisabled(): void
    {
        $processor = new ChangeProcessor($this->auditService, false, 'deletedAt');

        self::assertEquals(
            AuditLogInterface::ACTION_UPDATE,
            $processor->determineUpdateAction(['deletedAt' => [new \DateTime(), null]])
        );
    }

    public function testDetermineDeletionActionSoftDelete(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $meta = $this->createMock(ClassMetadata::class);
        $entity = new class () {
            public ?\DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = new \DateTime();

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('hasField')->with('deletedAt')->willReturn(true);

        $reflProp = new \ReflectionProperty($entity, 'deletedAt');
        $meta->method('getReflectionProperty')->with('deletedAt')->willReturn($reflProp);

        $action = $this->processor->determineDeletionAction($em, $entity, true);
        self::assertEquals(AuditLogInterface::ACTION_SOFT_DELETE, $action);
    }

    public function testDetermineDeletionActionHardDelete(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $meta = $this->createMock(ClassMetadata::class);
        $entity = new class () {
            public ?\DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = null;

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('hasField')->with('deletedAt')->willReturn(true);

        $reflProp = new \ReflectionProperty($entity, 'deletedAt');
        $meta->method('getReflectionProperty')->with('deletedAt')->willReturn($reflProp);

        $action = $this->processor->determineDeletionAction($em, $entity, true);
        self::assertEquals(AuditLogInterface::ACTION_DELETE, $action);
    }

    public function testDetermineDeletionActionHardDeleteDisabled(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $meta = $this->createMock(ClassMetadata::class);
        $entity = new class () {
            public ?\DateTimeInterface $deletedAt = null;
        };
        $entity->deletedAt = null; // Not soft deleted

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('hasField')->with('deletedAt')->willReturn(true);
        $reflProp = new \ReflectionProperty($entity, 'deletedAt');
        $meta->method('getReflectionProperty')->with('deletedAt')->willReturn($reflProp);

        $action = $this->processor->determineDeletionAction($em, $entity, false);
        self::assertNull($action);
    }

    public function testDetermineDeletionActionNoSoftDeleteField(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $meta = $this->createMock(ClassMetadata::class);
        $entity = new \stdClass();

        $em->method('getClassMetadata')->willReturn($meta);
        $meta->method('hasField')->with('deletedAt')->willReturn(false);

        $action = $this->processor->determineDeletionAction($em, $entity, true);
        self::assertEquals(AuditLogInterface::ACTION_DELETE, $action);
    }
}
