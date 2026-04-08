<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Service\AuditLogWriter;
use stdClass;
use Symfony\Component\Uid\Uuid;

final class AuditLogWriterTest extends TestCase
{
    public function testInsertAssignsIdAndWritesConvertedData(): void
    {
        $writer = new AuditLogWriter();
        $audit = new AuditLog(stdClass::class, '123', 'create');

        $metadata = self::createStub(ClassMetadata::class);
        $metadata->method('getFieldNames')->willReturn(['entityClass']);
        $metadata->method('getColumnName')->willReturnCallback(static function (string $field): string {
            if ($field === 'id') {
                return 'id';
            }

            if ($field === 'entityClass') {
                return 'entity_class';
            }

            throw new InvalidArgumentException('Unexpected field '.$field);
        });
        $metadata->method('getTypeOfField')->willReturnCallback(static function (string $field): string {
            if ($field === 'id') {
                return 'uuid';
            }

            if ($field === 'entityClass') {
                return 'string';
            }

            throw new InvalidArgumentException('Unexpected field '.$field);
        });
        $metadata->method('getFieldValue')->willReturnCallback(static function (AuditLog $log, string $field): mixed {
            if ($field === 'id') {
                return $log->id;
            }

            if ($field === 'entityClass') {
                return $log->entityClass;
            }

            throw new InvalidArgumentException('Unexpected field '.$field);
        });
        $metadata->method('setFieldValue')->willReturnCallback(static function (AuditLog $log, string $field, mixed $value): void {
            if ($field !== 'id') {
                throw new InvalidArgumentException('Unexpected field '.$field);
            }

            Closure::bind(static function () use ($log, $value): void {
                $log->id = $value;
            }, null, AuditLog::class)();
        });
        $metadata->method('getTableName')->willReturn('audit_log');

        $connection = $this->createMock(Connection::class);
        $connection->method('convertToDatabaseValue')->willReturnCallback(static function (mixed $value): mixed {
            return $value instanceof Uuid ? 'generated-uuid' : $value;
        });
        $connection->expects($this->once())
            ->method('insert')
            ->with('audit_log', [
                'id' => 'generated-uuid',
                'entity_class' => stdClass::class,
            ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->with(AuditLog::class)->willReturn($metadata);
        $em->method('getConnection')->willReturn($connection);

        $writer->insert($audit, $em);

        self::assertInstanceOf(Uuid::class, $audit->id);
    }
}
