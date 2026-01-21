<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Serializer;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Rcsofttech\AuditTrailBundle\Serializer\AuditLogMessageSerializer;
use Symfony\Component\Messenger\Envelope;

class AuditLogMessageSerializerTest extends TestCase
{
    private AuditLogMessageSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new AuditLogMessageSerializer();
    }

    public function testEncodeAuditLogMessage(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 00:00:00');
        $message = new AuditLogMessage(
            'TestEntity',
            '123',
            'create',
            null,
            ['foo' => 'bar'],
            ['foo'],
            'user1',
            'username1',
            '127.0.0.1',
            'Mozilla',
            'hash123',
            null,
            ['ctx' => 'val'],
            $createdAt
        );

        $envelope = new Envelope($message, [
            new ApiKeyStamp('test_api_key'),
            new SignatureStamp('test_signature'),
        ]);

        $encoded = $this->serializer->encode($envelope);

        $body = json_decode($encoded['body'], true);
        self::assertIsArray($body);

        // Assert all fields are present and correct
        $expectedBody = [
            'entity_class' => 'TestEntity',
            'entity_id' => '123',
            'action' => 'create',
            'old_values' => null,
            'new_values' => ['foo' => 'bar'],
            'changed_fields' => ['foo'],
            'user_id' => 'user1',
            'username' => 'username1',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Mozilla',
            'transaction_hash' => 'hash123',
            'signature' => null,
            'context' => ['ctx' => 'val'],
            'created_at' => $createdAt->format(\DateTimeInterface::ATOM),
        ];

        // Strict comparison of the entire array to ensure no extra or missing keys
        self::assertSame($expectedBody, $body);

        // Assert headers
        self::assertEquals('test_api_key', $encoded['headers']['X-Audit-Api-Key']);
        self::assertEquals('test_signature', $encoded['headers']['X-Audit-Signature']);
        self::assertEquals('application/json', $encoded['headers']['Content-Type']);
    }

    public function testDecodeThrowsException(): void
    {
        $this->expectException(\Symfony\Component\Messenger\Exception\MessageDecodingFailedException::class);
        $this->expectExceptionMessage('Decoding is not supported');

        $this->serializer->decode(['body' => '{}']);
    }
}
