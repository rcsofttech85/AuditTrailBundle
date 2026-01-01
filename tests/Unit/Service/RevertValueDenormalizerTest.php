<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\RevertValueDenormalizer;

#[AllowMockObjectsWithoutExpectations]
class RevertValueDenormalizerTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private RevertValueDenormalizer $denormalizer;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->denormalizer = new RevertValueDenormalizer($this->em);
    }

    public function testDenormalizeNull(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        self::assertNull($this->denormalizer->denormalize($metadata, 'field', null));
    }

    public function testDenormalizeSimpleField(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->with('field')->willReturn(true);
        $metadata->method('getTypeOfField')->with('field')->willReturn('string');

        self::assertEquals('value', $this->denormalizer->denormalize($metadata, 'field', 'value'));
    }

    public function testDenormalizeDateTimeImmutableFromString(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->with('field')->willReturn(true);
        $metadata->method('getTypeOfField')->with('field')->willReturn('datetime_immutable');

        $result = $this->denormalizer->denormalize($metadata, 'field', '2023-01-01 12:00:00');
        self::assertInstanceOf(\DateTimeImmutable::class, $result);
        self::assertEquals('2023-01-01 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDenormalizeDateTimeMutableFromArray(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->with('field')->willReturn(true);
        $metadata->method('getTypeOfField')->with('field')->willReturn('datetime');

        $value = ['date' => '2023-01-01 12:00:00.000000', 'timezone' => 'UTC'];
        $result = $this->denormalizer->denormalize($metadata, 'field', $value);

        self::assertInstanceOf(\DateTime::class, $result);
        self::assertEquals('2023-01-01 12:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDenormalizeDateTimeObject(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->with('field')->willReturn(true);
        $metadata->method('getTypeOfField')->with('field')->willReturn('datetime');

        $date = new \DateTime();
        $result = $this->denormalizer->denormalize($metadata, 'field', $date);
        self::assertSame($date, $result);
    }

    public function testDenormalizeAssociationObject(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->with('field')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->with('field')->willReturn(\stdClass::class);

        $entity = new \stdClass();
        $result = $this->denormalizer->denormalize($metadata, 'field', $entity);
        self::assertSame($entity, $result);
    }

    public function testDenormalizeAssociationId(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(false);
        $metadata->method('hasAssociation')->with('field')->willReturn(true);
        $metadata->method('getAssociationTargetClass')->with('field')->willReturn(\stdClass::class);

        $entity = new \stdClass();
        $this->em->expects($this->once())->method('find')->with(\stdClass::class, 1)->willReturn($entity);

        $result = $this->denormalizer->denormalize($metadata, 'field', 1);
        self::assertSame($entity, $result);
    }

    public function testValuesAreEqual(): void
    {
        self::assertTrue($this->denormalizer->valuesAreEqual('a', 'a'));
        self::assertFalse($this->denormalizer->valuesAreEqual('a', 'b'));

        $date1 = new \DateTime('2023-01-01 12:00:00');
        $date2 = new \DateTimeImmutable('2023-01-01 12:00:00');
        self::assertTrue($this->denormalizer->valuesAreEqual($date1, $date2));

        $date3 = new \DateTime('2023-01-01 12:00:01');
        self::assertFalse($this->denormalizer->valuesAreEqual($date1, $date3));
    }

    public function testDenormalizeDateTimeInvalidArray(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime');

        // Missing 'date' key
        $result = $this->denormalizer->denormalize($metadata, 'field', ['timezone' => 'UTC']);
        self::assertNull($result);
    }

    public function testDenormalizeDateTimeInvalidType(): void
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('hasField')->willReturn(true);
        $metadata->method('getTypeOfField')->willReturn('datetime');

        $result = $this->denormalizer->denormalize($metadata, 'field', 123); // Int is invalid for datetime
        self::assertNull($result);
    }
}
