<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToManyInverseSideMapping;
use Doctrine\ORM\UnitOfWork;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;

final class AssociationImpactAnalyzerTest extends TestCase
{
    public function testBuildAggregatedDeletedAssociationImpactsRemovesDeletedEntityId(): void
    {
        $deletedTag = new TestDeletedAssociationItem(1);
        $otherTag = new TestDeletedAssociationItem(2);
        $post = new class([$deletedTag, $otherTag]) {
            /**
             * @param array<int, object> $tags
             */
            public function __construct(
                public array $tags,
            ) {
            }
        };
        $deletedTag->posts = [$post];

        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturnCallback(static function (object $entity): string {
            if ($entity instanceof TestDeletedAssociationItem) {
                return (string) $entity->id;
            }

            throw new InvalidArgumentException('Unexpected entity type '.$entity::class);
        });

        $deletedMetadata = self::createMock(ClassMetadata::class);
        $deletedMetadata->method('getAssociationNames')->willReturn(['posts']);
        $deletedMetadata->method('isCollectionValuedAssociation')->with('posts')->willReturn(true);
        $deletedMetadata->expects($this->once())
            ->method('getAssociationMapping')
            ->with('posts')
            ->willReturn(ManyToManyInverseSideMapping::fromMappingArray([
                'fieldName' => 'posts',
                'sourceEntity' => $deletedTag::class,
                'targetEntity' => $post::class,
                'mappedBy' => 'tags',
                'isOwningSide' => false,
            ]));
        $deletedMetadata->expects($this->once())
            ->method('getFieldValue')
            ->with($deletedTag, 'posts')
            ->willReturn([$post]);

        $postMetadata = self::createMock(ClassMetadata::class);
        $postMetadata->expects($this->once())
            ->method('getFieldValue')
            ->with($post, 'tags')
            ->willReturn([$deletedTag, $otherTag]);

        $em = self::createStub(EntityManagerInterface::class);
        $em->method('getClassMetadata')->willReturnCallback(static function (string $class) use ($deletedTag, $post, $deletedMetadata, $postMetadata): ClassMetadata {
            if ($class === $deletedTag::class) {
                return $deletedMetadata;
            }

            if ($class === $post::class) {
                return $postMetadata;
            }

            throw new InvalidArgumentException('Unexpected metadata lookup for '.$class);
        });

        $uow = self::createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityDeletions')->willReturn([$deletedTag]);

        $analyzer = new AssociationImpactAnalyzer(new CollectionIdExtractor($idResolver), new CollectionTransitionMerger());

        self::assertSame([[
            'entity' => $post,
            'field' => 'tags',
            'old' => ['1', '2'],
            'new' => ['2'],
        ]], $analyzer->buildAggregatedDeletedAssociationImpacts($em, $uow));
    }
}

final class TestDeletedAssociationItem
{
    /** @var array<int, object> */
    public array $posts = [];

    public function __construct(
        public int $id,
    ) {
    }
}
