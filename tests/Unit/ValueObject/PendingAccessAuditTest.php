<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\ValueObject;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAccessAudit;
use stdClass;

final class PendingAccessAuditTest extends TestCase
{
    public function testConstructorStoresAllProperties(): void
    {
        $entity = new stdClass();
        $entityManager = self::createStub(EntityManagerInterface::class);
        $access = new AuditAccess(message: 'Viewed', cooldown: 60);

        $pending = new PendingAccessAudit('App:1', $entity, $entityManager, $access, ['userId' => '7']);

        self::assertSame('App:1', $pending->requestKey);
        self::assertSame($entity, $pending->entity);
        self::assertSame($entityManager, $pending->entityManager);
        self::assertSame($access, $pending->access);
        self::assertSame(['userId' => '7'], $pending->context);
    }
}
