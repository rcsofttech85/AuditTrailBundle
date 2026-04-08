<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use stdClass;

final class EntityDataExtractorTest extends AbstractAuditTestCase
{
    /** @var (EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(EntityManagerInterface&MockObject) */
    private EntityManagerInterface $em;

    /** @var ValueSerializerInterface&\PHPUnit\Framework\MockObject\Stub */
    private ValueSerializerInterface $serializer;

    /** @var MetadataCacheInterface&\PHPUnit\Framework\MockObject\Stub */
    private MetadataCacheInterface $metadataCache;

    /** @var (LoggerInterface&\PHPUnit\Framework\MockObject\Stub)|(LoggerInterface&MockObject) */
    private LoggerInterface $logger;

    private EntityDataExtractor $extractor;

    protected function setUp(): void
    {
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->serializer = self::createStub(ValueSerializerInterface::class);
        $this->metadataCache = self::createStub(MetadataCacheInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);

        $this->extractor = new EntityDataExtractor(
            $this->em,
            $this->serializer,
            $this->metadataCache,
            $this->logger
        );
    }

    public function testExtractSuccess(): void
    {
        $entity = new stdClass();
        $meta = self::createStub(ClassMetadata::class);

        $this->em->method('getClassMetadata')->willReturn($meta);
        $this->metadataCache->method('getAuditableAttribute')->willReturn(null);
        $this->metadataCache->method('getSensitiveFields')->willReturn(['password' => '***']);

        $meta->method('getFieldNames')->willReturn(['name', 'password', 'global_ignored']);
        $meta->method('getAssociationNames')->willReturn(['profile']);

        $meta->method('getFieldValue')->willReturnMap([
            [$entity, 'name', 'John'],
            [$entity, 'password', 'secret'],
            [$entity, 'profile', new stdClass()],
        ]);

        $this->serializer->method('serialize')->willReturnMap([
            ['John', 'John'],
            ['secret', 'secret'],
        ]);
        $this->serializer->method('serializeAssociation')->willReturn(['id' => 1]);

        $data = $this->extractor->extract($entity, ['global_ignored']);

        self::assertEquals([
            'name' => 'John',
            'password' => '***', // Masked
            'profile' => ['id' => 1],
        ], $data);

        self::assertArrayNotHasKey('global_ignored', $data);
    }

    public function testExtractWithIgnoredProperties(): void
    {
        $entity = new stdClass();
        $meta = self::createStub(ClassMetadata::class);

        $this->em->method('getClassMetadata')->willReturn($meta);
        $this->metadataCache->method('getAuditableAttribute')
            ->willReturn(new Auditable(ignoredProperties: ['attr_ignored']));
        $this->metadataCache->method('getSensitiveFields')->willReturn([]);

        $meta->method('getFieldNames')->willReturn(['field1', 'attr_ignored', 'param_ignored']);
        $meta->method('getAssociationNames')->willReturn([]);

        $meta->method('getFieldValue')->willReturn('value');
        $this->serializer->method('serialize')->willReturn('value');

        $data = $this->extractor->extract($entity, ['attr_ignored', 'param_ignored']);

        self::assertSame(['field1' => 'value'], $data);
        self::assertArrayNotHasKey('attr_ignored', $data);
        self::assertArrayNotHasKey('param_ignored', $data);
    }

    public function testExtractPreservesNullableFields(): void
    {
        $entity = new stdClass();
        $meta = self::createStub(ClassMetadata::class);
        $serializer = self::createMock(ValueSerializerInterface::class);
        $this->extractor = new EntityDataExtractor(
            $this->em,
            $serializer,
            $this->metadataCache,
            $this->logger
        );

        $this->em->method('getClassMetadata')->willReturn($meta);
        $this->metadataCache->method('getSensitiveFields')->willReturn([]);

        $meta->method('getFieldNames')->willReturn(['title']);
        $meta->method('getAssociationNames')->willReturn([]);
        $meta->method('getFieldValue')->willReturnMap([
            [$entity, 'title', null],
        ]);

        $serializer->expects($this->once())
            ->method('serialize')
            ->with(null)
            ->willReturn(null);

        self::assertSame(['title' => null], $this->extractor->extract($entity));
    }

    public function testExtractException(): void
    {
        $entity = new stdClass();
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->logger = self::createMock(LoggerInterface::class);
        $this->extractor = new EntityDataExtractor(
            $this->em,
            $this->serializer,
            $this->metadataCache,
            $this->logger
        );

        $this->em->method('getClassMetadata')->willThrowException(new Exception('Error'));

        $this->logger->expects($this->once())->method('error');

        $data = $this->extractor->extract($entity);

        self::assertTrue($data['_extraction_failed']);
        self::assertSame('Error', $data['_error']);
    }

    public function testGetFieldValueSafelyException(): void
    {
        $entity = new stdClass();
        $meta = self::createStub(ClassMetadata::class);

        $this->em->method('getClassMetadata')->willReturn($meta);
        $this->metadataCache->method('getAuditableAttribute')->willReturn(null);
        $this->metadataCache->method('getSensitiveFields')->willReturn([]);

        $meta->method('getFieldNames')->willReturn(['broken_field']);
        $meta->method('getAssociationNames')->willReturn([]);

        $meta->method('getFieldValue')->willThrowException(new Exception());

        $data = $this->extractor->extract($entity);

        self::assertEmpty($data);
    }
}
