<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;

final class CollectionTransitionMergerTest extends TestCase
{
    public function testMergeSingleFieldTransitionPreservesStableOrdering(): void
    {
        $existingOld = ['1', '2'];
        $existingNew = ['2', '3'];

        new CollectionTransitionMerger()->mergeSingleFieldTransition(
            $existingOld,
            $existingNew,
            ['2', '4'],
            ['4', '5'],
        );

        self::assertSame(['1', '2', '4'], $existingOld);
        self::assertSame(['4', '3', '5'], $existingNew);
    }

    public function testMergeSingleFieldTransitionKeepsIntAndStringIdsDistinct(): void
    {
        $existingOld = [1];
        $existingNew = [1];

        new CollectionTransitionMerger()->mergeSingleFieldTransition(
            $existingOld,
            $existingNew,
            ['1'],
            ['1'],
        );

        self::assertSame([1, '1'], $existingOld);
        self::assertSame([1, '1'], $existingNew);
    }
}
