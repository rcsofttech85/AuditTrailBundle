<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogAiProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

final class AuditLogContextProcessor
{
    /**
     * @param iterable<AuditLogAiProcessorInterface> $aiProcessors
     */
    public function __construct(
        private readonly ContextSanitizer $contextSanitizer,
        private readonly ?DataMaskerInterface $dataMasker = null,
        private readonly ?LoggerInterface $logger = null,
        #[AutowireIterator('audit_trail.ai_processor')]
        private readonly iterable $aiProcessors = [],
    ) {
    }

    public function prepare(AuditLog $audit, ?object $entity, AuditPhase $phase): void
    {
        $this->applyAiProcessors($audit, $entity, $phase);
        $this->enforceContextSafety($audit);
    }

    private function applyAiProcessors(AuditLog $audit, ?object $entity, AuditPhase $phase): void
    {
        if (!$phase->allowsAiProcessing()) {
            return;
        }

        $aiContext = [];

        foreach ($this->aiProcessors as $processor) {
            try {
                $result = $processor->process($audit->context, $entity);

                if ($result !== []) {
                    $aiContext = [...$aiContext, ...$result];
                }
            } catch (Throwable $e) {
                $this->logger?->warning(
                    sprintf('Audit AI processor %s failed for %s#%s: %s', $processor::class, $audit->entityClass, $audit->entityId, $e->getMessage()),
                    ['exception' => $e],
                );
            }
        }

        if ($aiContext !== []) {
            $audit->context = [...$audit->context, 'ai' => $aiContext];
        }
    }

    private function enforceContextSafety(AuditLog $audit): void
    {
        $context = $audit->context;

        try {
            if ($this->dataMasker !== null) {
                $context = $this->dataMasker->redact($context);
            }

            $context = $this->contextSanitizer->sanitizeArray($context);
            $context = $this->truncateOversizedContext($audit, $context);
        } catch (Throwable $e) {
            $this->logger?->warning(
                sprintf(
                    'Audit context safety failed for %s#%s: %s',
                    $audit->entityClass,
                    $audit->entityId,
                    $e->getMessage(),
                ),
                ['exception' => $e],
            );

            $context = [
                '_context_safety_error' => true,
                '_message' => 'Context could not be normalized safely.',
            ];
        }

        $audit->context = $context;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function truncateOversizedContext(AuditLog $audit, array $context): array
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
                        $audit->entityClass,
                        $audit->entityId,
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
                $audit->entityClass,
                $audit->entityId,
                $encodedSize,
                ContextSanitizer::MAX_CONTEXT_BYTES,
            ),
        );

        return ['_truncated' => true, '_original_size' => $encodedSize];
    }
}
