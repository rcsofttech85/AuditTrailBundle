<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Enum;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final class AuditActionTest extends TestCase
{
    public function testValuesReturnsAllBackedValues(): void
    {
        self::assertSame(
            ['create', 'update', 'delete', 'soft_delete', 'restore', 'revert', 'access'],
            AuditAction::values(),
        );
    }

    public function testTryFromScalarAcceptsEnumAndString(): void
    {
        self::assertSame(AuditAction::Create, AuditAction::tryFromScalar(AuditAction::Create));
        self::assertSame(AuditAction::Update, AuditAction::tryFromScalar('update'));
        self::assertNull(AuditAction::tryFromScalar('unknown'));
    }

    public function testFromScalarAcceptsEnumAndString(): void
    {
        self::assertSame(AuditAction::Delete, AuditAction::fromScalar(AuditAction::Delete));
        self::assertSame(AuditAction::Restore, AuditAction::fromScalar('restore'));
    }

    public function testFromScalarRejectsInvalidValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid action "unknown". Must be one of: create, update, delete, soft_delete, restore, revert, access');

        AuditAction::fromScalar('unknown');
    }

    #[DataProvider('actionMetadataProvider')]
    public function testActionMetadata(
        AuditAction $action,
        string $label,
        string $badgeClass,
        string $icon,
        bool $stateChanging,
        bool $uiRevertable,
    ): void {
        self::assertSame($label, $action->label());
        self::assertSame($badgeClass, $action->badgeClass());
        self::assertSame($icon, $action->icon());
        self::assertSame($stateChanging, $action->isStateChanging());
        self::assertSame($uiRevertable, $action->isUiRevertable());
    }

    /**
     * @return iterable<string, array{0: AuditAction, 1: string, 2: string, 3: string, 4: bool, 5: bool}>
     */
    public static function actionMetadataProvider(): iterable
    {
        yield 'create' => [AuditAction::Create, 'Create', 'success', 'fa-plus-circle', true, true];
        yield 'update' => [AuditAction::Update, 'Update', 'warning', 'fa-pencil-alt', true, true];
        yield 'delete' => [AuditAction::Delete, 'Delete', 'danger', 'fa-trash-alt', true, false];
        yield 'soft delete' => [AuditAction::SoftDelete, 'Soft Delete', 'danger', 'fa-eye-slash', true, true];
        yield 'restore' => [AuditAction::Restore, 'Restore', 'info', 'fa-undo-alt', true, false];
        yield 'revert' => [AuditAction::Revert, 'Revert', 'primary', 'fa-history', false, false];
        yield 'access' => [AuditAction::Access, 'Access', 'secondary', 'fa-eye', false, false];
    }
}
