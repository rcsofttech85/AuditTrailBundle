<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function array_flip;
use function implode;
use function sprintf;

final readonly class AuditLogEntityFetcher
{
    /**
     * @param list<string> $ids
     *
     * @return array<string, AuditLog>
     */
    public function fetchIndexedByIds(EntityManagerInterface $entityManager, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $qb = $entityManager->createQueryBuilder()
            ->select('a')
            ->from(AuditLog::class, 'a');
        $conditions = [];

        foreach ($ids as $index => $id) {
            $parameterName = sprintf('id_%d', $index);
            $conditions[] = sprintf('a.id = :%s', $parameterName);
            $qb->setParameter($parameterName, $id, 'uuid');
        }

        /** @var list<AuditLog> $logs */
        $logs = $qb
            ->andWhere('('.implode(' OR ', $conditions).')')
            ->getQuery()
            ->getResult();

        $logsById = [];
        $requestedIds = array_flip($ids);

        foreach ($logs as $log) {
            $logId = $log->id?->toRfc4122();
            if ($logId !== null && isset($requestedIds[$logId])) {
                $logsById[$logId] = $log;
            }
        }

        return $logsById;
    }
}
