<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditQueueManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeIndexBuilder;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\DeferredCollectionDetector;
use Rcsofttech\AuditTrailBundle\Service\EntityAuditDispatchManager;
use Rcsofttech\AuditTrailBundle\Service\EntityCollectionUpdateProcessor;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\StubCollection;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;
use stdClass;

final class EntityCollectionUpdateProcessorTest extends TestCase
{
    private AuditServiceInterface&MockObject $auditService;

    private AuditQueueManagerInterface&MockObject $auditManager;

    protected function setUp(): void
    {
        $this->auditService = $this->createMock(AuditServiceInterface::class);
        $this->auditManager = $this->createMock(AuditQueueManagerInterface::class);
    }

    public function testDispatchesImmediateCollectionUpdates(): void
    {
        $em = self::createMock(EntityManagerInterface::class);
        $uow = self::createMock(UnitOfWork::class);
        $owner = new class {
            /** @var array<int, object> */
            public array $items = [];
        };
        $item1 = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        $item2 = new class {
            public function getId(): int
            {
                return 2;
            }
        };
        $collection = new StubCollection(
            $owner,
            [$item2],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
            [$item1],
        );
        $owner->items = [$item1, $item2];
        $audit = new AuditLog(stdClass::class, '1', AuditAction::Update);
        $metadata = self::createMock(ClassMetadata::class);
        $idResolver = self::createStub(EntityIdResolverInterface::class);

        $uow->expects($this->once())->method('isScheduledForInsert')->with($owner)->willReturn(false);
        $uow->expects($this->once())->method('isScheduledForUpdate')->with($owner)->willReturn(false);
        $em->expects($this->once())->method('getClassMetadata')->with($owner::class)->willReturn($metadata);
        $metadata->expects($this->once())->method('getFieldValue')->with($owner, 'items')->willReturn($owner->items);
        $idResolver->method('resolveFromEntity')->willReturnMap([
            [$item1, $em, '1'],
            [$item2, $em, '2'],
        ]);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditAction::Update, ['items' => ['1', '2']])
            ->willReturn(true);
        $this->auditService->expects($this->once())
            ->method('createAuditLog')
            ->with($owner, AuditAction::Update, ['items' => ['1']], ['items' => ['1', '2']], [], $em)
            ->willReturn($audit);
        $this->auditManager->expects($this->once())
            ->method('schedule')
            ->with($owner, $audit, false);

        $this->createProcessor(idResolver: $idResolver)->process($em, $uow, [$collection]);
    }

    public function testSchedulesPendingPlanForDeferredCollectionUpdates(): void
    {
        $em = self::createMock(EntityManagerInterface::class);
        $uow = self::createMock(UnitOfWork::class);
        $owner = new class {
            /** @var array<int, object> */
            public array $items = [];
        };
        $pendingItem = new stdClass();
        $collection = new StubCollection(
            $owner,
            [$pendingItem],
            [],
            $this->createStubCollectionMapping('items', $owner::class),
            [],
        );
        $owner->items = [$pendingItem];
        $metadata = self::createMock(ClassMetadata::class);
        $idResolver = $this->createMock(EntityIdResolverInterface::class);

        $uow->expects($this->once())->method('isScheduledForInsert')->with($owner)->willReturn(false);
        $uow->expects($this->once())->method('isScheduledForUpdate')->with($owner)->willReturn(false);

        $em->expects($this->once())->method('getClassMetadata')->with($owner::class)->willReturn($metadata);
        $metadata->expects($this->once())->method('getFieldValue')->with($owner, 'items')->willReturn($owner->items);
        $idResolver->expects($this->exactly(2))->method('resolveFromEntity')->with($pendingItem, $em)->willReturn(\Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface::PENDING_ID);
        $this->auditService->expects($this->once())
            ->method('shouldAudit')
            ->with($owner, AuditAction::Update, ['items' => []])
            ->willReturn(true);
        $this->auditService->expects($this->never())->method('createAuditLog');
        $this->auditManager->expects($this->once())
            ->method('schedulePendingAuditPlan')
            ->with(self::callback(static function (PendingAuditPlan $plan) use ($owner): bool {
                return $plan->entity === $owner
                    && $plan->action === AuditAction::Update
                    && $plan->oldValues === ['items' => []]
                    && $plan->newValues === []
                    && $plan->deferredCollectionFields === ['items'];
            }));

        $this->createProcessor(idResolver: $idResolver)->process($em, $uow, [$collection]);
    }

    /**
     * @param class-string $sourceEntity
     */
    private function createStubCollectionMapping(string $fieldName, string $sourceEntity): ManyToManyInverseSideMapping
    {
        return ManyToManyInverseSideMapping::fromMappingArray([
            'fieldName' => $fieldName,
            'sourceEntity' => $sourceEntity,
            'targetEntity' => stdClass::class,
            'mappedBy' => 'owners',
            'isOwningSide' => false,
        ]);
    }

    private function createProcessor(
        ?AuditDispatcherInterface $dispatcher = null,
        ?EntityIdResolverInterface $idResolver = null,
    ): EntityCollectionUpdateProcessor {
        $resolver = $idResolver ?? self::createStub(EntityIdResolverInterface::class);
        $collectionIdExtractor = new CollectionIdExtractor($resolver);
        $collectionChangeResolver = new CollectionChangeResolver(
            $collectionIdExtractor,
            new CollectionChangeIndexBuilder($collectionIdExtractor, new JoinTableCollectionIdLoader($resolver)),
        );

        return new EntityCollectionUpdateProcessor(
            $this->auditService,
            $this->auditManager,
            $collectionChangeResolver,
            new DeferredCollectionDetector($collectionChangeResolver),
            new EntityAuditDispatchManager($dispatcher ?? self::createStub(AuditDispatcherInterface::class), $this->auditManager),
        );
    }
}
