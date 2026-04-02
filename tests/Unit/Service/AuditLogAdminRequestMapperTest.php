<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\AuditLogAdminRequestMapper;

final class AuditLogAdminRequestMapperTest extends TestCase
{
    private AuditLogAdminRequestMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AuditLogAdminRequestMapper();
    }

    public function testMapExportFiltersMapsCreatedAtBetweenComparison(): void
    {
        $result = $this->mapper->mapExportFilters([
            'createdAt' => [
                'comparison' => 'between',
                'value' => '2026-01-01 10:00:00',
                'value2' => '2026-01-31 18:00:00',
            ],
        ]);

        self::assertSame('2026-01-01 10:00:00', $result['from'] ?? null);
        self::assertSame('2026-01-31 18:00:00', $result['to'] ?? null);
    }

    public function testMapExportFiltersMapsCreatedAtLowerBoundComparison(): void
    {
        $result = $this->mapper->mapExportFilters([
            'createdAt' => [
                'comparison' => '>=',
                'value' => '2026-01-01 10:00:00',
            ],
        ]);

        self::assertSame('2026-01-01 10:00:00', $result['from'] ?? null);
        self::assertArrayNotHasKey('to', $result);
    }

    public function testMapExportFiltersMapsCreatedAtUpperBoundComparison(): void
    {
        $result = $this->mapper->mapExportFilters([
            'createdAt' => [
                'comparison' => '<=',
                'value' => '2026-01-31 18:00:00',
            ],
        ]);

        self::assertSame('2026-01-31 18:00:00', $result['to'] ?? null);
        self::assertArrayNotHasKey('from', $result);
    }

    public function testMapExportFiltersPreservesScalarFilters(): void
    {
        $result = $this->mapper->mapExportFilters([
            'username' => [
                'comparison' => 'LIKE',
                'value' => 'admin',
            ],
            'action' => [
                'value' => 'update',
            ],
        ]);

        self::assertSame('admin', $result['username'] ?? null);
        self::assertSame('update', $result['action'] ?? null);
    }

    public function testMapExportFiltersSkipsEmptyValues(): void
    {
        $result = $this->mapper->mapExportFilters([
            'username' => [
                'value' => '',
            ],
            'action' => [
                'value' => 'update',
            ],
        ]);

        self::assertArrayNotHasKey('username', $result);
        self::assertSame('update', $result['action'] ?? null);
    }

    public function testIsValidCursorAcceptsUuidOrEmptyString(): void
    {
        self::assertTrue($this->mapper->isValidCursor(''));
        self::assertTrue($this->mapper->isValidCursor('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a'));
        self::assertFalse($this->mapper->isValidCursor('not-a-uuid'));
    }

    public function testHasConflictingCursorsRequiresBothDirections(): void
    {
        self::assertTrue($this->mapper->hasConflictingCursors('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', '018f3a3b-3a3a-7a3a-8a3a-3a3a3a3a3a3a'));
        self::assertFalse($this->mapper->hasConflictingCursors('', '018f3a3b-3a3a-7a3a-8a3a-3a3a3a3a3a3a'));
        self::assertFalse($this->mapper->hasConflictingCursors('018f3a3a-3a3a-7a3a-8a3a-3a3a3a3a3a3a', ''));
    }
}
