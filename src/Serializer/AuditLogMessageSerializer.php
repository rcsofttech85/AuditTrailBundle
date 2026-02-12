<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Serializer;

use InvalidArgumentException;
use Override;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Rcsofttech\AuditTrailBundle\Message\Stamp\ApiKeyStamp;
use Rcsofttech\AuditTrailBundle\Message\Stamp\SignatureStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Serializer for publishing audit logs to external SaaS dashboard.
 * Supports cross-platform JSON format and maps stamps to headers.
 */
final readonly class AuditLogMessageSerializer implements SerializerInterface
{
    private const CONTENT_TYPE = 'application/json';

    private const API_KEY_HEADER = 'X-Audit-Api-Key';

    private const SIGNATURE_HEADER = 'X-Audit-Signature';

    private const BUNDLE_VERSION = '1.0.0';

    /**
     * @return array{body: string, headers: array<string, string>}
     */
    #[Override]
    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();

        if (!$message instanceof AuditLogMessage) {
            throw new InvalidArgumentException(sprintf('The message must be an instance of "%s".', AuditLogMessage::class));
        }

        return [
            'body' => json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'headers' => $this->buildHeaders($envelope),
        ];
    }

    #[Override]
    public function decode(array $encodedEnvelope): Envelope
    {
        throw new MessageDecodingFailedException('Decoding is not supported. This serializer is designed for publishing audit logs only.');
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(Envelope $envelope): array
    {
        $headers = [
            'Content-Type' => self::CONTENT_TYPE,
            'User-Agent' => 'RcsoftTech-AuditTrailBundle/'.self::BUNDLE_VERSION,
        ];

        /** @var ApiKeyStamp|null $apiKeyStamp */
        $apiKeyStamp = $envelope->last(ApiKeyStamp::class);
        if ($apiKeyStamp !== null) {
            $headers[self::API_KEY_HEADER] = $apiKeyStamp->apiKey;
        }

        /** @var SignatureStamp|null $signatureStamp */
        $signatureStamp = $envelope->last(SignatureStamp::class);
        if ($signatureStamp !== null) {
            $headers[self::SIGNATURE_HEADER] = $signatureStamp->signature;
        }

        return $headers;
    }
}
