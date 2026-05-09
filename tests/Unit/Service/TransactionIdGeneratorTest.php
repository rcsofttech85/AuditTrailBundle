<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use Symfony\Component\Uid\Factory\MockUuidFactory;
use Symfony\Component\Uid\Factory\UuidFactory;

final class TransactionIdGeneratorTest extends TestCase
{
    public function testGetTransactionIdReturnsUuid(): void
    {
        $generator = new TransactionIdGenerator(new UuidFactory());
        $id = $generator->getTransactionId();

        self::assertNotEmpty($id);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $id
        );
    }

    public function testGetTransactionIdReturnsSameIdForSameInstance(): void
    {
        $generator = new TransactionIdGenerator(new UuidFactory());
        $id1 = $generator->getTransactionId();
        $id2 = $generator->getTransactionId();

        self::assertSame($id1, $id2);
    }

    public function testDifferentInstancesReturnDifferentIds(): void
    {
        $generator1 = new TransactionIdGenerator(new UuidFactory());
        $generator2 = new TransactionIdGenerator(new UuidFactory());

        self::assertNotSame($generator1->getTransactionId(), $generator2->getTransactionId());
    }

    public function testResetGeneratesNewId(): void
    {
        $generator = new TransactionIdGenerator(
            new MockUuidFactory([
                '0195f4d8-b087-7d44-9c4f-a5c6d4aa0001',
                '0195f4d8-b087-7d44-9c4f-a5c6d4aa0002',
            ]),
        );
        $id1 = $generator->getTransactionId();

        $generator->reset();
        $id2 = $generator->getTransactionId();

        self::assertSame('0195f4d8-b087-7d44-9c4f-a5c6d4aa0001', $id1);
        self::assertSame('0195f4d8-b087-7d44-9c4f-a5c6d4aa0002', $id2);
    }
}
