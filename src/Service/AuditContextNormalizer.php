<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Throwable;

use function json_encode;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

final readonly class AuditContextNormalizer
{
    public function __construct(
        private ContextSanitizer $contextSanitizer,
        private ?DataMaskerInterface $dataMasker = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(
        array $context,
        string $entityClass,
        ?string $entityId,
        bool $applyMasking = false,
    ): array {
        try {
            if ($applyMasking && $this->dataMasker !== null) {
                $context = $this->dataMasker->redact($context);
            }

            $context = $this->contextSanitizer->sanitizeArray($context);

            return $this->truncateOversizedContext($context, $entityClass, $entityId);
        } catch (Throwable $exception) {
            $this->logger?->warning(
                sprintf(
                    'Audit context safety failed for %s#%s: %s',
                    $entityClass,
                    $entityId ?? '[unresolved]',
                    $exception->getMessage(),
                ),
                ['exception' => $exception],
            );

            return [
                '_context_safety_error' => true,
                '_message' => 'Context could not be normalized safely.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function truncateOversizedContext(array $context, string $entityClass, ?string $entityId): array
    {
        $encoded = json_encode($context, JSON_THROW_ON_ERROR);
        $encodedSize = strlen($encoded);

        if ($encodedSize <= ContextSanitizer::MAX_CONTEXT_BYTES) {
            return $context;
        }

        if (isset($context['ai'])) {
            $preservedContext = $context;
            unset($preservedContext['ai']);
            $preservedContext['_ai_truncated'] = true;
            $preservedContext['_original_size'] = $encodedSize;

            $preservedEncoded = json_encode($preservedContext, JSON_THROW_ON_ERROR);
            if (strlen($preservedEncoded) <= ContextSanitizer::MAX_CONTEXT_BYTES) {
                $this->logger?->warning(
                    sprintf(
                        'Audit AI metadata for %s#%s truncated (%d bytes exceeded %d limit).',
                        $entityClass,
                        $entityId ?? '[unresolved]',
                        $encodedSize,
                        ContextSanitizer::MAX_CONTEXT_BYTES,
                    ),
                );

                return $preservedContext;
            }
        }

        $this->logger?->warning(
            sprintf(
                'Audit context for %s#%s truncated (%d bytes exceeded %d limit).',
                $entityClass,
                $entityId ?? '[unresolved]',
                $encodedSize,
                ContextSanitizer::MAX_CONTEXT_BYTES,
            ),
        );

        return ['_truncated' => true, '_original_size' => $encodedSize];
    }
}
