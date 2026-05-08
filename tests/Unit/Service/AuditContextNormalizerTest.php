<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditContextNormalizer;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;

final class AuditContextNormalizerTest extends TestCase
{
    public function testNormalizeAppliesMaskingWhenRequested(): void
    {
        $normalizer = new AuditContextNormalizer(new ContextSanitizer(), new DataMasker());

        $result = $normalizer->normalize(
            ['api_token' => 'secret-value'],
            'App\\Entity\\Example',
            '42',
            true,
        );

        self::assertSame('********', $result['api_token']);
    }

    public function testNormalizePreservesNonAiContextWhenAiPayloadExceedsLimit(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit AI metadata for App\\Entity\\Example#42 truncated'));

        $normalizer = new AuditContextNormalizer(new ContextSanitizer(), new DataMasker(), $logger);
        $oversizedAiPayload = [];
        for ($index = 0; $index < 16; ++$index) {
            $oversizedAiPayload['summary_'.$index] = str_repeat('a', ContextSanitizer::MAX_STRING_BYTES);
        }

        $result = $normalizer->normalize(
            [
                'source' => 'unit-test',
                'ai' => $oversizedAiPayload,
            ],
            'App\\Entity\\Example',
            '42',
        );

        self::assertSame('unit-test', $result['source']);
        self::assertTrue($result['_ai_truncated'] ?? false);
        self::assertArrayHasKey('_original_size', $result);
        self::assertArrayNotHasKey('ai', $result);
    }
}
