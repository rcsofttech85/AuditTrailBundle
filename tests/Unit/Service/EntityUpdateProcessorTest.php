<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeIndexBuilder;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\DeferredCollectionDetector;
use Rcsofttech\AuditTrailBundle\Service\DeletedAssociationImpactResolver;
use Rcsofttech\AuditTrailBundle\Service\EntityAuditDispatchManager;
use Rcsofttech\AuditTrailBundle\Service\EntityUpdateProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityUpdateTransitionResolver;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\ValueObject\AssociationImpact;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use stdClass;

final class EntityUpdateProcessorTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private AuditQueueManagerInterface&MockObject $auditManager;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->auditManager = $this->createMock(AuditQueueManagerInterface::class);
    }

    public function testDispatchesImmediateUpdatesWithoutDeferredCollections(): void
    {
        $em = self::createStub(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new stdClass();
        $deletedAssociationImpacts = [new AssociationImpact($entity, 'items', ['1'], [])];
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $changeProcessor = self::createStub(ChangeProcessorInterface::class);

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['title' => ['old', 'new']]);
        $changeProcessor->method('extractChanges')->willReturn([['title' => 'old'], ['title' => 'new']]);
        $changeProcessor->method('determineUpdateAction')->willReturn(AuditAction::Update);

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Update, ['title' => 'new', 'items' => []])
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($entity, AuditAction::Update, ['title' => 'old', 'items' => ['1']], ['title' => 'new', 'items' => []], [], $em)
            ->willReturn($audit);
        $this->auditManager->expects($this->once())
            ->method('schedule')
            ->with($entity, $audit, false);
        $this->auditManager->expects($this->never())->method('schedulePendingAuditPlan');

        $this->createProcessor(changeProcessor: $changeProcessor)->process($em, $uow, $deletedAssociationImpacts);
    }

    public function testSchedulesPendingPlanForDeferredCollectionFields(): void
    {
        $em = self::createMock(EntityManagerInterface::class);
        $uow = self::createStub(UnitOfWork::class);
        $entity = new class {
            /** @var array<int, object> */
            public array $tags = [];
        };
        $pendingTag = new stdClass();
        $deletedAssociationImpacts = [new AssociationImpact($entity, 'items', ['1'], [])];
        $metadata = self::createStub(ClassMetadata::class);
        $changeProcessor = self::createStub(ChangeProcessorInterface::class);

        $entity->tags = [$pendingTag];

        $uow->method('getScheduledEntityUpdates')->willReturn([$entity]);
        $uow->method('getScheduledCollectionUpdates')->willReturn([]);
        $uow->method('getScheduledCollectionDeletions')->willReturn([]);
        $uow->method('getEntityChangeSet')->willReturn(['title' => ['old', 'new'], 'tags' => [[], [$pendingTag]]]);
        $changeProcessor->method('extractChanges')->willReturn([
            ['title' => 'old', 'tags' => []],
            ['title' => 'new', 'tags' => [$pendingTag]],
        ]);
        $changeProcessor->method('determineUpdateAction')->willReturn(AuditAction::Update);
        $em->expects($this->exactly(3))->method('getClassMetadata')->with($entity::class)->willReturn($metadata);
        $metadata->method('getFieldValue')->willReturnCallback(
            static fn (object $currentEntity, string $field): array => $field === 'tags' ? [$pendingTag] : []
        );
        $idResolver = $this->createMock(EntityIdResolverInterface::class);
        $idResolver->expects($this->once())->method('resolveFromEntity')->with($pendingTag, $em)->willReturn(null);

        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($entity, AuditAction::Update, ['title' => 'new', 'tags' => [$pendingTag], 'items' => []])
            ->willReturn(true);
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->auditManager->expects($this->once())
            ->method('schedulePendingAuditPlan')
            ->with(self::callback(static function (PendingAuditPlan $plan) use ($entity): bool {
                return $plan->entity === $entity
                    && $plan->action === AuditAction::Update
                    && $plan->oldValues === ['title' => 'old', 'tags' => [], 'items' => ['1']]
                    && $plan->newValues === ['title' => 'new', 'items' => []]
                    && $plan->deferredCollectionFields === ['tags']
                    && !$plan->refreshEntityData;
            }));

        $this->createProcessor(changeProcessor: $changeProcessor, idResolver: $idResolver)->process($em, $uow, $deletedAssociationImpacts);
    }

    private function createProcessor(
        ?ChangeProcessorInterface $changeProcessor = null,
        ?AuditDispatcherInterface $dispatcher = null,
        ?EntityIdResolverInterface $idResolver = null,
    ): EntityUpdateProcessor {
        $resolver = $idResolver ?? self::createStub(EntityIdResolverInterface::class);
        $collectionIdExtractor = new CollectionIdExtractor($resolver);
        $collectionChangeResolver = new CollectionChangeResolver(
            $collectionIdExtractor,
            new CollectionChangeIndexBuilder($collectionIdExtractor, new JoinTableCollectionIdLoader($resolver)),
            new JoinTableCollectionIdLoader($resolver),
        );
        $deletedAssociationImpactResolver = new DeletedAssociationImpactResolver();
        $collectionTransitionMerger = new CollectionTransitionMerger();

        return new EntityUpdateProcessor(
            $this->auditService,
            $this->auditManager,
            new AssociationImpactAnalyzer($collectionIdExtractor, new CollectionTransitionMerger()),
            new DeferredCollectionDetector($collectionChangeResolver),
            new EntityUpdateTransitionResolver(
                $changeProcessor ?? self::createStub(ChangeProcessorInterface::class),
                $deletedAssociationImpactResolver,
                $collectionChangeResolver,
                $collectionTransitionMerger,
            ),
            new EntityAuditDispatchManager($dispatcher ?? self::createStub(AuditDispatcherInterface::class), $this->auditManager),
        );
    }
}
