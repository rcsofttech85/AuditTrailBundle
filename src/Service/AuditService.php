<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityDataExtractorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function in_array;
use function sprintf;
use function strlen;

use const FILTER_VALIDATE_IP;
use const JSON_THROW_ON_ERROR;

final readonly class AuditService implements AuditServiceInterface
{
    private const array DIFFABLE_ACTIONS = [
        AuditLogInterface::ACTION_UPDATE,
        AuditLogInterface::ACTION_SOFT_DELETE,
        AuditLogInterface::ACTION_RESTORE,
    ];

    private DateTimeZone $tz;

    /**
     * @param iterable<AuditVoterInterface> $voters
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private TransactionIdGenerator $transactionIdGenerator,
        private EntityDataExtractorInterface $dataExtractor,
        private AuditMetadataManagerInterface $metadataManager,
        private ContextResolverInterface $contextResolver,
        private EntityIdResolverInterface $idResolver,
        private ContextSanitizer $contextSanitizer,
        private ?LoggerInterface $logger = null,
        private string $timezone = 'UTC',
        #[AutowireIterator('audit_trail.voter')]
        private iterable $voters = [],
    ) {
        $this->tz = new DateTimeZone($this->timezone);
    }

    #[Override]
    public function shouldAudit(
        object $entity,
        string $action = AuditLogInterface::ACTION_CREATE,
        array $changeSet = [],
    ): bool {
        if ($this->metadataManager->isEntityIgnored($entity::class)) {
            return false;
        }

        return $this->passesVoters($entity, $action, $changeSet);
    }

    /**
     * Evaluate all registered voters for the given entity and action.
     *
     * @param array<string, mixed> $changeSet
     */
    public function passesVoters(object $entity, string $action, array $changeSet = []): bool
    {
        foreach ($this->voters as $voter) {
            if (!$voter->vote($entity, $action, $changeSet)) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function getAccessAttribute(string $class): ?\Rcsofttech\AuditTrailBundle\Attribute\AuditAccess
    {
        return $this->metadataManager->getAuditAccessAttribute($class);
    }

    #[Override]
    public function getEntityData(
        object $entity,
        array $additionalIgnored = [],
        ?EntityManagerInterface $entityManager = null,
    ): array {
        $ignored = $this->metadataManager->getIgnoredProperties($entity, $additionalIgnored);

        return $this->dataExtractor->extract($entity, $ignored, $entityManager ?? $this->entityManager);
    }

    #[Override]
    public function createAuditLog(
        object $entity,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
        ?EntityManagerInterface $entityManager = null,
    ): AuditLog {
        $entityManager ??= $this->entityManager;
        $entityId = $this->idResolver->resolveFromEntity($entity, $entityManager);
        if ($entityId === AuditLogInterface::PENDING_ID && $action === AuditLogInterface::ACTION_DELETE && $oldValues !== null) {
            $entityId = $this->idResolver->resolveFromValues(
                $entity,
                $oldValues,
                $entityManager
            ) ?? AuditLogInterface::PENDING_ID;
        }

        $changedFields = (in_array($action, self::DIFFABLE_ACTIONS, true) && $newValues !== null)
            ? array_keys($newValues)
            : null;

        try {
            $resolvedContext = $this->contextResolver->resolve($entity, $action, $newValues ?? [], $context);
        } catch (Throwable $e) {
            $this->logger?->warning('Failed to resolve audit context: '.$e->getMessage());
            $resolvedContext = [
                'userId' => null,
                'username' => null,
                'ipAddress' => null,
                'userAgent' => null,
                'context' => ['_error' => 'Context resolution failed'],
            ];
        }

        $oldValues = $oldValues !== null ? $this->sanitizeAuditValues($oldValues) : null;
        $newValues = $newValues !== null ? $this->sanitizeAuditValues($newValues) : null;
        $changedFields = $changedFields !== null ? $this->sanitizeChangedFields($changedFields) : null;
        $contextData = $this->enforceContextSafety($entity::class, (string) $entityId, $resolvedContext['context']);
        $userId = $this->sanitizeOptionalString($resolvedContext['userId']);
        $username = $this->sanitizeOptionalString($resolvedContext['username']);
        $ipAddress = $this->sanitizeOptionalString($resolvedContext['ipAddress']);
        $userAgent = $this->sanitizeOptionalString($resolvedContext['userAgent']);
        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $this->logger?->warning(sprintf('Invalid IP address format detected during audit: "%s". Nullifying.', $ipAddress));
            $ipAddress = null;
        }

        return new AuditLog(
            entityClass: $entity::class,
            entityId: (string) $entityId,
            action: $action,
            createdAt: $this->clock->now()->setTimezone($this->tz),
            oldValues: $oldValues,
            newValues: $newValues,
            changedFields: $changedFields,
            transactionHash: $this->transactionIdGenerator->getTransactionId(),
            userId: $userId,
            username: $username,
            ipAddress: $ipAddress,
            userAgent: $userAgent,
            context: $contextData
        );
    }

    #[Override]
    public function getSensitiveFields(object $entity): array
    {
        return $this->metadataManager->getSensitiveFields($entity::class);
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
                sprintf(
                    'Audit context safety failed for %s#%s: %s',
                    $entityClass,
                    $entityId,
                    $e->getMessage(),
                ),
                ['exception' => $e],
            );

            return [
                '_context_safety_error' => true,
                '_message' => 'Context could not be normalized safely.',
            ];
        }
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function sanitizeAuditValues(array $values): array
    {
        return $this->contextSanitizer->sanitizeArray($values);
    }

    /**
     * @param array<int, string> $fields
     *
     * @return array<int, string>
     */
    private function sanitizeChangedFields(array $fields): array
    {
        return array_map($this->contextSanitizer->sanitizeString(...), $fields);
    }

    private function sanitizeOptionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->contextSanitizer->sanitizeString((string) $value);
    }
}
