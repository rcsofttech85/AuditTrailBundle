<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use ArrayObject;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\MetadataCache;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\ChildAuditable;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\NotAuditable;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\ParentAuditable;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\SensitiveEntity;
use RuntimeException;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class MetadataCacheTest extends TestCase
{
    private MetadataCache $cache;

    protected function setUp(): void
    {
        $this->cache = new MetadataCache();
    }

    public function testGetAuditableAttribute(): void
    {
        $attr = $this->cache->getAuditableAttribute(ParentAuditable::class);
        self::assertNotNull($attr);
        self::assertTrue($attr->enabled);

        // Test Cache Hit
        $attr2 = $this->cache->getAuditableAttribute(ParentAuditable::class);
        self::assertSame($attr, $attr2);
    }

    public function testGetAuditableAttributeInheritance(): void
    {
        $attr = $this->cache->getAuditableAttribute(ChildAuditable::class);
        self::assertNotNull($attr);
        self::assertTrue($attr->enabled);
    }

    public function testGetAuditableAttributeNone(): void
    {
        self::assertNull($this->cache->getAuditableAttribute(NotAuditable::class));
    }

    public function testGetAuditableAttributeInvalidClass(): void
    {
        self::assertNull($this->cache->getAuditableAttribute('InvalidClass'));
    }

    public function testGetSensitiveFields(): void
    {
        $fields = $this->cache->getSensitiveFields(SensitiveEntity::class);

        self::assertArrayHasKey('secret', $fields);
        self::assertEquals('***', $fields['secret']);

        self::assertArrayHasKey('password', $fields);
        self::assertEquals('**REDACTED**', $fields['password']);

        self::assertArrayNotHasKey('public', $fields);

        // Test Cache Hit
        $fields2 = $this->cache->getSensitiveFields(SensitiveEntity::class);
        self::assertSame($fields, $fields2);
    }

    public function testCacheReturnsConsistentResultsForManyClasses(): void
    {
        // Verifies the cache handles many lookups without issues
        // and always returns null for non-auditable classes
        $classes = [
            NotAuditable::class,
            stdClass::class,
            DateTimeImmutable::class,
            RuntimeException::class,
            ArrayObject::class,
        ];

        foreach ($classes as $class) {
            $result1 = $this->cache->getAuditableAttribute($class);
            $result2 = $this->cache->getAuditableAttribute($class);
            self::assertSame($result1, $result2, "Cache should return identical result for {$class}");
            self::assertNull($result1, "{$class} should not be auditable");
        }
    }
}
