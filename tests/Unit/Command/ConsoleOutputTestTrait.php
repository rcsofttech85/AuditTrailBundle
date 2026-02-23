<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Command;

use Symfony\Component\Console\Tester\CommandTester;

use function preg_replace;
use function trim;

trait ConsoleOutputTestTrait
{
    private function normalizeOutput(CommandTester $commandTester): string
    {
        $output = $commandTester->getDisplay();
        // Remove ANSI escape codes if any
        $regex = '/\x1b[[()#;?]*(?:[0-9]{1,4}(?:;[0-9]{0,4})*)?[0-9A-ORZcf-nqry=><]/';
        $output = (string) preg_replace($regex, '', $output);
        // Remove block decorations (!, [OK], [ERROR], etc.)
        $output = (string) preg_replace('/[!\[\]]+/', ' ', $output);

        // Normalize whitespace
        return (string) preg_replace('/\s+/', ' ', trim($output));
    }
}
