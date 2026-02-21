<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;

#[AllowMockObjectsWithoutExpectations]
final class DataMaskerTest extends TestCase
{
    private DataMasker $dataMasker;

    protected function setUp(): void
    {
        $this->dataMasker = new DataMasker();
    }

    public function testRedactSensitiveKeys(): void
    {
        $data = [
            'username' => 'john_doe',
            'Password' => 'secret123',
            'api_TOken' => 'abcd',
            'nested' => [
                'session_id' => 'xyz',
                'normal' => 'value',
            ],
        ];

        $redacted = $this->dataMasker->redact($data);

        self::assertSame('john_doe', $redacted['username']);
        self::assertSame('********', $redacted['Password']);
        self::assertSame('********', $redacted['api_TOken']);
        self::assertSame('********', $redacted['nested']['session_id']);
        self::assertSame('value', $redacted['nested']['normal']);
    }

    public function testMaskExplicitFields(): void
    {
        $data = [
            'email' => 'test@example.com',
            'phone' => '1234567890',
        ];

        $masked = $this->dataMasker->mask($data, ['email' => 'HIDDEN', 'missing' => 'HIDDEN']);

        self::assertSame('HIDDEN', $masked['email']);
        self::assertSame('1234567890', $masked['phone']);
        self::assertArrayNotHasKey('missing', $masked);
    }
}
