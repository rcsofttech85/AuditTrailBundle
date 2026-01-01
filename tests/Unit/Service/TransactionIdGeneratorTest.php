<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;

#[AllowMockObjectsWithoutExpectations]
class TransactionIdGeneratorTest extends TestCase
{
    public function testGetTransactionIdReturnsUuid(): void
    {
        $generator = new TransactionIdGenerator();
        $id = $generator->getTransactionId();

        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function testGetTransactionIdReturnsSameIdForSameInstance(): void
    {
        $generator = new TransactionIdGenerator();
        $id1 = $generator->getTransactionId();
        $id2 = $generator->getTransactionId();

        self::assertSame($id1, $id2);
    }

    public function testDifferentInstancesReturnDifferentIds(): void
    {
        $generator1 = new TransactionIdGenerator();
        $generator2 = new TransactionIdGenerator();

        self::assertNotSame($generator1->getTransactionId(), $generator2->getTransactionId());
    }

    public function testResetGeneratesNewId(): void
    {
        $generator = new TransactionIdGenerator();
        $id1 = $generator->getTransactionId();

        $generator->reset();
        $id2 = $generator->getTransactionId();

        self::assertNotSame($id1, $id2);
        self::assertNotEmpty($id2);
    }
}
