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
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function sprintf;
use function strlen;

use const JSON_THROW_ON_ERROR;

final class AuditService implements AuditServiceInterface
{
    private const string PENDING_ID = 'pending';

    private const int MAX_CONTEXT_BYTES = 65_536;

    private readonly DateTimeZone $tz;

    /**
     * @param iterable<AuditVoterInterface> $voters
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly TransactionIdGenerator $transactionIdGenerator,
        private readonly EntityDataExtractor $dataExtractor,
        private readonly AuditMetadataManagerInterface $metadataManager,
        private readonly ContextResolverInterface $contextResolver,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $timezone = 'UTC',
        #[AutowireIterator('audit_trail.voter')]
        private readonly iterable $voters = [],
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
    public function getEntityData(object $entity, array $additionalIgnored = []): array
    {
        $ignored = $this->metadataManager->getIgnoredProperties($entity, $additionalIgnored);

        return $this->dataExtractor->extract($entity, $ignored);
    }

    #[Override]
    public function createAuditLog(
        object $entity,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
    ): AuditLog {
        $entityId = $this->idResolver->resolveFromEntity($entity, $this->entityManager);
        if ($entityId === self::PENDING_ID && $action === AuditLogInterface::ACTION_DELETE && $oldValues !== null) {
            $entityId = $this->idResolver->resolveFromValues(
                $entity,
                $oldValues,
                $this->entityManager
            ) ?? self::PENDING_ID;
        }

        $changedFields = ($action === AuditLogInterface::ACTION_UPDATE && $newValues !== null)
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

        $contextData = $resolvedContext['context'];

        $encoded = json_encode($contextData, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            $this->logger?->warning(
                sprintf('Audit context for %s#%s truncated (%d bytes exceeded %d limit).', $entity::class, $entityId, strlen($encoded), self::MAX_CONTEXT_BYTES),
            );
            $contextData = ['_truncated' => true, '_original_size' => strlen($encoded)];
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
            userId: $resolvedContext['userId'],
            username: $resolvedContext['username'],
            ipAddress: $resolvedContext['ipAddress'],
            userAgent: $resolvedContext['userAgent'],
            context: $contextData
        );
    }

    #[Override]
    public function getSensitiveFields(object $entity): array
    {
        return $this->metadataManager->getSensitiveFields($entity::class);
    }
}
