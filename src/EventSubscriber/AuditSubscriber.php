<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityIdResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function sprintf;

#[AsDoctrineListener(event: Events::onFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postFlush, priority: 1000)]
#[AsDoctrineListener(event: Events::postLoad)]
#[AsDoctrineListener(event: Events::onClear)]
final class AuditSubscriber implements ResetInterface
{
    private const int BATCH_FLUSH_THRESHOLD = 500;

    private bool $isFlushing = false;

    private int $recursionDepth = 0;

    /** @var array<string, bool> */
    private array $auditedEntities = [];

    public function __construct(
        private readonly AuditService $auditService,
        private readonly ChangeProcessor $changeProcessor,
        private readonly AuditDispatcher $dispatcher,
        private readonly ScheduledAuditManager $auditManager,
        private readonly EntityProcessor $entityProcessor,
        private readonly TransactionIdGenerator $transactionIdGenerator,
        private readonly UserResolverInterface $userResolver,
        private readonly ?CacheItemPoolInterface $cache = null,
        private readonly bool $enableHardDelete = true,
        private readonly ?LoggerInterface $logger = null,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if (!$this->enabled || $this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $uow = $em->getUnitOfWork();
            $uow->computeChangeSets();

            $this->handleBatchFlushIfNeeded($em);
            $this->entityProcessor->processInsertions($em, $uow);
            $this->entityProcessor->processUpdates($em, $uow);
            $this->entityProcessor->processCollectionUpdates($em, $uow, $uow->getScheduledCollectionUpdates());
            $this->entityProcessor->processDeletions($em, $uow);
        } finally {
            --$this->recursionDepth;
        }
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        if (!$this->enabled) {
            return;
        }

        $entity = $args->getObject();
        $class = $entity::class;
        $id = EntityIdResolver::resolveFromEntity($entity, $args->getObjectManager());

        if ($id === EntityIdResolver::PENDING_ID) {
            return;
        }

        // Check for Shadow Read Access
        $accessAttr = $this->auditService->getAccessAttribute($class);
        if ($accessAttr === null) {
            return;
        }

        // Request-level Deduplication
        $requestKey = sprintf('%s:%s', $class, $id);
        if (isset($this->auditedEntities[$requestKey])) {
            return;
        }

        // Persistent Cooldown
        if ($accessAttr->cooldown > 0 && $this->cache !== null) {
            $userId = $this->userResolver->getUserId() ?? 'anonymous';
            $cacheKey = sprintf('audit_access.%s.%s.%s', $userId, str_replace('\\', '_', $class), $id);
            $item = $this->cache->getItem($cacheKey);

            if ($item->isHit()) {
                $this->auditedEntities[$requestKey] = true;

                return;
            }

            $item->set(true);
            $item->expiresAfter($accessAttr->cooldown);
            $this->cache->save($item);
        }

        $this->auditedEntities[$requestKey] = true;

        try {
            // Determine context from attribute
            $context = [];
            if ($accessAttr->message !== null) {
                $context['message'] = $accessAttr->message;
            }
            $context['level'] = $accessAttr->level;
            $audit = $this->auditService->createAuditLog(
                $entity,
                AuditLogInterface::ACTION_ACCESS,
                null,
                null,
                $context
            );

            $this->dispatcher->dispatch($audit, $args->getObjectManager(), 'post_load');
        } catch (Throwable $e) {
            $this->logger?->error('Failed to log audit access', [
                'entity' => $class,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if (!$this->enabled || $this->isFlushing || $this->recursionDepth > 0) {
            return;
        }

        ++$this->recursionDepth;

        try {
            $em = $args->getObjectManager();
            $hasNewAudits = $this->processPendingDeletions($em);
            $hasNewAudits = $this->processScheduledAudits($em) || $hasNewAudits;

            $this->auditManager->clear();
            $this->transactionIdGenerator->reset();
            $this->flushNewAuditsIfNeeded($em, $hasNewAudits);
        } finally {
            --$this->recursionDepth;
        }
    }

    private function processPendingDeletions(EntityManagerInterface $em): bool
    {
        $hasNewAudits = false;
        foreach ($this->auditManager->getPendingDeletions() as $pending) {
            $action = $this->changeProcessor->determineDeletionAction($em, $pending['entity'], $this->enableHardDelete);
            if ($action === null) {
                continue;
            }

            $newData = $action === AuditLogInterface::ACTION_SOFT_DELETE
                ? $this->auditService->getEntityData($pending['entity'])
                : null;
            $audit = $this->auditService->createAuditLog($pending['entity'], $action, $pending['data'], $newData);

            $this->dispatcher->dispatch($audit, $em, 'post_flush');

            if ($em->contains($audit)) {
                $hasNewAudits = true;
            }
        }

        return $hasNewAudits;
    }

    private function processScheduledAudits(EntityManagerInterface $em): bool
    {
        $hasNewAudits = false;
        foreach ($this->auditManager->getScheduledAudits() as $scheduled) {
            if ($scheduled['is_insert']) {
                $id = EntityIdResolver::resolveFromEntity($scheduled['entity'], $em);
                if ($id !== EntityIdResolver::PENDING_ID) {
                    $scheduled['audit']->setEntityId($id);
                }
            }

            $this->dispatcher->dispatch($scheduled['audit'], $em, 'post_flush');

            if ($em->contains($scheduled['audit'])) {
                $hasNewAudits = true;
            }
        }

        return $hasNewAudits;
    }

    public function onClear(): void
    {
        $this->reset();
    }

    public function reset(): void
    {
        $this->auditManager->clear();
        $this->transactionIdGenerator->reset();
        $this->isFlushing = false;
        $this->recursionDepth = 0;
        $this->auditedEntities = [];
    }

    private function handleBatchFlushIfNeeded(EntityManagerInterface $em): void
    {
        if ($this->auditManager->countScheduled() < self::BATCH_FLUSH_THRESHOLD) {
            return;
        }

        $this->isFlushing = true;
        try {
            foreach ($this->auditManager->getScheduledAudits() as $scheduled) {
                $this->dispatcher->dispatch($scheduled['audit'], $em, 'batch_flush');
            }
            $this->auditManager->clear();
        } finally {
            $this->isFlushing = false;
        }
    }

    private function flushNewAuditsIfNeeded(EntityManagerInterface $em, bool $hasNewAudits): void
    {
        if (!$hasNewAudits) {
            return;
        }

        $this->isFlushing = true;
        try {
            $em->flush();
        } catch (Throwable $e) {
            $this->logger?->critical('Failed to flush audits', ['exception' => $e->getMessage()]);
        } finally {
            $this->isFlushing = false;
        }
    }
}
