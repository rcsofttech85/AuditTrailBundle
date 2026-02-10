<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Entity;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

class AuditLogTest extends TestCase
{
    public function testContext(): void
    {
        $log = new AuditLog();
        self::assertEquals([], $log->getContext());

        $context = ['foo' => 'bar', 'nested' => ['a' => 1]];
        $log->setContext($context);
        self::assertEquals($context, $log->getContext());
    }

    public function testActionValidation(): void
    {
        $log = new AuditLog();
        $log->setAction('create');
        self::assertEquals('create', $log->getAction());

        $this->expectException(InvalidArgumentException::class);
        $log->setAction('invalid_action');
    }
}
