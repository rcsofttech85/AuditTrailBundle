<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditService;

#[CoversClass(AuditService::class)]
final class AuditServiceTimezoneTest extends TestCase
{
    public function testCreateAuditLogWithCustomTimezone(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $userResolver = $this->createStub(UserResolverInterface::class);
        $clock = $this->createStub(ClockInterface::class);

        // Mock current time as UTC
        $now = new \DateTimeImmutable('2023-01-01 12:00:00', new \DateTimeZone('UTC'));
        $clock->method('now')->willReturn($now);

        // Configure service with 'Asia/Kolkata' (UTC+5:30)
        $service = new AuditService(
            $entityManager,
            $userResolver,
            $clock,
            [],
            [],
            null,
            'Asia/Kolkata'
        );

        $entity = new class () {
            public function getId(): int
            {
                return 1;
            }
        };

        // Mock metadata for getEntityId
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $auditLog = $service->createAuditLog($entity, AuditLog::ACTION_CREATE);

        $this->assertEquals('Asia/Kolkata', $auditLog->getCreatedAt()->getTimezone()->getName());
        $this->assertEquals('2023-01-01 17:30:00', $auditLog->getCreatedAt()->format('Y-m-d H:i:s'));
    }

    public function testCreateAuditLogWithDefaultTimezone(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $userResolver = $this->createStub(UserResolverInterface::class);
        $clock = $this->createStub(ClockInterface::class);

        $now = new \DateTimeImmutable('2023-01-01 12:00:00', new \DateTimeZone('UTC'));
        $clock->method('now')->willReturn($now);

        // Default timezone is UTC
        $service = new AuditService(
            $entityManager,
            $userResolver,
            $clock
        );

        $entity = new class () {
            public function getId(): int
            {
                return 1;
            }
        };

        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => 1]);
        $entityManager->method('getClassMetadata')->willReturn($metadata);

        $auditLog = $service->createAuditLog($entity, AuditLog::ACTION_CREATE);

        $this->assertEquals('UTC', $auditLog->getCreatedAt()->getTimezone()->getName());
        $this->assertEquals('2023-01-01 12:00:00', $auditLog->getCreatedAt()->format('Y-m-d H:i:s'));
    }
}
