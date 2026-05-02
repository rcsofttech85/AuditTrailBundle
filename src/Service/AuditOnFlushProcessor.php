<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;

final readonly class AuditOnFlushProcessor
{
    public function __construct(
        private EntityProcessorInterface $entityProcessor,
        private AssociationImpactAnalyzer $associationImpactAnalyzer,
    ) {
    }

    public function process(EntityManagerInterface $entityManager): void
    {
        $unitOfWork = $entityManager->getUnitOfWork();
        $deletedAssociationImpacts = $this->associationImpactAnalyzer->buildAggregatedDeletedAssociationImpacts($entityManager, $unitOfWork);

        $this->entityProcessor->processInsertions($entityManager, $unitOfWork);
        $this->entityProcessor->processUpdates($entityManager, $unitOfWork, $deletedAssociationImpacts);
        $this->entityProcessor->processCollectionUpdates($entityManager, $unitOfWork, $unitOfWork->getScheduledCollectionUpdates());
        $this->entityProcessor->processCollectionUpdates($entityManager, $unitOfWork, $unitOfWork->getScheduledCollectionDeletions());
        $this->entityProcessor->processDeletions($entityManager, $unitOfWork, $deletedAssociationImpacts);
    }
}
