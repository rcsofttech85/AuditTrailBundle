<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\EventSubscriber;

use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\TablePrefixSubscriber;

#[CoversClass(TablePrefixSubscriber::class)]
#[AllowMockObjectsWithoutExpectations]
final class TablePrefixSubscriberTest extends TestCase
{
    public function testLoadClassMetadataWithPrefixAndSuffix(): void
    {
        $subscriber = new TablePrefixSubscriber('prefix', 'suffix');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(AuditLog::class);
        $metadata->method('getTableName')->willReturn('audit_log');
        $metadata->expects($this->once())->method('setPrimaryTable')->with(['name' => 'prefix_audit_log_suffix']);

        $args = self::createStub(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        $subscriber->loadClassMetadata($args);
    }

    public function testLoadClassMetadataWithNoPrefixAndSuffix(): void
    {
        $subscriber = new TablePrefixSubscriber('', '');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn(AuditLog::class);
        $metadata->expects($this->never())->method('setPrimaryTable');

        $args = self::createStub(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        $subscriber->loadClassMetadata($args);
    }

    public function testLoadClassMetadataWithOtherEntity(): void
    {
        $subscriber = new TablePrefixSubscriber('prefix', 'suffix');

        $metadata = $this->createMock(ClassMetadata::class);
        $metadata->method('getName')->willReturn('OtherEntity');
        $metadata->expects($this->never())->method('setPrimaryTable');

        $args = self::createStub(LoadClassMetadataEventArgs::class);
        $args->method('getClassMetadata')->willReturn($metadata);

        $subscriber->loadClassMetadata($args);
    }
}
