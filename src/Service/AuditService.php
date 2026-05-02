<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityDataExtractorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class AuditService implements AuditServiceInterface
{
    /**
     * @param iterable<AuditVoterInterface> $voters
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private EntityDataExtractorInterface $dataExtractor,
        private AuditMetadataManagerInterface $metadataManager,
        private AuditLogFactory $auditLogFactory,
        #[AutowireIterator('audit_trail.voter')]
        private iterable $voters = [],
    ) {
    }

    #[Override]
    public function shouldAudit(
        object $entity,
        AuditAction $action = AuditAction::Create,
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
    public function passesVoters(object $entity, AuditAction $action, array $changeSet = []): bool
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
        AuditAction $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = [],
        ?EntityManagerInterface $entityManager = null,
    ): AuditLog {
        return $this->auditLogFactory->create(
            $entity,
            $action,
            $oldValues,
            $newValues,
            $context,
            $entityManager ?? $this->entityManager,
        );
    }

    #[Override]
    public function getSensitiveFields(object $entity): array
    {
        return $this->metadataManager->getSensitiveFields($entity::class);
    }
}
