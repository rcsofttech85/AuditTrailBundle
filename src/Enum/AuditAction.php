<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Enum;

use InvalidArgumentException;

use function sprintf;

enum AuditAction: string
{
    case Create = 'create';
    case Update = 'update';
    case Delete = 'delete';
    case SoftDelete = 'soft_delete';
    case Restore = 'restore';
    case Revert = 'revert';
    case Access = 'access';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromScalar(self|string $action): ?self
    {
        return $action instanceof self ? $action : self::tryFrom($action);
    }

    public static function fromScalar(self|string $action): self
    {
        if ($action instanceof self) {
            return $action;
        }

        return self::tryFrom($action)
            ?? throw new InvalidArgumentException(sprintf('Invalid action "%s". Must be one of: %s', $action, implode(', ', self::values())));
    }

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Create',
            self::Update => 'Update',
            self::Delete => 'Delete',
            self::SoftDelete => 'Soft Delete',
            self::Restore => 'Restore',
            self::Revert => 'Revert',
            self::Access => 'Access',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Create => 'success',
            self::Update => 'warning',
            self::Delete, self::SoftDelete => 'danger',
            self::Restore => 'info',
            self::Revert => 'primary',
            self::Access => 'secondary',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Create => 'fa-plus-circle',
            self::Update => 'fa-pencil-alt',
            self::Delete => 'fa-trash-alt',
            self::SoftDelete => 'fa-eye-slash',
            self::Restore => 'fa-undo-alt',
            self::Revert => 'fa-history',
            self::Access => 'fa-eye',
        };
    }

    public function isStateChanging(): bool
    {
        return match ($this) {
            self::Create, self::Update, self::Delete, self::SoftDelete, self::Restore => true,
            self::Revert, self::Access => false,
        };
    }

    public function isUiRevertable(): bool
    {
        return match ($this) {
            self::Create, self::Update, self::SoftDelete => true,
            self::Delete, self::Restore, self::Revert, self::Access => false,
        };
    }
}
