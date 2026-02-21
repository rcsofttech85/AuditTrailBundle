<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
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
use Rcsofttech\AuditTrailBundle\Contract\ScheduledAuditManagerInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
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
        Stub&TransactionIdGenerator $transactionIdGenerator,
        MetadataCache $metadataCache = new MetadataCache(),
        ValueSerializer $serializer = new ValueSerializer(null),
    ): AuditServiceInterface {
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
            $idResolver
        );
    }

    protected function createAuditDispatcher(
        MockObject&AuditTransportInterface $transport,
        (Stub&AuditIntegrityServiceInterface)|null $integrityService = null,
    ): AuditDispatcherInterface {
        return new AuditDispatcher(
            $transport,
            null, // eventDispatcher
            $integrityService ?? self::createStub(AuditIntegrityServiceInterface::class)
        );
    }

    protected function createEntityProcessor(
        AuditServiceInterface $auditService,
        ChangeProcessorInterface $changeProcessor,
        AuditDispatcherInterface $dispatcher,
        ScheduledAuditManagerInterface $auditManager,
        bool $deferTransportUntilCommit = false,
    ): EntityProcessorInterface {
        return new EntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
            self::createStub(EntityIdResolverInterface::class),
            $deferTransportUntilCommit
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
}
