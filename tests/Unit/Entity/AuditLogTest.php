<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Entity;

use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

class AuditLogTest extends TestCase
{
    public function testContext(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'create');
        self::assertEquals([], $log->context);

        $context = ['foo' => 'bar', 'nested' => ['a' => 1]];
        $log = new AuditLog('App\Entity\User', '1', 'create', context: $context);
        self::assertEquals($context, $log->context);
    }

    public function testActionValidation(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'create');
        self::assertEquals('create', $log->action);

        $this->expectException(InvalidArgumentException::class);
        $log = new AuditLog('App\Entity\User', '1', 'invalid_action');
    }

    public function testSealProtection(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'create');
        $log->entityId = '123';
        self::assertEquals('123', $log->entityId);

        $log->seal();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot modify a sealed audit log.');

        $log->entityId = '456';
    }
}
