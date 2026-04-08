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

use function array_key_exists;
use function is_array;
use function sprintf;
use function strlen;
use function trim;

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

        $existingAiContext = [];

        if (isset($audit->context['ai']) && is_array($audit->context['ai'])) {
            $existingAiContext = $audit->context['ai'];
        }

        $aiContext = $existingAiContext;

        foreach ($this->aiProcessors as $processor) {
            try {
                $namespace = trim($processor->getNamespace());

                if ($namespace === '') {
                    $this->logger?->warning(
                        sprintf('Audit AI processor %s returned an empty namespace and was skipped.', $processor::class),
                    );

                    continue;
                }

                $result = $processor->process($audit->context, $entity);

                if ($result !== []) {
                    $aiContext = $this->mergeAiProcessorResult($aiContext, $result, $processor, $namespace);
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

    /**
     * @param array<string, mixed> $aiContext
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function mergeAiProcessorResult(array $aiContext, array $result, AuditLogAiProcessorInterface $processor, string $namespace): array
    {
        $existingNamespace = isset($aiContext[$namespace]) && is_array($aiContext[$namespace]) ? $aiContext[$namespace] : [];
        $aiContext[$namespace] = $this->mergeAiArrays($existingNamespace, $result, $processor::class.':'.$namespace);

        return $aiContext;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     *
     * @return array<string, mixed>
     */
    private function mergeAiArrays(array $base, array $incoming, string $source): array
    {
        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $base)) {
                $base[$key] = $value;

                continue;
            }

            if (is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->mergeAiArrays($base[$key], $value, $source.'.'.$key);

                continue;
            }

            $this->logger?->warning(
                sprintf('Audit AI key collision for "%s" from %s; preserving the existing value.', $key, $source),
            );
        }

        return $base;
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
