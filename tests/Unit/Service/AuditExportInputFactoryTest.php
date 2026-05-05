<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\AuditExportInputFactory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class AuditExportInputFactoryTest extends TestCase
{
    public function testCreateBuildsStreamingExportInput(): void
    {
        $factory = new AuditExportInputFactory();
        $input = self::createStub(InputInterface::class);
        $input
            ->method('getOption')
            ->willReturnMap([
                ['format', 'CSV'],
                ['output', null],
                ['entity', 'User'],
                ['action', 'create'],
                ['from', '2024-01-01'],
                ['to', '2024-01-02'],
                ['limit', '25'],
            ]);
        $io = new SymfonyStyle($input, new BufferedOutput());

        $exportInput = $factory->create($input, $io);

        self::assertNotNull($exportInput);
        self::assertSame('php://output', $exportInput->outputTarget);
        self::assertSame('csv', $exportInput->format);
        self::assertSame(25, $exportInput->limit);
        self::assertSame('User', $exportInput->filters['entityClass']);
        self::assertSame('create', $exportInput->filters['action']);
        self::assertArrayHasKey('from', $exportInput->filters);
        self::assertArrayHasKey('to', $exportInput->filters);
    }

    public function testCreateReturnsNullForInvalidDate(): void
    {
        $factory = new AuditExportInputFactory();
        $input = self::createStub(InputInterface::class);
        $input
            ->method('getOption')
            ->willReturnMap([
                ['format', null],
                ['output', null],
                ['entity', null],
                ['action', null],
                ['from', 'not-a-date'],
                ['to', null],
                ['limit', null],
            ]);
        $output = new BufferedOutput();
        $io = new SymfonyStyle($input, $output);

        $exportInput = $factory->create($input, $io);

        self::assertNull($exportInput);
        self::assertStringContainsString('Invalid "from" date', $output->fetch());
    }
}
