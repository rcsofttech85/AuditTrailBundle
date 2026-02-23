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
        $this->expectExceptionMessage('Invalid action "invalid_action". Must be one of:');
        $_ = new AuditLog('App\Entity\User', '1', 'invalid_action');
    }

    public function testEntityClassValidation(): void
    {
        // Test mb_trim
        $log = new AuditLog("  App\Entity\User  ", '1', 'create');
        self::assertSame('App\Entity\User', $log->entityClass);

        // Test empty check
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity class cannot be empty');
        $_ = new AuditLog('   ', '1', 'create');
    }

    public function testEntityIdValidation(): void
    {
        // Test mb_trim
        $log = new AuditLog('App\Entity\User', '  123  ', 'create');
        self::assertSame('123', $log->entityId);

        // Test empty check
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Entity ID cannot be empty');
        $_ = new AuditLog('App\Entity\User', '   ', 'create');
    }

    public function testIpAddressValidation(): void
    {
        // Valid IPv4
        $log = new AuditLog('App\Entity\User', '1', 'create', ipAddress: '127.0.0.1');
        self::assertSame('127.0.0.1', $log->ipAddress);

        // Valid IPv6
        $log = new AuditLog('App\Entity\User', '1', 'create', ipAddress: '::1');
        self::assertSame('::1', $log->ipAddress);

        // Null is allowed
        $log = new AuditLog('App\Entity\User', '1', 'create', ipAddress: null);
        self::assertNull($log->ipAddress);

        // Invalid IP
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid IP address format: "not-an-ip"');
        $_ = new AuditLog('App\Entity\User', '1', 'create', ipAddress: 'not-an-ip');
    }

    public function testSealProtection(): void
    {
        $log = new AuditLog('App\Entity\User', '1', 'create');
        $log->entityId = '123';
        self::assertEquals('123', $log->entityId);

        $log->seal();

        // entityId is hooked
        try {
            $log->entityId = '456';
            self::fail('Should have thrown LogicException for entityId');
        } catch (LogicException $e) {
            self::assertSame('Cannot modify a sealed audit log.', $e->getMessage());
        }

        // context is hooked
        try {
            $log->context = ['new' => 'val'];
            self::fail('Should have thrown LogicException for context');
        } catch (LogicException $e) {
            self::assertSame('Cannot modify a sealed audit log.', $e->getMessage());
        }

        // signature is hooked
        try {
            $log->signature = 'new_sig';
            self::fail('Should have thrown LogicException for signature');
        } catch (LogicException $e) {
            self::assertSame('Cannot modify a sealed audit log.', $e->getMessage());
        }
    }

    public function testGettersReturnCorrectValues(): void
    {
        $log = new AuditLog(
            'App\Entity\User',
            '1',
            'create',
            ipAddress: '1.2.3.4',
            userAgent: 'Mozilla/5.0',
            userId: 'user_123',
            username: 'jdoe',
            transactionHash: 'tx_abc',
            oldValues: ['v' => 1],
            newValues: ['v' => 2],
            changedFields: ['v'],
            signature: 'sig_123'
        );

        self::assertSame('App\Entity\User', $log->entityClass);
        self::assertSame('1', $log->entityId);
        self::assertSame('create', $log->action);
        self::assertSame('1.2.3.4', $log->ipAddress);
        self::assertSame('Mozilla/5.0', $log->userAgent);
        self::assertSame('user_123', $log->userId);
        self::assertSame('jdoe', $log->username);
        self::assertSame('tx_abc', $log->transactionHash);
        self::assertSame(['v' => 1], $log->oldValues);
        self::assertSame(['v' => 2], $log->newValues);
        self::assertSame(['v'], $log->changedFields);
        self::assertSame('sig_123', $log->signature);
    }
}
