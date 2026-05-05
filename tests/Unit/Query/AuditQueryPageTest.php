<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Query\AuditEntry;
use Rcsofttech\AuditTrailBundle\Query\AuditEntryCollection;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryPage;

final class AuditQueryPageTest extends TestCase
{
    public function testFirstReturnsFirstEntry(): void
    {
        $first = new AuditEntry(new AuditLog('Class', '1', 'create'));
        $second = new AuditEntry(new AuditLog('Class', '2', 'update'));
        $page = new AuditQueryPage(new AuditEntryCollection([$first, $second]), 'cursor-2');

        self::assertSame($first, $page->first());
        self::assertSame('cursor-2', $page->nextCursor);
    }

    public function testIsEmptyReflectsEntriesCollection(): void
    {
        $page = new AuditQueryPage(new AuditEntryCollection([]), null);

        self::assertTrue($page->isEmpty());
        self::assertNull($page->first());
    }
}
