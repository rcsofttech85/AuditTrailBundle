<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogChangedFieldConstraintBuilder;

use const JSON_THROW_ON_ERROR;

final class AuditLogChangedFieldConstraintBuilderTest extends TestCase
{
    public function testApplyBuildsMysqlJsonContainsPredicate(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $identifier): string => $identifier);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL80Platform());

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $parameters = [];

        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with("(JSON_CONTAINS(a.changed_fields, :changedField_0, '$') = 1 OR JSON_CONTAINS(a.changed_fields, :changedField_1, '$') = 1)")
            ->willReturnSelf();
        $queryBuilder->expects($this->exactly(2))
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use (&$parameters, $queryBuilder): QueryBuilder {
                $parameters[$name] = $value;

                return $queryBuilder;
            });

        new AuditLogChangedFieldConstraintBuilder()->apply($queryBuilder, $connection, ['status', 'publishedAt'], 'changed_fields');

        self::assertSame(json_encode('status', JSON_THROW_ON_ERROR), $parameters['changedField_0']);
        self::assertSame(json_encode('publishedAt', JSON_THROW_ON_ERROR), $parameters['changedField_1']);
    }

    public function testApplyBuildsPostgresqlJsonbPredicate(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $identifier): string => $identifier);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $parameters = [];

        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('(jsonb_exists(CAST(a.changed_fields AS JSONB), :changedField_0))')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use (&$parameters, $queryBuilder): QueryBuilder {
                $parameters[$name] = $value;

                return $queryBuilder;
            });

        new AuditLogChangedFieldConstraintBuilder()->apply($queryBuilder, $connection, ['status'], 'changed_fields');

        self::assertSame('status', $parameters['changedField_0']);
    }

    public function testApplyBuildsSqliteJsonEachPredicate(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(static fn (string $identifier): string => $identifier);
        $connection->method('getDatabasePlatform')->willReturn(new SQLitePlatform());

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $parameters = [];

        $queryBuilder->expects($this->once())
            ->method('andWhere')
            ->with('(EXISTS (SELECT 1 FROM json_each(a.changed_fields) WHERE json_each.value = :changedField_0))')
            ->willReturnSelf();
        $queryBuilder->expects($this->once())
            ->method('setParameter')
            ->willReturnCallback(static function (string $name, mixed $value) use (&$parameters, $queryBuilder): QueryBuilder {
                $parameters[$name] = $value;

                return $queryBuilder;
            });

        new AuditLogChangedFieldConstraintBuilder()->apply($queryBuilder, $connection, ['status'], 'changed_fields');

        self::assertSame('status', $parameters['changedField_0']);
    }
}
