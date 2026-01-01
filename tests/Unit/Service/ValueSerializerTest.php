<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\ValueSerializer;

class ValueSerializerTest extends TestCase
{
    #[AllowMockObjectsWithoutExpectations]
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
        $date = new \DateTimeImmutable('2023-01-01 12:00:00');

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
        $object = new class () {
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
        $object = new class () {
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
        $object = new \stdClass();

        self::assertSame('stdClass', $serializer->serialize($object));
    }

    public function testSerializeAssociation(): void
    {
        $serializer = new ValueSerializer();

        self::assertNull($serializer->serializeAssociation(null));

        $object = new class () {
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
        $objNoId = new \stdClass();
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
}
