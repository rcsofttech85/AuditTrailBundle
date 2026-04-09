<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditLogWriter;
use ReflectionProperty;
use stdClass;
use Symfony\Component\Uid\Uuid;

use function is_string;

final class AuditLogWriterTest extends TestCase
{
    public function testInsertAssignsIdAndWritesConvertedData(): void
    {
        $writer = new AuditLogWriter();
        $audit = new AuditLog(stdClass::class, '123', 'create');

        $metadata = $this->createMetadataStub(['entityClass']);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects($this->once())
            ->method('insert')
            ->with(
                'audit_log',
                self::callback(static function (array $data): bool {
                    return isset($data['id'], $data['entity_class'])
                        && is_string($data['id'])
                        && $data['id'] !== ''
                        && $data['entity_class'] === stdClass::class;
                })
            );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->with(AuditLog::class)->willReturn($metadata);
        $em->method('getConnection')->willReturn($connection);

        $writer->insert($audit, $em);

        self::assertInstanceOf(Uuid::class, $audit->id);
    }

    public function testInsertTreatsDuplicateDeliveryAsIdempotentSuccess(): void
    {
        $writer = new AuditLogWriter();
        $audit = new AuditLog(stdClass::class, '123', 'create', deliveryId: '0195f4d8-b087-7d44-9c4f-a5c6d4aa5555');

        $metadata = $this->createMetadataStub(['entityClass', 'deliveryId']);

        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());
        $connection->expects($this->once())
            ->method('insert')
            ->willThrowException(self::createStub(UniqueConstraintViolationException::class));
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with('SELECT 1 FROM "audit_log" WHERE "delivery_id" = ?', ['0195f4d8-b087-7d44-9c4f-a5c6d4aa5555'])
            ->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->with(AuditLog::class)->willReturn($metadata);
        $em->method('getConnection')->willReturn($connection);

        $writer->insert($audit, $em);
    }

    private static function setAuditLogId(AuditLog $audit, mixed $value): void
    {
        $property = new ReflectionProperty(AuditLog::class, 'id');
        $property->setValue($audit, $value);
    }

    /**
     * @param list<string> $fields
     */
    /**
     * @param list<string> $fields
     *
     * @return ClassMetadata<object>&Stub
     */
    private function createMetadataStub(array $fields): ClassMetadata&Stub
    {
        /** @var ClassMetadata<object>&Stub $metadata */
        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn($fields);
        $metadata->method('getColumnName')->willReturnCallback(static function (string $field): string {
            return match ($field) {
                'id' => 'id',
                'entityClass' => 'entity_class',
                'deliveryId' => 'delivery_id',
                default => throw new InvalidArgumentException('Unexpected field '.$field),
            };
        });
        $metadata->method('getTypeOfField')->willReturnCallback(static function (string $field): string {
            return match ($field) {
                'id' => 'uuid',
                'entityClass' => 'string',
                'deliveryId' => 'string',
                default => throw new InvalidArgumentException('Unexpected field '.$field),
            };
        });
        $metadata->method('getFieldValue')->willReturnCallback(static function (AuditLog $log, string $field): mixed {
            return match ($field) {
                'id' => $log->id,
                'entityClass' => $log->entityClass,
                'deliveryId' => $log->deliveryId,
                default => throw new InvalidArgumentException('Unexpected field '.$field),
            };
        });
        $metadata->method('setFieldValue')->willReturnCallback(static function (AuditLog $log, string $field, mixed $value): void {
            if ($field !== 'id') {
                throw new InvalidArgumentException('Unexpected field '.$field);
            }

            self::setAuditLogId($log, $value);
        });
        $metadata->method('getTableName')->willReturn('audit_log');

        return $metadata;
    }
}
