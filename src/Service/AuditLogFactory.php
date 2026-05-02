<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Throwable;

use function in_array;
use function sprintf;
use function strlen;

use const FILTER_VALIDATE_IP;
use const JSON_THROW_ON_ERROR;

final readonly class AuditLogFactory
{
    private const array DIFFABLE_ACTIONS = [
        AuditAction::Update,
        AuditAction::SoftDelete,
        AuditAction::Restore,
    ];

    private DateTimeZone $tz;

    public function __construct(
        private ClockInterface $clock,
        private TransactionIdGenerator $transactionIdGenerator,
        private ContextResolverInterface $contextResolver,
        private EntityIdResolverInterface $idResolver,
        private ContextSanitizer $contextSanitizer,
        private ?LoggerInterface $logger = null,
        private string $timezone = 'UTC',
    ) {
        $this->tz = new DateTimeZone($this->timezone);
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>      $context
     */
    public function create(
        object $entity,
        AuditAction $action,
        ?array $oldValues,
        ?array $newValues,
        array $context,
        EntityManagerInterface $entityManager,
    ): AuditLog {
        $entityId = $this->resolveEntityId($entity, $action, $oldValues, $entityManager);
        $changedFields = $this->resolveChangedFields($action, $newValues);
        $resolvedContext = $this->resolveContext($entity, $action, $newValues ?? [], $context);
        $ipAddress = $this->sanitizeOptionalString($resolvedContext['ipAddress']);

        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $this->logger?->warning(sprintf('Invalid IP address format detected during audit: "%s". Nullifying.', $ipAddress));
            $ipAddress = null;
        }

        $auditLog = new AuditLog(
            entityClass: $entity::class,
            entityId: (string) $entityId,
            action: $action,
            createdAt: $this->clock->now()->setTimezone($this->tz),
            oldValues: $oldValues !== null ? $this->contextSanitizer->sanitizeArray($oldValues) : null,
            newValues: $newValues !== null ? $this->contextSanitizer->sanitizeArray($newValues) : null,
            changedFields: $changedFields !== null ? array_map($this->contextSanitizer->sanitizeString(...), $changedFields) : null,
            transactionHash: $this->transactionIdGenerator->getTransactionId(),
            userId: $this->sanitizeOptionalString($resolvedContext['userId']),
            username: $this->sanitizeOptionalString($resolvedContext['username']),
            ipAddress: $ipAddress,
            userAgent: $this->sanitizeOptionalString($resolvedContext['userAgent']),
            context: $this->enforceContextSafety($entity::class, (string) $entityId, $resolvedContext['context']),
        );

        $auditLog->markContextNormalized();

        return $auditLog;
    }

    /**
     * @param array<string, mixed>|null $oldValues
     */
    private function resolveEntityId(
        object $entity,
        AuditAction $action,
        ?array $oldValues,
        EntityManagerInterface $entityManager,
    ): string|int {
        $entityId = $this->idResolver->resolveFromEntity($entity, $entityManager);
        if ($entityId === AuditLogInterface::PENDING_ID && $action === AuditAction::Delete && $oldValues !== null) {
            $entityId = $this->idResolver->resolveFromValues($entity, $oldValues, $entityManager) ?? AuditLogInterface::PENDING_ID;
        }

        return $entityId;
    }

    /**
     * @param array<string, mixed>|null $newValues
     *
     * @return array<int, string>|null
     */
    private function resolveChangedFields(AuditAction $action, ?array $newValues): ?array
    {
        if (!in_array($action, self::DIFFABLE_ACTIONS, true) || $newValues === null) {
            return null;
        }

        return array_keys($newValues);
    }

    /**
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $context
     *
     * @return array{
     *     userId: mixed,
     *     username: mixed,
     *     ipAddress: mixed,
     *     userAgent: mixed,
     *     context: array<string, mixed>
     * }
     */
    private function resolveContext(object $entity, AuditAction $action, array $newValues, array $context): array
    {
        try {
            return $this->contextResolver->resolve($entity, $action, $newValues, $context);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to resolve audit context: '.$e->getMessage());

            return [
                'userId' => null,
                'username' => null,
                'ipAddress' => null,
                'userAgent' => null,
                'context' => ['_error' => 'Context resolution failed'],
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function enforceContextSafety(string $entityClass, string $entityId, array $context): array
    {
        try {
            $context = $this->contextSanitizer->sanitizeArray($context);

            $encoded = json_encode($context, JSON_THROW_ON_ERROR);
            $encodedSize = strlen($encoded);

            if ($encodedSize > ContextSanitizer::MAX_CONTEXT_BYTES) {
                $this->logger?->warning(
                    sprintf(
                        'Audit context for %s#%s truncated (%d bytes exceeded %d limit).',
                        $entityClass,
                        $entityId,
                        $encodedSize,
                        ContextSanitizer::MAX_CONTEXT_BYTES,
                    ),
                );

                return ['_truncated' => true, '_original_size' => $encodedSize];
            }

            return $context;
        } catch (Throwable $e) {
            $this->logger?->warning(
                sprintf('Audit context safety failed for %s#%s: %s', $entityClass, $entityId, $e->getMessage()),
                ['exception' => $e],
            );

            return [
                '_context_safety_error' => true,
                '_message' => 'Context could not be normalized safely.',
            ];
        }
    }

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->contextSanitizer->sanitizeString((string) $value);
    }
}
