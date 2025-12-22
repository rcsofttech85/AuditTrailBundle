<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use PHPUnit\Framework\MockObject\MockObject;

#[Auditable(enabled: true)]
class TestEntity
{
    public function getId(): int
    {
        return 1;
    }
}

class AuditServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;
    private UserResolverInterface&MockObject $userResolver;
    private ClockInterface&MockObject $clock;
    private LoggerInterface $logger;
    private AuditService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->userResolver = $this->createMock(UserResolverInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->logger = $this->createStub(LoggerInterface::class);

        $this->service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->clock,
            ['ignoredField'], // global ignored
            [], // ignored entities
            $this->logger
        );
    }

    public function testShouldAuditReturnsTrueForAuditableEntity(): void
    {
        $entity = new TestEntity();
        $this->assertTrue($this->service->shouldAudit($entity));
    }

    public function testShouldAuditReturnsFalseForIgnoredEntity(): void
    {
        $service = new AuditService(
            $this->entityManager,
            $this->userResolver,
            $this->createMock(ClockInterface::class),
            [],
            [TestEntity::class], // Ignore TestEntity
            $this->logger
        );

        $entity = new TestEntity();
        $this->assertFalse($service->shouldAudit($entity));
    }

    public function testGetEntityDataExtractsFields(): void
    {
        $entity = new TestEntity();
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['name', 'ignoredField']);
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getFieldValue')->willReturnMap([
            [$entity, 'name', 'Test Name'],
            [$entity, 'ignoredField', 'Ignored'],
        ]);

        $this->entityManager->expects($this->once())->method('getClassMetadata')->willReturn($metadata);

        $data = $this->service->getEntityData($entity);

        $this->assertArrayHasKey('name', $data);
        $this->assertEquals('Test Name', $data['name']);
        $this->assertArrayNotHasKey('ignoredField', $data);
    }

    public function testCreateAuditLog(): void
    {
        $entity = new TestEntity();
        $this->entityManager->expects($this->once())->method('getClassMetadata')->willReturn($this->createStub(ClassMetadata::class));

        $this->userResolver->expects($this->once())->method('getUserId')->willReturn(123);
        $this->userResolver->expects($this->once())->method('getUsername')->willReturn('testuser');

        $now = new \DateTimeImmutable('2024-01-01 12:00:00');
        $this->clock->expects($this->once())->method('now')->willReturn($now);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLog::ACTION_UPDATE,
            ['name' => 'Old'],
            ['name' => 'New']
        );

        $this->assertEquals(TestEntity::class, $log->entityClass);
        $this->assertEquals(AuditLog::ACTION_UPDATE, $log->action);
        $this->assertEquals(['name' => 'Old'], $log->oldValues);
        $this->assertEquals(['name' => 'New'], $log->newValues);
        $this->assertEquals(['name'], $log->changedFields);
        $this->assertEquals(123, $log->userId);
        $this->assertEquals('testuser', $log->username);
        $this->assertEquals($now, $log->createdAt);
    }
}
