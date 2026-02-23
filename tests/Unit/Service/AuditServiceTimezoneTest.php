<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;

#[AllowMockObjectsWithoutExpectations]
final class AuditServiceTimezoneTest extends TestCase
{
    public function testCreateAuditLogWithCustomTimezone(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $clock = self::createStub(ClockInterface::class);

        // Mock current time as UTC
        $now = new DateTimeImmutable('2023-01-01 12:00:00', new DateTimeZone('UTC'));
        $clock->method('now')->willReturn($now);

        $contextResolver = self::createStub(ContextResolverInterface::class);
        $contextResolver->method('resolve')->willReturn([
            'userId' => null,
            'username' => null,
            'ipAddress' => null,
            'userAgent' => null,
            'context' => [],
        ]);

        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('1');
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);

        // Configure service with 'Asia/Kolkata' (UTC+5:30)
        $service = new AuditService(
            $entityManager,
            $clock,
            self::createStub(TransactionIdGenerator::class),
            self::createStub(EntityDataExtractor::class),
            $metadataManager,
            $contextResolver,
            $idResolver,
            null,
            'Asia/Kolkata'
        );

        $entity = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        // Mock metadata for getEntityId
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $auditLog = $service->createAuditLog($entity, AuditLogInterface::ACTION_CREATE);

        self::assertEquals('Asia/Kolkata', $auditLog->createdAt->getTimezone()->getName());
        self::assertEquals('2023-01-01 17:30:00', $auditLog->createdAt->format('Y-m-d H:i:s'));
    }

    public function testCreateAuditLogWithDefaultTimezone(): void
    {
        $entityManager = self::createStub(EntityManagerInterface::class);
        $clock = self::createStub(ClockInterface::class);

        $now = new DateTimeImmutable('2023-01-01 12:00:00', new DateTimeZone('UTC'));
        $clock->method('now')->willReturn($now);

        $contextResolver = self::createStub(ContextResolverInterface::class);
        $contextResolver->method('resolve')->willReturn([
            'userId' => null,
            'username' => null,
            'ipAddress' => null,
            'userAgent' => null,
            'context' => [],
        ]);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('1');
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);

        // Default timezone is UTC
        $service = new AuditService(
            $entityManager,
            $clock,
            self::createStub(TransactionIdGenerator::class),
            self::createStub(EntityDataExtractor::class),
            $metadataManager,
            $contextResolver,
            $idResolver
        );

        $entity = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $auditLog = $service->createAuditLog($entity, AuditLogInterface::ACTION_CREATE);

        self::assertEquals('UTC', $auditLog->createdAt->getTimezone()->getName());
        self::assertEquals('2023-01-01 12:00:00', $auditLog->createdAt->format('Y-m-d H:i:s'));
    }
}
