<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\ValueObject;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\ValueObject\RevertPlan;

final class RevertPlanTest extends TestCase
{
    public function testFromChangesCreatesPlan(): void
    {
        $plan = RevertPlan::fromChanges(['name' => 'before']);

        self::assertSame(['name' => 'before'], $plan->changes);
        self::assertTrue($plan->previousValues === []);
        self::assertTrue($plan->fieldValues === []);
    }

    public function testForFieldChangesCreatesPlan(): void
    {
        $plan = RevertPlan::forFieldChanges(
            ['name' => 'before'],
            ['name' => 'after'],
            ['name' => 'before'],
        );

        self::assertSame(['name' => 'before'], $plan->changes);
        self::assertSame(['name' => 'after'], $plan->previousValues);
        self::assertSame(['name' => 'before'], $plan->fieldValues);
    }

    public function testIsDeleteActionChecksBackedValue(): void
    {
        self::assertTrue(new RevertPlan(['action' => AuditAction::Delete->value])->isDeleteAction());
        self::assertFalse(new RevertPlan(['action' => AuditAction::Update->value])->isDeleteAction());
    }

    public function testIsEmptyReflectsAllState(): void
    {
        self::assertTrue(new RevertPlan([])->isEmpty());
        self::assertFalse(new RevertPlan([], restoreSoftDelete: true)->isEmpty());
        self::assertFalse(new RevertPlan(['field' => 'value'])->isEmpty());
    }

    public function testToLegacyArrayNormalizesEnumActionAndKeepsOptionalFields(): void
    {
        $plan = new RevertPlan(
            ['action' => AuditAction::Delete, 'name' => 'before'],
            ['name' => 'after'],
            ['name' => 'before'],
            true,
        );

        self::assertSame(
            [
                'changes' => ['action' => 'delete', 'name' => 'before'],
                'previousValues' => ['name' => 'after'],
                'fieldValues' => ['name' => 'before'],
                'restoreSoftDelete' => true,
            ],
            $plan->toLegacyArray(),
        );
    }

    public function testToLegacyArrayOmitsEmptyOptionalFields(): void
    {
        $plan = new RevertPlan(['name' => 'before']);

        self::assertSame(['changes' => ['name' => 'before']], $plan->toLegacyArray());
    }
}
