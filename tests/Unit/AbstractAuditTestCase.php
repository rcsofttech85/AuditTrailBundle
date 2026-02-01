<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\ChangeProcessor;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\EntityProcessor;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use Rcsofttech\AuditTrailBundle\Service\ScheduledAuditManager;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use Symfony\Component\Clock\MockClock;

abstract class AbstractAuditTestCase extends TestCase
{
    protected function createAuditService(
        EntityManagerInterface $em,
        Stub&TransactionIdGenerator $transactionIdGenerator,
        MetadataCache $metadataCache = new MetadataCache(),
        ValueSerializer $serializer = new ValueSerializer(null),
    ): AuditService {
        $extractor = new EntityDataExtractor($em, $serializer, $metadataCache);

        return new AuditService(
            $em,
            self::createStub(UserResolverInterface::class),
            new MockClock(),
            $transactionIdGenerator,
            $extractor,
            $metadataCache,
            [],
            []
        );
    }

    protected function createAuditDispatcher(
        MockObject&AuditTransportInterface $transport,
        (Stub&AuditIntegrityServiceInterface)|null $integrityService = null,
    ): AuditDispatcher {
        return new AuditDispatcher(
            $transport,
            $integrityService ?? self::createStub(AuditIntegrityServiceInterface::class),
            null
        );
    }

    protected function createEntityProcessor(
        AuditService $auditService,
        ChangeProcessor $changeProcessor,
        AuditDispatcher $dispatcher,
        ScheduledAuditManager $auditManager,
        bool $deferTransportUntilCommit = false,
    ): EntityProcessor {
        return new EntityProcessor(
            $auditService,
            $changeProcessor,
            $dispatcher,
            $auditManager,
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
        $metadata->method('getReflectionClass')->willReturn(new \ReflectionClass($entity));
        $metadata->method('getReflectionProperty')->willReturnCallback(
            static fn (string $p): \ReflectionProperty => new \ReflectionProperty($entity, $p)
        );

        return $metadata;
    }
}
