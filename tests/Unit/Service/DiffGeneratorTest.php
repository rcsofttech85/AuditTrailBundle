<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\DiffGenerator;

#[AllowMockObjectsWithoutExpectations]
class DiffGeneratorTest extends TestCase
{
    private DiffGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new DiffGenerator();
    }

    public function testGenerateBasicDiff(): void
    {
        $old = ['name' => 'John', 'age' => 30];
        $new = ['name' => 'Jane', 'age' => 30];

        $diff = $this->generator->generate($old, $new);

        self::assertCount(1, $diff);
        self::assertArrayHasKey('name', $diff);
        self::assertEquals('John', $diff['name']['old']);
        self::assertEquals('Jane', $diff['name']['new']);
    }

    public function testGenerateWithNormalization(): void
    {
        $date1 = new \DateTimeImmutable('2023-01-01 10:00:00', new \DateTimeZone('UTC'));
        $date2 = new \DateTimeImmutable('2023-01-01 11:00:00', new \DateTimeZone('UTC'));

        $old = ['updatedAt' => $date1, 'data' => ['foo' => 'bar']];
        $new = ['updatedAt' => $date2, 'data' => ['foo' => 'baz']];

        // By default updatedAt is ignored
        $diff = $this->generator->generate($old, $new);
        self::assertCount(1, $diff);
        self::assertArrayHasKey('data', $diff);
        self::assertStringContainsString('"foo": "baz"', $diff['data']['new']);

        // Include timestamps
        $diff = $this->generator->generate($old, $new, ['include_timestamps' => true]);
        self::assertCount(2, $diff);
        self::assertEquals('2023-01-01 10:00:00 UTC', $diff['updatedAt']['old']);
    }

    public function testGenerateRaw(): void
    {
        $old = ['data' => ['foo' => 'bar']];
        $new = ['data' => ['foo' => 'baz']];

        $diff = $this->generator->generate($old, $new, ['raw' => true]);

        self::assertIsArray($diff['data']['old']);
        self::assertEquals('bar', $diff['data']['old']['foo']);
    }

    public function testNormalizationOfJsonString(): void
    {
        $old = ['config' => '{"a":1}'];
        $new = ['config' => '{"a": 2}'];

        $diff = $this->generator->generate($old, $new);

        self::assertStringContainsString('"a": 1', $diff['config']['old']);
        self::assertStringContainsString('"a": 2', $diff['config']['new']);
    }
}
