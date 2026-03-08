<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use stdClass;
use Stringable;

#[AllowMockObjectsWithoutExpectations]
class ValueSerializerTest extends AbstractAuditTestCase
{
    private EntityManagerInterface&MockObject $em;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
    }

    public function testSerializeBasicTypes(): void
    {
        $serializer = new ValueSerializer();

        self::assertSame('test', $serializer->serialize('test'));
        self::assertSame(123, $serializer->serialize(123));
        self::assertSame(12.34, $serializer->serialize(12.34));
        self::assertTrue($serializer->serialize(true));
        self::assertNull($serializer->serialize(null));
    }

    public function testSerializeDateTime(): void
    {
        $serializer = new ValueSerializer();
        $date = new DateTimeImmutable('2023-01-01 12:00:00');

        self::assertSame('2023-01-01T12:00:00+00:00', $serializer->serialize($date));
    }

    public function testSerializeEnum(): void
    {
        $serializer = new ValueSerializer();
        self::assertSame('Foo', $serializer->serialize(TestUnitEnum::Foo));
        self::assertSame('bar_value', $serializer->serialize(TestBackedEnum::Bar));
    }

    public function testSerializeObjectWithId(): void
    {
        $serializer = new ValueSerializer();
        $entity = new TestEntity(42);

        self::assertSame(42, $serializer->serialize($entity));
    }

    public function testSerializeObjectWithToString(): void
    {
        $serializer = new ValueSerializer();
        $object = new class implements Stringable {
            public function __toString(): string
            {
                return 'string_rep';
            }
        };

        self::assertSame('string_rep', $serializer->serialize($object));
    }

    public function testSerializeObjectFallback(): void
    {
        $serializer = new ValueSerializer();
        $object = new stdClass();

        self::assertSame('stdClass', $serializer->serialize($object));
    }

    public function testSerializeAssociation(): void
    {
        $serializer = new ValueSerializer();

        self::assertNull($serializer->serializeAssociation(null));

        $entity = new TestEntity(42);
        self::assertSame(42, $serializer->serializeAssociation($entity));

        self::assertNull($serializer->serializeAssociation('not_an_object'));
    }

    public function testSerializeRespectsMaxDepth(): void
    {
        $serializer = new ValueSerializer();

        $deepArray = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'level5' => [
                                'level6' => 'value',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $serialized = $serializer->serialize($deepArray);

        self::assertSame(
            [
                'level1' => [
                    'level2' => [
                        'level3' => [
                            'level4' => [
                                'level5' => '[max depth reached]',
                            ],
                        ],
                    ],
                ],
            ],
            $serialized
        );
    }

    #[DataProvider('provideCollectionModes')]
    public function testCollectionSerializationModes(string $mode, mixed $expected): void
    {
        $serializer = new ValueSerializer(null, $mode);
        /** @var ClassMetadata<TestEntity>&MockObject $metadata */
        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(TestEntity::class);

        /** @var ArrayCollection<int, TestEntity>&Selectable<int, TestEntity> $inner */
        $inner = new ArrayCollection([new TestEntity(1), new TestEntity(2)]);
        /** @phpstan-ignore-next-line */
        $collection = $this->createUninitializedCollection($this->em, $metadata, $inner);

        $result = $serializer->serialize($collection);

        self::assertEquals($expected, $result);
    }

    /**
     * @return iterable<string, array{0: string, 1: mixed}>
     */
    public static function provideCollectionModes(): iterable
    {
        yield 'lazy mode returns placeholder' => [
            'lazy',
            ['_state' => 'uninitialized', '_total_count' => 'unknown'],
        ];

        yield 'eager mode returns full values (IDs by default)' => [
            'eager',
            [1, 2],
        ];

        yield 'ids_only mode returns IDs' => [
            'ids_only',
            [1, 2],
        ];
    }

    public function testIdsOnlyModeForcesIdentifiersEvenDirectly(): void
    {
        $serializer = new ValueSerializer(null, 'ids_only');

        $collection = new ArrayCollection([new TestEntity(1), new TestEntity(2)]);

        // Direct serialize() usually tries for detail, but ids_only should lock it to IDs
        $result = $serializer->serialize($collection);

        self::assertEquals([1, 2], $result);
    }

    public function testMaxItemsRespectsConfig(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $serializer = new ValueSerializer($logger, 'eager', 2);
        $collection = new ArrayCollection([1, 2, 3, 4, 5]);

        $result = $serializer->serialize($collection);

        self::assertTrue($result['_truncated']);
        self::assertEquals(5, $result['_total_count']);
        self::assertCount(2, $result['_sample']);
        self::assertEquals([1, 2], $result['_sample']);
    }

    public function testSerializeObjectIdRecursionLimit(): void
    {
        $serializer = new ValueSerializer();

        $current = new class {
            public function getId(): object
            {
                return new stdClass();
            }
        };

        for ($i = 0; $i < 5; ++$i) {
            $parent = new class {
                public mixed $child;

                public function getId(): mixed
                {
                    return $this->child;
                }
            };
            $parent->child = $current;
            $current = $parent;
        }

        self::assertSame('[max depth reached]', $serializer->serialize($current));
    }
}

enum TestUnitEnum
{
    case Foo;
}

enum TestBackedEnum: string
{
    case Bar = 'bar_value';
}
