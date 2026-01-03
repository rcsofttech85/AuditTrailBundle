<?php

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

class AuditService
{
    private const string PENDING_ID = 'pending';

    /**
     * @param array<string>                              $ignoredEntities
     * @param iterable<AuditVoterInterface>              $voters
     * @param iterable<AuditContextContributorInterface> $contributors
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserResolverInterface $userResolver,
        private readonly ClockInterface $clock,
        private readonly TransactionIdGenerator $transactionIdGenerator,
        private readonly EntityDataExtractor $dataExtractor,
        private readonly MetadataCache $metadataCache,
        private readonly array $ignoredEntities = [],
        private readonly ?LoggerInterface $logger = null,
        private readonly string $timezone = 'UTC',
        #[AutowireIterator('audit_trail.voter')] private readonly iterable $voters = [],
        #[AutowireIterator('audit_trail.context_contributor')] private readonly iterable $contributors = [],
    ) {
    }

    /**
     * Check if the entity should be audited.
     *
     * @param array<string, mixed> $changeSet
     */
    public function shouldAudit(
        object $entity,
        string $action = AuditLogInterface::ACTION_CREATE,
        array $changeSet = [],
    ): bool {
        $class = $entity::class;

        if (\in_array($class, $this->ignoredEntities, true)) {
            return false;
        }

        $auditable = $this->metadataCache->getAuditableAttribute($class);

        if (null === $auditable || !$auditable->enabled) {
            return false;
        }

        foreach ($this->voters as $voter) {
            if (!$voter->vote($entity, $action, $changeSet)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract entity data for auditing.
     *
     * @param array<string> $additionalIgnored
     *
     * @return array<string, mixed>
     */
    public function getEntityData(object $entity, array $additionalIgnored = []): array
    {
        return $this->dataExtractor->extract($entity, $additionalIgnored);
    }

    /**
     * Create audit log entry.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<string, mixed>      $context
     */
    public function createAuditLog(
        object $entity,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
    ): AuditLogInterface {
        $auditLog = new AuditLog();
        $auditLog->setEntityClass($entity::class);

        $entityId = $this->getEntityId($entity);
        if (self::PENDING_ID === $entityId && AuditLogInterface::ACTION_DELETE === $action && null !== $oldValues) {
            $entityId = $this->extractIdFromValues($entity, $oldValues) ?? self::PENDING_ID;
        }
        $auditLog->setEntityId($entityId);

        $auditLog->setAction($action);
        $auditLog->setOldValues($oldValues);
        $auditLog->setNewValues($newValues);

        if (AuditLogInterface::ACTION_UPDATE === $action && null !== $oldValues && null !== $newValues) {
            $auditLog->setChangedFields($this->detectChangedFields($oldValues, $newValues));
        }

        $this->enrichWithUserContext($auditLog, $entity, $context);
        $auditLog->setTransactionHash($this->transactionIdGenerator->getTransactionId());
        $auditLog->setCreatedAt($this->clock->now()->setTimezone(new \DateTimeZone($this->timezone)));

        return $auditLog;
    }

    /**
     * @return array<string, string>
     */
    public function getSensitiveFields(object $entity): array
    {
        return $this->metadataCache->getSensitiveFields($entity::class);
    }

    /**
     * Detect changed fields between old and new values.
     *
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     *
     * @return array<int, string>
     */
    private function detectChangedFields(array $oldValues, array $newValues): array
    {
        $changed = [];

        foreach ($newValues as $field => $newValue) {
            if (!\array_key_exists($field, $oldValues)) {
                $changed[] = $field;
                continue;
            }

            if ($this->valuesAreDifferent($oldValues[$field], $newValue)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    private function valuesAreDifferent(mixed $oldValue, mixed $newValue): bool
    {
        if (null === $oldValue || null === $newValue) {
            return $oldValue !== $newValue;
        }

        if (is_numeric($oldValue) && is_numeric($newValue)) {
            return abs((float) $oldValue - (float) $newValue) > 1e-9;
        }

        return $oldValue !== $newValue;
    }

    /**
     * @param array<string, mixed> $extraContext
     */
    private function enrichWithUserContext(AuditLog $auditLog, object $entity, array $extraContext = []): void
    {
        try {
            $auditLog->setUserId($this->userResolver->getUserId());
            $auditLog->setUsername($this->userResolver->getUsername());
            $auditLog->setIpAddress($this->userResolver->getIpAddress());
            $auditLog->setUserAgent($this->userResolver->getUserAgent());

            $context = [...$auditLog->getContext(), ...$extraContext];
            $impersonatorId = $this->userResolver->getImpersonatorId();
            if (null !== $impersonatorId) {
                $context['impersonation'] = [
                    'impersonator_id' => $impersonatorId,
                    'impersonator_username' => $this->userResolver->getImpersonatorUsername(),
                ];
            }

            // Add custom context from contributors
            foreach ($this->contributors as $contributor) {
                $context = [
                    ...$context,
                    ...$contributor->contribute($entity, $auditLog->getAction(), $auditLog->getNewValues() ?? []),
                ];
            }

            $auditLog->setContext($context);
        } catch (\Throwable $e) {
            $this->logger?->error('Failed to set user context', ['exception' => $e->getMessage()]);
        }
    }

    public function getEntityId(object $entity): string
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);

            if ([] === $ids) {
                if (method_exists($entity, 'getId')) {
                    $id = $entity->getId();

                    $isStringable = is_scalar($id) || $id instanceof \Stringable;

                    return (null !== $id && $isStringable) ? (string) $id : self::PENDING_ID;
                }

                return self::PENDING_ID;
            }

            $idValues = array_filter(
                array_map(fn ($v) => is_scalar($v) || $v instanceof \Stringable ? (string) $v : '', $ids),
                fn ($id) => '' !== $id
            );

            if ([] === $idValues) {
                return self::PENDING_ID;
            }

            return count($idValues) > 1
                ? json_encode(array_values($idValues), JSON_THROW_ON_ERROR)
                : reset($idValues);
        } catch (\Throwable $e) {
            if (method_exists($entity, 'getId')) {
                try {
                    $id = $entity->getId();

                    $isStringable = is_scalar($id) || $id instanceof \Stringable;

                    return (null !== $id && $isStringable) ? (string) $id : self::PENDING_ID;
                } catch (\Throwable) {
                }
            }

            $this->logger?->error('Failed to get entity ID', [
                'exception' => $e->getMessage(),
                'entity' => $entity::class,
            ]);

            return self::PENDING_ID;
        }
    }

    /**
     * @param array<string, mixed> $values
     */
    private function extractIdFromValues(object $entity, array $values): ?string
    {
        try {
            $meta = $this->entityManager->getClassMetadata($entity::class);
            $idFields = $meta->getIdentifierFieldNames();
            $ids = [];

            foreach ($idFields as $idField) {
                if (!isset($values[$idField])) {
                    return null;
                }
                $val = $values[$idField];
                $ids[] = (is_scalar($val) || $val instanceof \Stringable) ? (string) $val : '';
            }

            return count($ids) > 1
                ? json_encode($ids, JSON_THROW_ON_ERROR)
                : ($ids[0] ?? null);
        } catch (\Throwable) {
            return null;
        }
    }
}
