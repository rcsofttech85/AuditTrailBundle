<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Query;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryState;

final class AuditQueryStateTest extends TestCase
{
    public function testWithMethodsReturnNewStateWithUpdatedValues(): void
    {
        self::assertFalse(new AuditQueryState()->hasChangedFieldFilter());

        $since = new DateTimeImmutable('-1 day');
        $until = new DateTimeImmutable();

        $state = new AuditQueryState()
            ->withEntity('App\\Entity\\User', '1')
            ->withActions([AuditAction::Update])
            ->withUserId('42')
            ->withTransactionHash('tx-1')
            ->withSince($since)
            ->withUntil($until)
            ->withChangedFields(['status'])
            ->withLimit(15)
            ->withAfterId('after-1');

        self::assertSame('App\\Entity\\User', $state->entityClass);
        self::assertSame('1', $state->entityId);
        self::assertSame([AuditAction::Update], $state->actions);
        self::assertSame('42', $state->userId);
        self::assertSame('tx-1', $state->transactionHash);
        self::assertSame($since, $state->since);
        self::assertSame($until, $state->until);
        self::assertSame(['status'], $state->changedFields);
        self::assertTrue($state->hasChangedFieldFilter());
        self::assertSame(15, $state->limit);
        self::assertSame('after-1', $state->afterId);
        self::assertNull($state->beforeId);
    }

    public function testBeforeAndAfterResetOppositeCursor(): void
    {
        $state = new AuditQueryState(afterId: 'after-1')->withBeforeId('before-1');
        self::assertNull($state->afterId);
        self::assertSame('before-1', $state->beforeId);

        $state = new AuditQueryState(beforeId: 'before-1')->withAfterId('after-1');
        self::assertSame('after-1', $state->afterId);
        self::assertNull($state->beforeId);
    }

    public function testEntityIdUpdatePreservesOtherState(): void
    {
        $original = new AuditQueryState(
            entityClass: 'App\\Entity\\Post',
            actions: [AuditAction::Create],
            limit: 50,
        );

        $updated = $original->withEntityId('99');

        self::assertSame('App\\Entity\\Post', $updated->entityClass);
        self::assertSame('99', $updated->entityId);
        self::assertSame([AuditAction::Create], $updated->actions);
        self::assertSame(50, $updated->limit);
        self::assertNull($original->entityId);
    }
}
