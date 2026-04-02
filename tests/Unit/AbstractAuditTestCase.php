<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\PersistentCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\ChangeProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Service\AssociationImpactAnalyzer;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditLogContextProcessor;
use Rcsofttech\AuditTrailBundle\Service\AuditLogWriter;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\CollectionChangeResolver;
use Rcsofttech\AuditTrailBundle\Service\CollectionIdExtractor;
use Rcsofttech\AuditTrailBundle\Service\CollectionTransitionMerger;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\JoinTableCollectionIdLoader;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Clock\MockClock;

abstract class AbstractAuditTestCase extends TestCase
{
    protected function createAuditService(
        EntityManagerInterface $em,
        TransactionIdGenerator $transactionIdGenerator,
        MetadataCacheInterface $metadataCache = new MetadataCache(),
        ?ValueSerializer $serializer = null,
    ): AuditServiceInterface {
        $serializer ??= new ValueSerializer($this->createEntityIdResolverStub());
        $extractor = new EntityDataExtractor($em, $serializer, $metadataCache);
        $metadataManager = self::createStub(AuditMetadataManagerInterface::class);
        $contextResolver = self::createStub(ContextResolverInterface::class);
        $contextResolver->method('resolve')->willReturn([
            'userId' => null,
            'username' => null,
            'ipAddress' => null,
            'userAgent' => null,
            'context' => [],
        ]);
        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolveFromEntity')->willReturn('1');

        return new AuditService(
            $em,
            new MockClock(),
            $transactionIdGenerator,
            $extractor,
            $metadataManager,
            $contextResolver,
            $idResolver,
            new ContextSanitizer(),
            null,
            'UTC',
            [],
        );
    }

    protected function createEntityIdResolverStub(string|int|null $resolvedId = '1'): EntityIdResolverInterface
    {
        $resolver = self::createStub(EntityIdResolverInterface::class);
        $resolver->method('resolveFromEntity')->willReturn($resolvedId);

        return $resolver;
    }

    protected function createAuditDispatcher(
        MockObject&AuditTransportInterface $transport,
        (Stub&AuditIntegrityServiceInterface)|null $integrityService = null,
    ): AuditDispatcherInterface {
        return new AuditDispatcher(
            $transport,
            new AuditLogContextProcessor(new ContextSanitizer()),
            new AuditLogWriter(),
            null, // eventDispatcher
            $integrityService ?? self::createStub(AuditIntegrityServiceInterface::class),
        );
    }

    protected function createEntityProcessor(
        AuditServiceInterface $auditService,
        ChangeProcessorInterface $changeProcessor,
        AuditDispatcherInterface $dispatcher,
        ScheduledAuditManagerInterface $auditManager,
        bool $deferTransportUntilCommit = false,
    ): EntityProcessorInterface {
        $idResolver = self::createStub(EntityIdResolverInterface::class);

        return new EntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            new AssociationImpactAnalyzer(new CollectionIdExtractor($idResolver), new CollectionTransitionMerger()),
            new CollectionChangeResolver(new CollectionIdExtractor($idResolver), new JoinTableCollectionIdLoader($idResolver)),
            new CollectionTransitionMerger(),
            $deferTransportUntilCommit,
            false,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T>      $className
     * @param array<string, mixed> $idValues
     *
     * @return ClassMetadata<T>&Stub
     */
    protected function createEntityMetadataStub(
        string $className,
        object $entity,
        array $idValues = ['id' => 1],
    ): ClassMetadata {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn($idValues);
        $metadata->method('getName')->willReturn($className);
        $metadata->method('getFieldNames')->willReturn(array_keys($idValues));
        $metadata->method('getAssociationNames')->willReturn([]);
        $metadata->method('getReflectionClass')->willReturn(new ReflectionClass($entity));
        $metadata->method('getReflectionProperty')->willReturnCallback(
            static fn (string $p): ReflectionProperty => new ReflectionProperty($entity, $p)
        );

        return $metadata;
    }

    /**
     * @param ClassMetadata<object>               $metadata
     * @param Collection<int|string, object>|null $inner
     *
     * @return PersistentCollection<int|string, object>
     */
    protected function createUninitializedCollection(
        EntityManagerInterface $em,
        ClassMetadata $metadata,
        ?Collection $inner = null
    ): PersistentCollection {
        /** @var Collection<int|string, object>&Selectable<int|string, object> $actualInner */
        $actualInner = $inner ?? new ArrayCollection();

        $coll = new PersistentCollection($em, $metadata, $actualInner);
        $coll->setInitialized(false);

        return $coll;
    }
}
