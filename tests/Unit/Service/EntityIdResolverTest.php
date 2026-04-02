<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;
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
        $log = new AuditLog('App\Entity\User', '123', AuditLogInterface::ACTION_CREATE);

        self::assertSame('123', $this->resolver->resolve($log, ['is_insert' => true]));
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
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 123]);

        self::assertSame('123', $this->resolver->resolve($log, ['entity' => $entity, 'em' => $em]));
    }

    public function testResolvePendingCompositeId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn(['id1' => 1, 'id2' => 2]);

        self::assertSame('["1","2"]', $this->resolver->resolve($log, ['entity' => $entity, 'em' => $em]));
    }

    public function testResolvePendingNoId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierValues')->willReturn([]);

        self::assertSame('pending', $this->resolver->resolve($log, ['entity' => $entity, 'em' => $em]));
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

    public function testResolveFromValuesReturnsNullWhenIdentifierCannotBeStringified(): void
    {
        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $metadata = self::createStub(ClassMetadata::class);
        $em->method('getClassMetadata')->willReturn($metadata);
        $metadata->method('getIdentifierFieldNames')->willReturn(['id1', 'id2']);

        self::assertNull($this->resolver->resolveFromValues($entity, ['id1' => new stdClass(), 'id2' => 789], $em));
    }
}
