<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Contract\RevertActionHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\SoftDeleteHandlerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Throwable;

use function count;
use function sprintf;

final readonly class AuditReverter implements AuditReverterInterface
{
    /**
     * @param iterable<RevertActionHandlerInterface> $actionHandlers
     */
    public function __construct(
        private EntityManagerInterface $em,
        private ValidatorInterface $validator,
        private RevertValueDenormalizer $denormalizer,
        private SoftDeleteHandlerInterface $softDeleteHandler,
        private ScheduledAuditManagerInterface $auditManager,
        private RevertGuard $revertGuard,
        private RevertEntityStateApplier $revertStateApplier,
        private RevertAuditLogCreator $revertAuditLogCreator,
        #[AutowireIterator('audit_trail.revert_action_handler')]
        private iterable $actionHandlers,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function revert(
        AuditLog $log,
        bool $dryRun = false,
        bool $force = false,
        array $context = [],
        bool $silenceSubscriber = true,
        bool $verifySignature = true,
    ): array {
        $this->revertGuard->assertRevertable($log, $verifySignature);

        if ($silenceSubscriber) {
            $this->auditManager->disable();
        }

        try {
            $entity = $this->findEntity($log->entityClass, $log->entityId);
            if ($entity === null) {
                throw new RuntimeException(sprintf('Entity %s:%s not found.', $log->entityClass, $log->entityId));
            }

            $revertData = $this->determineChanges($log, $entity, $force, $dryRun);
            $changes = $revertData->changes;
            if ($dryRun) {
                return $changes;
            }

            $this->applyAndPersist($entity, $log, $revertData, $context);

            return $changes;
        } finally {
            if ($silenceSubscriber) {
                $this->auditManager->enable();
            }
        }
    }

    private function determineChanges(AuditLog $log, object $entity, bool $force, bool $dryRun): RevertPlan
    {
        foreach ($this->actionHandlers as $handler) {
            if ($handler->supports($log->action)) {
                return $handler->buildPlan($log, $entity, $force, $dryRun);
            }
        }

        throw new RuntimeException(sprintf('Reverting action "%s" is not supported.', $log->action->value));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function applyAndPersist(
        object $entity,
        AuditLog $log,
        RevertPlan $revertPlan,
        array $context,
    ): void {
        $changes = $revertPlan->changes;
        $isDelete = $revertPlan->isDeleteAction();

        $this->em->wrapInTransaction(function () use ($entity, $isDelete, $log, $changes, $context, $revertPlan) {
            try {
                if ($isDelete) {
                    $this->em->remove($entity);
                } else {
                    $this->revertStateApplier->apply($entity, $revertPlan);
                    $this->validateEntity($entity);
                }

                $this->revertAuditLogCreator->create(
                    $entity,
                    $log,
                    $changes,
                    $revertPlan->previousValues,
                    $isDelete,
                    $context,
                    $this->em,
                );
            } catch (Throwable $e) {
                if (!$isDelete && $this->em->isOpen()) {
                    $this->em->refresh($entity);
                }

                throw $e;
            }
        });
    }

    private function validateEntity(object $entity): void
    {
        $errors = $this->validator->validate($entity);
        if (0 !== count($errors)) {
            throw new RuntimeException((string) $errors);
        }
    }

    private function findEntity(string $class, string $id): ?object
    {
        if (!class_exists($class)) {
            return null;
        }

        $disabledFilters = $this->softDeleteHandler->disableSoftDeleteFilters();

        try {
            /** @var class-string<object> $class */
            return $this->em->find($class, $this->denormalizer->normalizeEntityIdentifier($class, $id));
        } finally {
            $this->softDeleteHandler->enableFilters($disabledFilters);
        }
    }
}
