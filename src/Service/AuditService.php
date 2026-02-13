<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;
use Traversable;

use function in_array;
use function is_scalar;

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
        #[AutowireIterator('audit_trail.voter')]
        private readonly iterable $voters = [],
        #[AutowireIterator('audit_trail.context_contributor')]
        private readonly iterable $contributors = [],
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

        if (in_array($class, $this->ignoredEntities, true)) {
            return false;
        }

        $auditable = $this->metadataCache->getAuditableAttribute($class);

        if ($auditable === null || !$auditable->enabled) {
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
        return array_all(
            $this->voters instanceof Traversable ? iterator_to_array($this->voters) : $this->voters,
            static fn (AuditVoterInterface $voter) => $voter->vote($entity, $action, $changeSet)
        );
    }

    public function getAccessAttribute(string $class): ?\Rcsofttech\AuditTrailBundle\Attribute\AuditAccess
    {
        return $this->metadataCache->getAuditAccessAttribute($class);
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
        if ($auditable !== null) {
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
        if ($entityId === self::PENDING_ID && $action === AuditLogInterface::ACTION_DELETE && $oldValues !== null) {
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

        if ($action === AuditLogInterface::ACTION_UPDATE && $newValues !== null) {
            $auditLog->setChangedFields(array_keys($newValues));
        }

        $this->enrichWithUserContext($auditLog, $entity, $context);
        $auditLog->setTransactionHash($this->transactionIdGenerator->getTransactionId());
        $auditLog->setCreatedAt($this->clock->now()->setTimezone(new DateTimeZone($this->timezone)));

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
            $userId = $extraContext[AuditLogInterface::CONTEXT_USER_ID] ?? $this->userResolver->getUserId();
            $username = $extraContext[AuditLogInterface::CONTEXT_USERNAME] ?? $this->userResolver->getUsername();

            $auditLog->setUserId((is_scalar($userId) || ($userId instanceof Stringable)) ? (string) $userId : null);
            $auditLog->setUsername((is_scalar($username) || ($username instanceof Stringable)) ? (string) $username : null);
            $auditLog->setIpAddress($this->userResolver->getIpAddress());
            $auditLog->setUserAgent($this->userResolver->getUserAgent());

            // Remove internal "transport" keys so they don't pollute the JSON storage
            $context = array_diff_key($extraContext, [
                AuditLogInterface::CONTEXT_USER_ID => true,
                AuditLogInterface::CONTEXT_USERNAME => true,
            ]);

            $impersonatorId = $this->userResolver->getImpersonatorId();
            if ($impersonatorId !== null) {
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
        } catch (Throwable $e) {
            $this->logger?->error('Failed to set user context', ['exception' => $e->getMessage()]);
        }
    }
}
