<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use RuntimeException;

use function sprintf;

final readonly class RevertAuditLogCreator
{
    public function __construct(
        private AuditServiceInterface $auditService,
        private AuditDispatcherInterface $dispatcher,
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
    ): void {
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

        if ($revertLog->entityId === AuditLogInterface::PENDING_ID) {
            $revertLog->entityId = $log->entityId;
        }

        if (!$this->dispatcher->dispatch($revertLog, $entityManager, AuditPhase::PostFlush, null, $entity)) {
            throw new RuntimeException(sprintf('Failed to dispatch revert audit log for %s:%s.', $log->entityClass, $log->entityId));
        }
    }
}
