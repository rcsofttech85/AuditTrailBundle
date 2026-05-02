<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\ValueObject;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;
use stdClass;

final class AssociationImpactTest extends TestCase
{
    public function testKeyUsesEntityIdentityAndField(): void
    {
        $entity = new stdClass();
        $impact = new AssociationImpact($entity, 'tags', [1], [2]);

        self::assertSame(spl_object_id($entity).':tags', $impact->key());
    }

    public function testToArrayReturnsStructuredPayload(): void
    {
        $entity = new stdClass();
        $impact = new AssociationImpact($entity, 'tags', [1, 2], [3]);

        self::assertSame(
            [
                'entity' => $entity,
                'field' => 'tags',
                'old' => [1, 2],
                'new' => [3],
            ],
            $impact->toArray(),
        );
    }
}
