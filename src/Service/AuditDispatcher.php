<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use BackedEnum;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use LogicException;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogAiProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;
use UnitEnum;

use function get_debug_type;
use function get_resource_type;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function mb_check_encoding;
use function method_exists;
use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

final class AuditDispatcher implements AuditDispatcherInterface
{
    private const array DEFERRED_FALLBACK_PHASES = ['post_flush', 'post_load', 'batch_flush'];

    private const array AI_SAFE_PHASES = ['post_flush', 'batch_flush', 'manual_flush'];

    private const int MAX_CONTEXT_BYTES = 65_536;

    private const int MAX_CONTEXT_DEPTH = 5;

    /**
     * @param iterable<AuditLogAiProcessorInterface> $aiProcessors
     */
    public function __construct(
        private readonly AuditTransportInterface $transport,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?AuditIntegrityServiceInterface $integrityService = null,
        private readonly ?DataMaskerInterface $dataMasker = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $failOnTransportError = false,
        private readonly bool $fallbackToDatabase = true,
        #[AutowireIterator('audit_trail.ai_processor')]
        private readonly iterable $aiProcessors = [],
    ) {
    }

    public function dispatch(
        AuditLog $audit,
        EntityManagerInterface $em,
        string $phase,
        ?UnitOfWork $uow = null,
        ?object $entity = null,
    ): bool {
        $context = [
            'phase' => $phase,
            'em' => $em,
            'audit' => $audit,
        ];

        if ($uow !== null) {
            $context['uow'] = $uow;
        }

        if ($entity !== null) {
            $context['entity'] = $entity;
        }

        if (!$this->transport->supports($phase, $context)) {
            return false;
        }

        $this->applyAiProcessors($audit, $entity, $phase);
        $this->enforceContextSafety($audit);
        $context['audit'] = $audit;

        if ($this->eventDispatcher !== null) {
            $event = new AuditLogCreatedEvent($audit, $entity);
            $this->eventDispatcher->dispatch($event);
            $audit = $event->auditLog;
            $context['audit'] = $audit;
            $this->enforceContextSafety($audit);
            $context['audit'] = $audit;
        }

        if ($this->integrityService?->isEnabled() === true) {
            $audit->signature = $this->integrityService->generateSignature($audit);
        }

        try {
            $this->transport->send($audit, $context);
            $audit->seal();
        } catch (Throwable $e) {
            $this->logger?->error(
                sprintf('Audit transport failed for %s#%s: %s', $audit->entityClass, $audit->entityId, $e->getMessage()),
                ['exception' => $e]
            );

            if ($this->failOnTransportError) {
                throw $e;
            }

            if ($this->fallbackToDatabase) {
                return $this->persistFallback($audit, $em, $phase, $uow);
            }

            return false;
        }

        return true;
    }

    private function applyAiProcessors(AuditLog $audit, ?object $entity, string $phase): void
    {
        if (!in_array($phase, self::AI_SAFE_PHASES, true)) {
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

            $context = $this->sanitizeContext($context);
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
    private function sanitizeContext(array $context): array
    {
        return $this->sanitizeArray($context, 0);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private function sanitizeArray(array $values, int $depth): array
    {
        if ($depth >= self::MAX_CONTEXT_DEPTH) {
            return ['_max_depth_reached' => true];
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $sanitized[(string) $key] = $this->sanitizeValue($value, $depth + 1);
        }

        return $sanitized;
    }

    private function sanitizeValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value), is_float($value) => $value,
            is_string($value) => $this->sanitizeString($value),
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_array($value) => $this->sanitizeArray($value, $depth),
            is_resource($value) => sprintf('[resource:%s]', get_resource_type($value)),
            is_object($value) && ($value instanceof Stringable || method_exists($value, '__toString')) => $this->sanitizeString((string) $value),
            is_object($value) => $value::class,
            default => sprintf('[%s]', get_debug_type($value)),
        };
    }

    private function sanitizeString(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return '[invalid utf-8]';
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

        if ($encodedSize <= self::MAX_CONTEXT_BYTES) {
            return $context;
        }

        if (isset($context['ai'])) {
            $preservedContext = $context;
            unset($preservedContext['ai']);
            $preservedContext['_ai_truncated'] = true;
            $preservedContext['_original_size'] = $encodedSize;

            $preservedEncoded = json_encode($preservedContext, JSON_THROW_ON_ERROR);
            if (strlen($preservedEncoded) <= self::MAX_CONTEXT_BYTES) {
                $this->logger?->warning(
                    sprintf(
                        'Audit AI metadata for %s#%s truncated (%d bytes exceeded %d limit).',
                        $audit->entityClass,
                        $audit->entityId,
                        $encodedSize,
                        self::MAX_CONTEXT_BYTES,
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
                self::MAX_CONTEXT_BYTES,
            ),
        );

        return ['_truncated' => true, '_original_size' => $encodedSize];
    }

    private function persistFallback(
        AuditLog $audit,
        EntityManagerInterface $em,
        string $phase,
        ?UnitOfWork $uow = null,
    ): bool {
        try {
            if (!$em->contains($audit)) {
                $em->persist($audit);
            }

            return match (true) {
                $phase === 'on_flush' => $this->persistOnFlushFallback($audit, $em, $uow),
                in_array($phase, self::DEFERRED_FALLBACK_PHASES, true) => $this->finalizeDeferredFallback($audit),
                default => $this->deferFallbackWithoutImplicitFlush($audit, $phase),
            };
        } catch (Throwable $fallbackError) {
            $this->logger?->critical(
                sprintf('AUDIT LOSS: Failed to persist fallback for %s#%s: %s', $audit->entityClass, $audit->entityId, $fallbackError->getMessage()),
                ['exception' => $fallbackError],
            );

            return false;
        }
    }

    private function persistOnFlushFallback(
        AuditLog $audit,
        EntityManagerInterface $em,
        ?UnitOfWork $uow,
    ): bool {
        if ($uow === null) {
            throw new LogicException('UnitOfWork is required to persist fallback audit logs during on_flush.');
        }

        $uow->computeChangeSet($em->getClassMetadata(AuditLog::class), $audit);

        return $this->finalizeDeferredFallback($audit);
    }

    private function deferFallbackWithoutImplicitFlush(AuditLog $audit, string $phase): bool
    {
        $audit->seal();

        $this->logger?->warning(
            sprintf(
                'Audit log for %s#%s queued via database fallback during phase "%s" without an implicit flush. Persist it with the next application flush.',
                $audit->entityClass,
                $audit->entityId,
                $phase,
            ),
        );

        return true;
    }

    private function finalizeDeferredFallback(AuditLog $audit): bool
    {
        $audit->seal();

        $this->logger?->warning(
            sprintf('Audit log for %s#%s saved via database fallback.', $audit->entityClass, $audit->entityId),
        );

        return true;
    }
}
