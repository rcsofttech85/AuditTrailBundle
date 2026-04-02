<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditRenderer;
use stdClass;
use Symfony\Component\Console\Output\BufferedOutput;

use function strlen;

final class AuditRendererTest extends TestCase
{
    private AuditRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new AuditRenderer();
    }

    public function testFormatValueStripsAnsiSequences(): void
    {
        $ansiString = "\x1b[31mRed Content\x1b[0m";

        $result = $this->renderer->formatValue($ansiString);

        self::assertSame('Red Content', $result);
        self::assertStringNotContainsString("\x1b", $result);
    }

    public function testFormatValueTruncatesLongStrings(): void
    {
        $longString = str_repeat('a', 60);

        $result = $this->renderer->formatValue($longString);

        self::assertSame(50, strlen($result));
        self::assertStringEndsWith('...', $result);
    }

    public function testRenderTable(): void
    {
        $output = new BufferedOutput();
        $log = new AuditLog('App\\Entity\\User', '1', 'create');

        $this->renderer->renderTable($output, [$log], false);
        $content = $output->fetch();

        self::assertStringContainsString('User', $content);
        self::assertStringContainsString('create', $content);
    }

    public function testRenderTableWithDetails(): void
    {
        $output = new BufferedOutput();
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'update',
            oldValues: ['name' => 'Old Name'],
            newValues: ['name' => 'New Name']
        );

        $this->renderer->renderTable($output, [$log], true);
        $content = $output->fetch();

        self::assertStringContainsString('Old Name → New Name', $content);
        self::assertStringContainsString('update', $content);
    }

    public function testFormatChangedDetailsEmpty(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'create');
        self::assertSame('-', $this->renderer->formatChangedDetails($log));
    }

    public function testFormatChangedDetailsWithArray(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\User',
            entityId: '1',
            action: 'create',
            newValues: ['roles' => ['ROLE_USER', 'ROLE_ADMIN']]
        );
        $details = $this->renderer->formatChangedDetails($log);
        self::assertStringContainsString('ROLE_USER', $details);
        self::assertStringContainsString('ROLE_ADMIN', $details);
    }

    public function testFormatChangedDetailsUsesChangedFieldsForSoftDeleteStyleDiffs(): void
    {
        $log = new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '1',
            action: 'soft_delete',
            oldValues: [
                'id' => 1,
                'title' => 'create',
                'deletedAt' => null,
            ],
            newValues: [
                'id' => 1,
                'title' => 'create',
                'deletedAt' => '2026-04-05T08:46:03+00:00',
            ],
            changedFields: ['deletedAt'],
        );

        $details = $this->renderer->formatChangedDetails($log);

        self::assertStringContainsString('deletedAt', $details);
        self::assertStringNotContainsString('title', $details);
        self::assertStringNotContainsString('id:', $details);
    }

    public function testFormatValueEdgeCases(): void
    {
        self::assertSame('true', $this->renderer->formatValue(true));
        self::assertSame('false', $this->renderer->formatValue(false));
        self::assertSame('null', $this->renderer->formatValue(null));
        self::assertSame('[]', $this->renderer->formatValue([])); // Empty array

        $obj = new stdClass();
        self::assertStringContainsString('stdClass', $this->renderer->formatValue($obj));
    }

    public function testShortenHash(): void
    {
        self::assertSame('-', $this->renderer->shortenHash(null));
        self::assertSame('-', $this->renderer->shortenHash(''));
        self::assertSame('12345678', $this->renderer->shortenHash('1234567890abcdef'));
    }
}
