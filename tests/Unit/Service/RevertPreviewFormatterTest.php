<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Rcsofttech\AuditTrailBundle\Service\RevertPreviewFormatter;
use Rcsofttech\AuditTrailBundle\Tests\Unit\AbstractAuditTestCase;
use stdClass;
use Stringable;

final class RevertPreviewFormatterTest extends AbstractAuditTestCase
{
    private RevertPreviewFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new RevertPreviewFormatter();
    }

    public function testFormatNull(): void
    {
        self::assertNull($this->formatter->format(null));
    }

    public function testFormatDateTime(): void
    {
        $date = new DateTimeImmutable('2024-03-12 15:30:00');
        self::assertSame('2024-03-12 15:30:00', $this->formatter->format($date));
    }

    public function testFormatStringableObject(): void
    {
        $object = new class implements Stringable {
            public function __toString(): string
            {
                return 'string_representation';
            }
        };

        self::assertSame('string_representation', $this->formatter->format($object));
    }

    public function testFormatObjectWithId(): void
    {
        $object = new class {
            public function getId(): int
            {
                return 42;
            }
        };

        $result = $this->formatter->format($object);
        self::assertIsString($result);
        self::assertStringContainsString('#42', $result);
    }

    public function testFormatPlainObject(): void
    {
        $object = new stdClass();
        self::assertSame('stdClass', $this->formatter->format($object));
    }

    public function testFormatTraversableCollection(): void
    {
        $value = new ArrayCollection([
            new class {
                public function getId(): int
                {
                    return 42;
                }

                public function getLabel(): string
                {
                    return 'php';
                }
            },
            new class {
                public function getId(): int
                {
                    return 7;
                }

                public function getName(): string
                {
                    return 'Backend';
                }
            },
        ]);

        self::assertSame([
            'Anonymous#42 (php)',
            'Anonymous#7 (Backend)',
        ], $this->formatter->format($value));
    }

    public function testFormatObjectWithPublicPhp84StyleProperties(): void
    {
        $object = new class {
            public private(set) int $id = 42;

            public function __construct(
                public private(set) string $name = 'Backend',
            ) {
            }
        };

        self::assertSame('Anonymous#42 (Backend)', $this->formatter->format($object));
    }

    public function testFormatObjectWithTitleButWithoutId(): void
    {
        $object = new class {
            public function getTitle(): string
            {
                return 'Release Notes';
            }
        };

        self::assertSame('Anonymous (Release Notes)', $this->formatter->format($object));
    }

    public function testFormatIgnoresNonPublicLabelProperty(): void
    {
        $object = new class {
            private string $label = 'Hidden';

            public function __construct()
            {
                $this->consume($this->label);
            }

            private function consume(string $_value): void
            {
            }
        };

        self::assertSame('Anonymous', $this->formatter->format($object));
    }

    public function testFormatIgnoresUninitializedPublicLabelProperty(): void
    {
        $object = new class {
            public string $label;
        };

        self::assertSame('Anonymous', $this->formatter->format($object));
    }

    public function testFormatRecursiveArray(): void
    {
        $data = [
            'name' => 'Test',
            'date' => new DateTimeImmutable('2024-03-12 15:30:00'),
            'nested' => [
                'val' => null,
                'obj' => new stdClass(),
            ],
        ];

        $expected = [
            'name' => 'Test',
            'date' => '2024-03-12 15:30:00',
            'nested' => [
                'val' => null,
                'obj' => 'stdClass',
            ],
        ];

        self::assertSame($expected, $this->formatter->format($data));
    }

    public function testFormatScalars(): void
    {
        self::assertSame(123, $this->formatter->format(123));
        self::assertSame('string', $this->formatter->format('string'));
        self::assertTrue($this->formatter->format(true));
    }
}
