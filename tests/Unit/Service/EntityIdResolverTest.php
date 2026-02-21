<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class EntityIdResolverTest extends TestCase
{
    private EntityIdResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new EntityIdResolver();
    }

    public function testResolveNotPendingIsInsert(): void
    {
        $log = new AuditLog('App\Entity\User', '123', AuditLogInterface::ACTION_CREATE);

        self::assertEquals('123', $this->resolver->resolve($log, ['is_insert' => true]));
    }

    public function testResolveNotPendingNotInsert(): void
    {
        $log = new AuditLog('App\Entity\User', '123', AuditLogInterface::ACTION_UPDATE);

        self::assertNull($this->resolver->resolve($log, ['is_insert' => false]));
    }

    public function testResolvePendingMissingContext(): void
    {
        $log = new AuditLog('App\Entity\User', 'pending', AuditLogInterface::ACTION_CREATE);

        self::assertNull($this->resolver->resolve($log, []));
    }

    public function testResolvePendingSingleId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);

        $em->method('getClassMetadata')->with(stdClass::class)->willReturn($metadata);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id' => 123]);

        self::assertEquals('123', $this->resolver->resolve($log, ['entity' => $entity, 'em' => $em]));
    }

    public function testResolvePendingCompositeId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);

        $em->method('getClassMetadata')->with(stdClass::class)->willReturn($metadata);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id1' => 1, 'id2' => 2]);

        self::assertEquals('["1","2"]', $this->resolver->resolve($log, ['entity' => $entity, 'em' => $em]));
    }

    public function testResolvePendingNoId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);

        $em->method('getClassMetadata')->with(stdClass::class)->willReturn($metadata);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn([]);

        self::assertEquals('pending', $this->resolver->resolve($log, ['entity' => $entity, 'em' => $em]));
    }

    public function testResolveFromEntity(): void
    {
        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);
        $em->method('getClassMetadata')->with(stdClass::class)->willReturn($metadata);
        $metadata->method('getIdentifierValues')->with($entity)->willReturn(['id' => 456]);

        $resolver = new EntityIdResolver($em);
        self::assertEquals('456', $resolver->resolveFromEntity($entity));
    }

    public function testResolveFromValues(): void
    {
        $entity = new stdClass();
        $em = $this->createMock(EntityManagerInterface::class);
        $metadata = $this->createMock(ClassMetadata::class);
        $em->method('getClassMetadata')->with(stdClass::class)->willReturn($metadata);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id']);

        self::assertEquals('789', $this->resolver->resolveFromValues($entity, ['id' => 789], $em));
    }
}
