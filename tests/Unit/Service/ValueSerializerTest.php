<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;
use stdClass;
use Stringable;

#[AllowMockObjectsWithoutExpectations]
class ValueSerializerTest extends TestCase
{
    public function testSerializeRespectsMaxDepth(): void
    {
        $serializer = new ValueSerializer();

        // Let's create a deeply nested array
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

        // Expected structure:
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

    public function testSerializeResource(): void
    {
        $serializer = new ValueSerializer();
        $resource = fopen('php://memory', 'r');
        self::assertNotFalse($resource);

        self::assertStringStartsWith('[resource: stream]', $serializer->serialize($resource));

        fclose($resource);
    }

    public function testSerializeObjectWithId(): void
    {
        $serializer = new ValueSerializer();
        $object = new class {
            public function getId(): int
            {
                return 1;
            }
        };

        self::assertSame(1, $serializer->serialize($object));
    }

    public function testSerializeObjectWithToString(): void
    {
        $serializer = new ValueSerializer();
        $object = new class {
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

        $object = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        self::assertSame(1, $serializer->serializeAssociation($object));

        self::assertNull($serializer->serializeAssociation('not_an_object'));

        // Test collection in association
        $collection = new \Doctrine\Common\Collections\ArrayCollection([$object]);
        self::assertEquals([1], $serializer->serializeAssociation($collection));

        // Test object without getId
        $objNoId = new stdClass();
        self::assertEquals('stdClass', $serializer->serializeAssociation($objNoId));
    }

    public function testSerializeCollection(): void
    {
        $serializer = new ValueSerializer();
        $collection = new \Doctrine\Common\Collections\ArrayCollection(['a', 'b']);

        self::assertEquals(['a', 'b'], $serializer->serialize($collection));
    }

    public function testSerializeLargeCollection(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $serializer = new ValueSerializer($logger);

        $items = array_fill(0, 101, 'item');
        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);

        $result = $serializer->serialize($collection);

        self::assertIsArray($result);
        self::assertTrue($result['_truncated']);
        self::assertEquals(101, $result['_total_count']);
        self::assertCount(100, $result['_sample']);
    }

    public function testSerializeObjectIdRecursionLimit(): void
    {
        $serializer = new ValueSerializer();

        // Create an object whose ID is another object, recurring deep enough to hit the limit
        $deepObj = new class {
            public object $child;

            public function getId(): object
            {
                return $this->child;
            }
        };

        $obj1 = clone $deepObj;
        $obj2 = clone $deepObj;
        $obj3 = clone $deepObj;
        $obj4 = clone $deepObj;
        $obj5 = clone $deepObj;
        $obj6 = clone $deepObj;

        $obj1->child = $obj2;
        $obj2->child = $obj3;
        $obj3->child = $obj4;
        $obj4->child = $obj5;
        $obj5->child = $obj6;
        $obj6->child = new stdClass();

        $result = $serializer->serialize($obj1);
        self::assertSame('[max depth reached]', $result);
    }

    public function testExtractEntityIdentifierWithObjectId(): void
    {
        $serializer = new ValueSerializer();

        $idObj = new class {
            public function __toString(): string
            {
                return 'id_string';
            }
        };

        $entity = new class($idObj) {
            public function __construct(private object $id)
            {
            }

            public function getId(): object
            {
                return $this->id;
            }
        };

        // This tests line 146 where is_object($id) is true in extractEntityIdentifier
        $result = $serializer->serializeAssociation($entity);
        self::assertSame('id_string', $result);
    }

    public function testSerializeStringableInterface(): void
    {
        $serializer = new ValueSerializer();
        // PHP 8 Stringable interface
        $stringable = new class implements Stringable {
            public function __toString(): string
            {
                return 'stringable_val';
            }
        };

        $result = $serializer->serialize($stringable);
        self::assertSame('stringable_val', $result);
    }

    public function testSerializeEnum(): void
    {
        $serializer = new ValueSerializer();
        self::assertSame('Foo', $serializer->serialize(TestUnitEnum::Foo));
        self::assertSame('bar_value', $serializer->serialize(TestBackedEnum::Bar));
    }

    public function testSerializeUninitializedPersistentCollection(): void
    {
        $serializer = new ValueSerializer();
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $classMetadata = new \Doctrine\ORM\Mapping\ClassMetadata('stdClass');

        $collection = new \Doctrine\ORM\PersistentCollection($em, $classMetadata, new \Doctrine\Common\Collections\ArrayCollection());
        $collection->setInitialized(false);

        $result = $serializer->serialize($collection);
        self::assertEquals([
            '_state' => 'uninitialized',
            '_total_count' => 'unknown',
        ], $result);
    }

    public function testSerializeCollectionIdentifiersOnly(): void
    {
        $serializer = new ValueSerializer();
        $obj1 = new class {
            public function getId(): int
            {
                return 1;
            }
        };
        $obj2 = new class {
            public function getId(): string
            {
                return 'two';
            }
        };
        // a primitive to test the `is_object` check in the array_map closure
        $primitive = 'primitive_value';

        $collection = new \Doctrine\Common\Collections\ArrayCollection([$obj1, $obj2, $primitive]);

        $result = $serializer->serializeAssociation($collection);
        self::assertEquals([1, 'two', 'primitive_value'], $result);
    }

    public function testSerializeLargeCollectionIdentifiersOnly(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $serializer = new ValueSerializer($logger);

        // Mix objects and primitives
        $items = [];
        for ($i = 0; $i < 101; ++$i) {
            $items[] = new class($i) {
                public function __construct(private int $id)
                {
                }

                public function getId(): int
                {
                    return $this->id;
                }
            };
        }
        $items[50] = 'primitive'; // Insert a primitive to cover is_object() = false logic

        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);

        $result = $serializer->serializeAssociation($collection);

        self::assertIsArray($result);
        self::assertTrue($result['_truncated']);
        self::assertEquals(101, $result['_total_count']);
        self::assertCount(100, $result['_sample']);

        self::assertEquals(0, $result['_sample'][0]);
        self::assertEquals('primitive', $result['_sample'][50]);
    }

    /**
     * Test that serialize(null) returns null, not void.
     * Kills mutant: MatchArm removal for null => null.
     */
    public function testSerializeNullReturnsNull(): void
    {
        $serializer = new ValueSerializer();
        $result = $serializer->serialize(null);
        self::assertNull($result);
    }

    /**
     * Test serializeAssociation(null) returns exactly null (not void).
     * Kills mutant: ReturnRemoval in serializeAssociation null branch.
     */
    public function testSerializeAssociationNullReturnsNull(): void
    {
        $serializer = new ValueSerializer();
        $result = $serializer->serializeAssociation(null);
        self::assertNull($result);
    }

    /**
     * Test boundary: collection with exactly 100 items should NOT be truncated.
     * Kills mutant: > vs >= on MAX_COLLECTION_ITEMS check.
     */
    public function testSerializeCollectionBoundaryExactly100(): void
    {
        $serializer = new ValueSerializer();
        $items = array_fill(0, 100, 'item');
        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);

        $result = $serializer->serialize($collection);
        // 100 items should NOT be truncated (> 100, not >= 100)
        self::assertIsArray($result);
        self::assertArrayNotHasKey('_truncated', $result);
        self::assertCount(100, $result);
    }

    /**
     * Test collection with nested arrays to detect depth increment mutations.
     * Kills mutants: depth + 1 → depth + 0, depth + 2, depth - 1.
     */
    public function testSerializeCollectionDepthIncrement(): void
    {
        $serializer = new ValueSerializer();

        // Create collection with deeply nested arrays
        // depth starts at 0 when called from serialize()
        // collection at depth 0, items serialized at depth 1, nested arrays at depth 2, etc.
        $nestedItem = [
            'level1' => [
                'level2' => [
                    'level3' => [
                        'level4' => [
                            'level5' => 'deep_value',
                        ],
                    ],
                ],
            ],
        ];

        $collection = new \Doctrine\Common\Collections\ArrayCollection([$nestedItem]);
        $result = $serializer->serialize($collection);

        // With correct depth: serialize(collection, 0) → items serialized at depth 1
        // The nested array should reach max depth at level 5
        self::assertIsArray($result);
        self::assertIsArray($result[0]);
        self::assertIsArray($result[0]['level1']);
        self::assertIsArray($result[0]['level1']['level2']);
        self::assertIsArray($result[0]['level1']['level2']['level3']);
        // At depth 5 (0 + 1 for collection + 4 levels), should hit max depth
        self::assertSame('[max depth reached]', $result[0]['level1']['level2']['level3']['level4']);
    }

    /**
     * Test serializeAssociation with objects uses extractEntityIdentifier (onlyIdentifiers=true).
     * vs serialize() which uses full serialization (onlyIdentifiers=false).
     * Kills mutants: TrueValue and FalseValue on onlyIdentifiers parameter.
     */
    public function testSerializeAssociationUsesIdentifiersNotFullSerialization(): void
    {
        $serializer = new ValueSerializer();

        // Object with getId returning an object with __toString
        $entity = new class {
            public function getId(): int
            {
                return 42;
            }

            public function __toString(): string
            {
                return 'Entity#42';
            }
        };

        // serializeAssociation with a single object should use extractEntityIdentifier
        $assocResult = $serializer->serializeAssociation($entity);
        self::assertSame(42, $assocResult); // extractEntityIdentifier returns getId()

        // serialize with the same object should use serializeObject
        $serializeResult = $serializer->serialize($entity);
        self::assertSame(42, $serializeResult); // serializeObject also returns getId()

        // Now test with a collection - serializeAssociation should use onlyIdentifiers=true
        $collection = new \Doctrine\Common\Collections\ArrayCollection([$entity]);

        $assocCollectionResult = $serializer->serializeAssociation($collection);
        self::assertSame([42], $assocCollectionResult);

        // serialize() on collection uses onlyIdentifiers=false — also returns getId via serializeObject
        $serializeCollectionResult = $serializer->serialize($collection);
        self::assertSame([42], $serializeCollectionResult);
    }

    /**
     * Test large collection with onlyIdentifiers=false (via serialize, not serializeAssociation).
     * Ensures truncated sample uses serialize(), not extractEntityIdentifier.
     * Kills mutants on depth + 1 in truncated branch.
     */
    public function testSerializeLargeCollectionWithoutIdentifiersOnly(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $serializer = new ValueSerializer($logger);

        // Create 101 items that are nested arrays to test depth
        $items = [];
        for ($i = 0; $i < 101; ++$i) {
            $items[] = ['nested' => ['value' => $i]];
        }
        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);

        $result = $serializer->serialize($collection);

        self::assertIsArray($result);
        self::assertTrue($result['_truncated']);
        self::assertCount(100, $result['_sample']);
        // First item should be fully serialized (not just identifier extraction)
        self::assertIsArray($result['_sample'][0]);
        self::assertSame(['value' => 0], $result['_sample'][0]['nested']);
    }

    /**
     * Test that slice(0, ...) matters — first item must be present.
     * Kills mutant: slice(0, ...) → slice(1, ...).
     */
    public function testLargeCollectionSliceStartsAtZero(): void
    {
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $serializer = new ValueSerializer($logger);

        $items = [];
        for ($i = 0; $i < 101; ++$i) {
            $items[] = 'item_'.$i;
        }
        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);

        $result = $serializer->serialize($collection);

        self::assertIsArray($result);
        self::assertTrue($result['_truncated']);
        // First item should be item_0, not item_1
        self::assertSame('item_0', $result['_sample'][0]);
        // Last item in sample should be item_99
        self::assertSame('item_99', $result['_sample'][99]);
    }

    /**
     * Test large collection via serializeAssociation logger null-safe call.
     * Kills mutant: logger?-> vs logger->.
     */
    public function testLargeCollectionWithoutLoggerDoesNotCrash(): void
    {
        // No logger - should not crash on null safe method call
        $serializer = new ValueSerializer(null);

        $items = array_fill(0, 101, 'item');
        $collection = new \Doctrine\Common\Collections\ArrayCollection($items);

        $result = $serializer->serialize($collection);

        self::assertIsArray($result);
        self::assertTrue($result['_truncated']);
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
