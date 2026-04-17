<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Attribute\Auditable;
use Rcsofttech\AuditTrailBundle\Contract\MetadataCacheInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditMetadataManager;
use stdClass;

final class AuditMetadataManagerTest extends TestCase
{
    public function testGetIgnoredPropertiesMemoizesPerClassAndAdditionalIgnoredSet(): void
    {
        $metadataCache = $this->createMock(MetadataCacheInterface::class);
        $metadataCache->expects($this->once())
            ->method('getAuditableAttribute')
            ->with(IgnoredPropertiesEntity::class)
            ->willReturn(new Auditable(ignoredProperties: ['auditable_ignored']));

        $manager = new AuditMetadataManager($metadataCache, ignoredProperties: ['global_ignored']);
        $entity = new IgnoredPropertiesEntity();

        $first = $manager->getIgnoredProperties($entity, ['local_ignored', 'global_ignored']);
        $second = $manager->getIgnoredProperties($entity, ['local_ignored', 'global_ignored']);

        self::assertSame(['global_ignored', 'local_ignored', 'auditable_ignored'], $first);
        self::assertSame($first, $second);
    }

    public function testGetIgnoredPropertiesCacheIgnoresAdditionalIgnoredOrdering(): void
    {
        $metadataCache = $this->createMock(MetadataCacheInterface::class);
        $metadataCache->expects($this->once())
            ->method('getAuditableAttribute')
            ->with(IgnoredPropertiesEntity::class)
            ->willReturn(new Auditable(ignoredProperties: ['auditable_ignored']));

        $manager = new AuditMetadataManager($metadataCache, ignoredProperties: ['global_ignored']);
        $entity = new IgnoredPropertiesEntity();

        $first = $manager->getIgnoredProperties($entity, ['b', 'a']);
        $second = $manager->getIgnoredProperties($entity, ['a', 'b']);

        self::assertSame(['global_ignored', 'a', 'b', 'auditable_ignored'], $first);
        self::assertSame(['global_ignored', 'a', 'b', 'auditable_ignored'], $second);
    }

    public function testIsEntityIgnoredUsesConfiguredLookupBeforeMetadataResolution(): void
    {
        $metadataCache = $this->createMock(MetadataCacheInterface::class);
        $metadataCache->expects($this->never())->method('getAuditableAttribute');

        $manager = new AuditMetadataManager($metadataCache, ignoredEntities: [stdClass::class]);

        self::assertTrue($manager->isEntityIgnored(stdClass::class));
    }
}

final class IgnoredPropertiesEntity
{
}
