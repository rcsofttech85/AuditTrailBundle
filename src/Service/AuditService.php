<?php

declare(strict_types=1);

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
     * @param array<string>                              $ignoredProperties
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
        private readonly array $ignoredProperties = [],
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
        $ignored = $this->getIgnoredProperties($entity, $additionalIgnored);

        return $this->dataExtractor->extract($entity, $ignored);
    }

    /**
     * @param array<string> $additionalIgnored
     *
     * @return array<string>
     */
    public function getIgnoredProperties(object $entity, array $additionalIgnored = []): array
    {
        $ignored = [...$this->ignoredProperties, ...$additionalIgnored];

        $auditable = $this->metadataCache->getAuditableAttribute($entity::class);
        if (null !== $auditable) {
            $ignored = [...$ignored, ...$auditable->ignoredProperties];
        }

        return array_unique($ignored);
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

        $entityId = EntityIdResolver::resolveFromEntity($entity, $this->entityManager);
        if (self::PENDING_ID === $entityId && AuditLogInterface::ACTION_DELETE === $action && null !== $oldValues) {
            $entityId = EntityIdResolver::resolveFromValues(
                $entity,
                $oldValues,
                $this->entityManager
            ) ?? self::PENDING_ID;
        }
        $auditLog->setEntityId($entityId);

        $auditLog->setAction($action);
        $auditLog->setOldValues($oldValues);
        $auditLog->setNewValues($newValues);

        if (AuditLogInterface::ACTION_UPDATE === $action && null !== $newValues) {
            $auditLog->setChangedFields(array_keys($newValues));
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
}
