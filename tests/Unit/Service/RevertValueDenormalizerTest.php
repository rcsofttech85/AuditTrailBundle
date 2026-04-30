<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTime;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;

#[AllowMockObjectsWithoutExpectations]
final class RevertValueDenormalizerTest extends TestCase
{
    /** @var (EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(EntityManagerInterface&MockObject) */
    private EntityManagerInterface $em;

    private RevertValueDenormalizer $denormalizer;

    protected function setUp(): void
    {
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
    }

    public function testDenormalizeNull(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        self::assertNull($this->denormalizer->denormalize($metadata, 'field', null));
    }

    public function testDenormalizeSimpleField(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('string');

        self::assertSame('value', $this->denormalizer->denormalize($metadata, 'field', 'value'));
    }

    public function testDenormalizeDateTimeImmutableFromString(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime_immutable');

        $result = $this->denormalizer->denormalize($metadata, 'field', '2023-01-01 12:00:00');
        self::assertInstanceOf(DateTimeImmutable::class, $result);
        self::assertSame('2023-01-01 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDenormalizeDateTimeMutableFromArray(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime');

        $value = ['date' => '2023-01-01 12:00:00.000000', 'timezone' => 'UTC'];
        $result = $this->denormalizer->denormalize($metadata, 'field', $value);

        self::assertInstanceOf(DateTime::class, $result);
        self::assertSame('2023-01-01 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDenormalizeDateTimeObject(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime');

        $date = new DateTime();
        $result = $this->denormalizer->denormalize($metadata, 'field', $date);
        self::assertSame($date, $result);
    }

    public function testDenormalizeAssociationObject(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->willReturn(stdClass::class);

        $entity = new stdClass();
        $result = $this->denormalizer->denormalize($metadata, 'field', $entity);
        self::assertSame($entity, $result);
    }

    public function testDenormalizeAssociationId(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->willReturn(stdClass::class);

        $entity = new stdClass();
        $this->em = self::createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
        $this->em->expects($this->once())->method('find')->with(stdClass::class, 1)->willReturn($entity);

        $result = $this->denormalizer->denormalize($metadata, 'field', 1);
        self::assertSame($entity, $result);
    }

    public function testDenormalizeCollectionAssociationIds(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->willReturn(true);
        $metadata->method('isCollectionValuedAssociation')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->willReturn(stdClass::class);

        $targetMetadata = self::createMock(ClassMetadata::class);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $targetMetadata->expects($this->exactly(2))
            ->method('getTypeOfField')
            ->with('id')
            ->willReturn('string');

        $first = new stdClass();
        $second = new stdClass();

        $this->em = self::createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
        $this->em->expects($this->exactly(2))
            ->method('getClassMetadata')
            ->with(stdClass::class)
            ->willReturn($targetMetadata);
        $this->em->expects($this->exactly(2))
            ->method('getReference')
            ->willReturnMap([
                [stdClass::class, 'uuid-1', $first],
                [stdClass::class, 'uuid-2', $second],
            ]);

        $result = $this->denormalizer->denormalize($metadata, 'field', ['uuid-1', 'uuid-2'], true);

        self::assertInstanceOf(ArrayCollection::class, $result);
        self::assertSame([$first, $second], $result->toArray());
    }

    public function testDenormalizeAssociationUuidIdentifierForDryRun(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->willReturn(stdClass::class);

        $targetMetadata = self::createMock(ClassMetadata::class);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $targetMetadata->expects($this->once())
            ->method('getTypeOfField')
            ->with('id')
            ->willReturn('uuid');

        $entity = new stdClass();
        $uuid = Uuid::v7()->toRfc4122();

        $this->em = self::createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
        $this->em->expects($this->once())
            ->method('getClassMetadata')
            ->with(stdClass::class)
            ->willReturn($targetMetadata);
        $this->em->expects($this->once())
            ->method('getReference')
            ->with(stdClass::class, self::callback(static fn (mixed $value): bool => $value instanceof Uuid && $uuid === (string) $value))
            ->willReturn($entity);

        $result = $this->denormalizer->denormalize($metadata, 'field', $uuid, true);

        self::assertSame($entity, $result);
    }

    public function testNormalizeEntityIdentifierSupportsUlid(): void
    {
        $targetMetadata = self::createMock(ClassMetadata::class);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['id']);
        $targetMetadata->expects($this->once())
            ->method('getTypeOfField')
            ->with('id')
            ->willReturn('ulid');

        $ulid = (string) new Ulid();

        $this->em = self::createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
        $this->em->expects($this->once())
            ->method('getClassMetadata')
            ->with(stdClass::class)
            ->willReturn($targetMetadata);

        $normalized = $this->denormalizer->normalizeEntityIdentifier(stdClass::class, $ulid);

        self::assertInstanceOf(Ulid::class, $normalized);
        self::assertSame($ulid, (string) $normalized);
    }

    public function testNormalizeEntityIdentifierFallsBackSafelyForCompositeAssociationIds(): void
    {
        $targetMetadata = self::createStub(ClassMetadata::class);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['tenant', 'code']);
        $targetMetadata->method('getTypeOfField')->willThrowException(new RuntimeException('Association id field'));

        $identifier = ['tenant' => 'acme', 'code' => 'ABC123'];

        $this->em = self::createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
        $this->em->expects($this->once())
            ->method('getClassMetadata')
            ->with(stdClass::class)
            ->willReturn($targetMetadata);

        self::assertSame($identifier, $this->denormalizer->normalizeEntityIdentifier(stdClass::class, $identifier));
    }

    public function testNormalizeEntityIdentifierDecodesCompositeJsonIdentifiers(): void
    {
        $targetMetadata = self::createStub(ClassMetadata::class);
        $targetMetadata->method('getIdentifierFieldNames')->willReturn(['tenant', 'code']);
        $targetMetadata->method('getTypeOfField')->willReturnMap([
            ['tenant', 'string'],
            ['code', 'string'],
        ]);

        $this->em = self::createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
        $this->em->expects($this->once())
            ->method('getClassMetadata')
            ->with(stdClass::class)
            ->willReturn($targetMetadata);

        self::assertSame(
            ['tenant' => 'acme', 'code' => 'ABC123'],
            $this->denormalizer->normalizeEntityIdentifier(stdClass::class, '["acme","ABC123"]')
        );
    }

    public function testValuesAreEqual(): void
    {
        self::assertTrue($this->denormalizer->valuesAreEqual('a', 'a'));
        self::assertFalse($this->denormalizer->valuesAreEqual('a', 'b'));

        $date1 = new DateTime('2023-01-01 12:00:00');
        $date2 = new DateTimeImmutable('2023-01-01 12:00:00');
        self::assertTrue($this->denormalizer->valuesAreEqual($date1, $date2));

        $date3 = new DateTime('2023-01-01 12:00:01');
        self::assertFalse($this->denormalizer->valuesAreEqual($date1, $date3));
    }

    public function testDenormalizeDateTimeInvalidArray(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime');

        // Missing 'date' key
        $result = $this->denormalizer->denormalize($metadata, 'field', ['timezone' => 'UTC']);
        self::assertNull($result);
    }

    public function testDenormalizeDateTimeInvalidType(): void
    {
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime');

        $result = $this->denormalizer->denormalize($metadata, 'field', 123); // Int is invalid for datetime
        self::assertNull($result);
    }
}
