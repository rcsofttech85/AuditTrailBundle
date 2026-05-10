<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogSqlIdentifierQuoter;

final class AuditLogSqlIdentifierQuoterTest extends TestCase
{
    public function testQuoteColumnReferenceUsesSingleIdentifierQuoting(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '['.$identifier.']'
        );

        self::assertSame(
            'a.[changed_fields]',
            AuditLogSqlIdentifierQuoter::quoteColumnReference($connection, 'a', 'changed_fields'),
        );
    }

    public function testQuoteTableReferenceQuotesSchemaAndTableSeparately(): void
    {
        $connection = self::createStub(Connection::class);
        $connection->method('quoteSingleIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '['.$identifier.']'
        );

        self::assertSame(
            '[audit].[audit_log]',
            AuditLogSqlIdentifierQuoter::quoteTableReference($connection, 'audit_log', 'audit'),
        );
    }
}
