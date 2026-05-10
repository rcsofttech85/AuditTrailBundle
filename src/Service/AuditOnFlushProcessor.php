<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\UnitOfWork;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;

use function array_values;
use function spl_object_id;

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
        $scheduledCollectionChanges = $this->mergeScheduledCollectionChanges($unitOfWork);

        $this->entityProcessor->processInsertions($entityManager, $unitOfWork);
        $this->entityProcessor->processUpdates($entityManager, $unitOfWork, $deletedAssociationImpacts);
        $this->entityProcessor->processCollectionUpdates($entityManager, $unitOfWork, $scheduledCollectionChanges);
        $this->entityProcessor->processDeletions($entityManager, $unitOfWork, $deletedAssociationImpacts);
    }

    /**
     * @return list<object>
     */
    private function mergeScheduledCollectionChanges(UnitOfWork $unitOfWork): array
    {
        $merged = [];

        foreach ($unitOfWork->getScheduledCollectionUpdates() as $collection) {
            $merged[spl_object_id($collection)] = $collection;
        }

        foreach ($unitOfWork->getScheduledCollectionDeletions() as $collection) {
            $merged[spl_object_id($collection)] = $collection;
        }

        return array_values($merged);
    }
}
