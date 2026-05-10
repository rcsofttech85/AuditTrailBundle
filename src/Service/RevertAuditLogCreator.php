<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final readonly class RevertAuditLogCreator
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private ValueSerializerInterface $serializer,
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $previousValues
     * @param array<string, mixed> $context
     */
    public function create(
        object $entity,
        AuditLog $log,
        array $changes,
        array $previousValues,
        bool $isDelete,
        array $context,
        EntityManagerInterface $entityManager,
    ): AuditLog {
        $revertContext = [
            ...$context,
            'reverted_log_id' => $log->id?->toRfc4122(),
        ];

        $serializedChanges = [];
        foreach ($changes as $field => $value) {
            $serializedChanges[$field] = $this->serializer->serialize($value);
        }

        $revertLog = $this->auditService->createAuditLog(
            $entity,
            AuditAction::Revert,
            $previousValues !== [] ? $previousValues : null,
            $isDelete ? null : $serializedChanges,
            $revertContext,
            $entityManager,
        );

        if (!$revertLog->hasResolvedEntityId()) {
            $revertLog->entityId = $log->requireEntityId();
        }

        $revertLog->revertedLogId = $log->id?->toRfc4122();

        return $revertLog;
    }
}
