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
        $encodedSize = $this->resolveContextSize($context);
        if ($this->fitsWithinContextLimit($encodedSize)) {
            return $context;
        }

        return $this->resolveTruncatedContext($context, $encodedSize, $entityClass, $entityId);
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function resolveTruncatedContext(
        array $context,
        int $encodedSize,
        string $entityClass,
        ?string $entityId,
    ): array {
        $aiTruncatedContext = $this->truncateAiContextIfPossible($context, $encodedSize);
        if ($aiTruncatedContext !== null) {
            $this->logAiContextTruncation($entityClass, $entityId, $encodedSize);

            return $aiTruncatedContext;
        }

        $this->logContextTruncation($entityClass, $entityId, $encodedSize);

        return ['_truncated' => true, '_original_size' => $encodedSize];
    }

    private function logAiContextTruncation(string $entityClass, ?string $entityId, int $encodedSize): void
    {
        $this->logger?->warning(
            sprintf(
                'Audit AI metadata for %s#%s truncated (%d bytes exceeded %d limit).',
                $entityClass,
                $entityId ?? '[unresolved]',
                $encodedSize,
                ContextSanitizer::MAX_CONTEXT_BYTES,
            ),
        );
    }

    private function logContextTruncation(string $entityClass, ?string $entityId, int $encodedSize): void
    {
        $this->logger?->warning(
            sprintf(
                'Audit context for %s#%s truncated (%d bytes exceeded %d limit).',
                $entityClass,
                $entityId ?? '[unresolved]',
                $encodedSize,
                ContextSanitizer::MAX_CONTEXT_BYTES,
            ),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveContextSize(array $context): int
    {
        return strlen(json_encode($context, JSON_THROW_ON_ERROR));
    }

    private function fitsWithinContextLimit(int $encodedSize): bool
    {
        return $encodedSize <= ContextSanitizer::MAX_CONTEXT_BYTES;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>|null
     */
    private function truncateAiContextIfPossible(array $context, int $encodedSize): ?array
    {
        if (!isset($context['ai'])) {
            return null;
        }

        $preservedContext = $context;
        unset($preservedContext['ai']);
        $preservedContext['_ai_truncated'] = true;
        $preservedContext['_original_size'] = $encodedSize;

        return $this->fitsWithinContextLimit($this->resolveContextSize($preservedContext))
            ? $preservedContext
            : null;
    }
}
