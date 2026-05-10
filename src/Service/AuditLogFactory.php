<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Throwable;

use function in_array;
use function sprintf;

use const FILTER_VALIDATE_IP;

final readonly class AuditLogFactory
{
    private const array DIFFABLE_ACTIONS = [
        AuditAction::Update,
        AuditAction::SoftDelete,
        AuditAction::Restore,
    ];

    private DateTimeZone $tz;

    private EntityClassResolver $entityClassResolver;

    public function __construct(
        private ClockInterface $clock,
        private TransactionIdGenerator $transactionIdGenerator,
        private ContextResolverInterface $contextResolver,
        private EntityIdResolverInterface $idResolver,
        private ContextSanitizer $contextSanitizer,
        private AuditContextNormalizer $contextNormalizer,
        private ?LoggerInterface $logger = null,
        private string $timezone = 'UTC',
        ?EntityClassResolver $entityClassResolver = null,
    ) {
        $this->tz = new DateTimeZone($this->timezone);
        $this->entityClassResolver = $entityClassResolver ?? new EntityClassResolver();
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
        $entityClass = $this->entityClassResolver->resolve($entity, $entityManager);
        $changedFields = $this->resolveChangedFields($action, $newValues);
        $resolvedContext = $this->resolveContext($entity, $action, $newValues ?? [], $context);
        $ipAddress = $this->resolveValidatedIpAddress($resolvedContext['ipAddress']);

        return $this->buildAuditLog(
            $entityClass,
            $entityId,
            $action,
            $oldValues,
            $newValues,
            $changedFields,
            $resolvedContext,
            $ipAddress,
        );
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<int, string>|null   $changedFields
     * @param array{
     *     userId: mixed,
     *     username: mixed,
     *     ipAddress: mixed,
     *     userAgent: mixed,
     *     context: array<string, mixed>
     * } $resolvedContext
     */
    private function buildAuditLog(
        string $entityClass,
        ?string $entityId,
        AuditAction $action,
        ?array $oldValues,
        ?array $newValues,
        ?array $changedFields,
        array $resolvedContext,
        ?string $ipAddress,
    ): AuditLog {
        $auditLog = new AuditLog(
            entityClass: $entityClass,
            entityId: $entityId,
            action: $action,
            createdAt: $this->clock->now()->setTimezone($this->tz),
            oldValues: $this->sanitizeOptionalArray($oldValues),
            newValues: $this->sanitizeOptionalArray($newValues),
            changedFields: $this->sanitizeChangedFields($changedFields),
            transactionHash: $this->transactionIdGenerator->getTransactionId(),
            userId: $this->sanitizeResolvedContextField($resolvedContext, 'userId'),
            username: $this->sanitizeResolvedContextField($resolvedContext, 'username'),
            ipAddress: $ipAddress,
            userAgent: $this->sanitizeResolvedContextField($resolvedContext, 'userAgent'),
            context: $this->normalizeResolvedContext($resolvedContext, $entityClass, $entityId),
        );

        $auditLog->markContextNormalized();

        return $auditLog;
    }

    /**
     * @param array<string, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    private function sanitizeOptionalArray(?array $values): ?array
    {
        return $values === null ? null : $this->contextSanitizer->sanitizeArray($values);
    }

    /**
     * @param array<int, string>|null $changedFields
     *
     * @return array<int, string>|null
     */
    private function sanitizeChangedFields(?array $changedFields): ?array
    {
        if ($changedFields === null) {
            return null;
        }

        $sanitizedFields = [];
        foreach ($changedFields as $field) {
            $sanitizedFields[] = $this->contextSanitizer->sanitizeString($field);
        }

        return $sanitizedFields;
    }

    /**
     * @param array{
     *     userId: mixed,
     *     username: mixed,
     *     ipAddress: mixed,
     *     userAgent: mixed,
     *     context: array<string, mixed>
     * } $resolvedContext
     */
    private function sanitizeResolvedContextField(array $resolvedContext, string $field): ?string
    {
        return $this->sanitizeOptionalString($resolvedContext[$field]);
    }

    /**
     * @param array{
     *     userId: mixed,
     *     username: mixed,
     *     ipAddress: mixed,
     *     userAgent: mixed,
     *     context: array<string, mixed>
     * } $resolvedContext
     *
     * @return array<string, mixed>
     */
    private function normalizeResolvedContext(array $resolvedContext, string $entityClass, ?string $entityId): array
    {
        return $this->contextNormalizer->normalize($resolvedContext['context'], $entityClass, $entityId);
    }

    private function resolveValidatedIpAddress(mixed $value): ?string
    {
        $ipAddress = $this->sanitizeOptionalString($value);
        if ($ipAddress === null || filter_var($ipAddress, FILTER_VALIDATE_IP) !== false) {
            return $ipAddress;
        }

        $this->logger?->warning(sprintf('Invalid IP address format detected during audit: "%s". Nullifying.', $ipAddress));

        return null;
    }

    /**
     * @param array<string, mixed>|null $oldValues
     */
    private function resolveEntityId(
        object $entity,
        AuditAction $action,
        ?array $oldValues,
        EntityManagerInterface $entityManager,
    ): ?string {
        $entityId = $this->idResolver->resolveFromEntity($entity, $entityManager);
        if ($entityId === null && $action === AuditAction::Delete && $oldValues !== null) {
            $entityId = $this->idResolver->resolveFromValues($entity, $oldValues, $entityManager);
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

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->contextSanitizer->sanitizeString((string) $value);
    }
}
