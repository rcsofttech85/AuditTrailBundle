<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;

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
        self::assertIsArray($redacted['nested'] ?? null);
        self::assertSame('********', $redacted['nested']['session_id'] ?? null);
        self::assertSame('value', $redacted['nested']['normal'] ?? null);
    }

    public function testRedactDoesNotMaskPartialSensitiveSubstrings(): void
    {
        $data = [
            'primaryKeyValue' => 'keep',
            'monkeyBusiness' => 'keep',
            'apitoken' => 'keep',
            'api_token' => 'mask',
            'apiKey' => 'mask',
            'accessToken' => 'mask',
            'sessionId' => 'mask',
        ];

        $redacted = $this->dataMasker->redact($data);

        self::assertSame('keep', $redacted['primaryKeyValue']);
        self::assertSame('keep', $redacted['monkeyBusiness']);
        self::assertSame('keep', $redacted['apitoken']);
        self::assertSame('********', $redacted['api_token']);
        self::assertSame('********', $redacted['apiKey']);
        self::assertSame('********', $redacted['accessToken']);
        self::assertSame('********', $redacted['sessionId']);
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

    public function testCustomSensitiveKeysRemainSupported(): void
    {
        $masker = new DataMasker(['jwt', 'signingKey', 'merchant_token']);
        $data = [
            'jwt' => 'mask',
            'signingKey' => 'mask',
            'merchant_token' => 'mask',
            'primaryKeyValue' => 'keep',
        ];

        $redacted = $masker->redact($data);

        self::assertSame('********', $redacted['jwt']);
        self::assertSame('********', $redacted['signingKey']);
        self::assertSame('********', $redacted['merchant_token']);
        self::assertSame('keep', $redacted['primaryKeyValue']);
    }
}
