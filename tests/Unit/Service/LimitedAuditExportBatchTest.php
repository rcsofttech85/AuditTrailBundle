<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\LimitedAuditExportBatch;

final class LimitedAuditExportBatchTest extends TestCase
{
    public function testHasItemsReturnsFalseForEmptyBatch(): void
    {
        $batch = new LimitedAuditExportBatch([], 10);

        self::assertFalse($batch->hasItems());
        self::assertSame([], iterator_to_array($batch));
    }

    public function testIterationPreservesFirstItemAndRespectsLimit(): void
    {
        $audits = [
            new AuditLog('User', '1', 'create'),
            new AuditLog('User', '2', 'create'),
            new AuditLog('User', '3', 'create'),
        ];
        $batch = new LimitedAuditExportBatch($audits, 2);

        self::assertTrue($batch->hasItems());
        self::assertSame([$audits[0], $audits[1]], iterator_to_array($batch));
    }
}
