<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Field;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Stringable;
use Symfony\Contracts\Translation\TranslatableInterface;

use function is_scalar;
use function is_string;

final class AuditActionField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName = 'action', TranslatableInterface|string|bool|null $label = null): self
    {
        return new self()
            ->setFieldFqcn(self::class)
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('@AuditTrail/admin/field/audit_action.html.twig')
            ->setDefaultColumns('col-md-6 col-xxl-5')
            ->formatValue(static fn (mixed $value): string => self::formatLabel($value));
    }

    private static function formatLabel(mixed $value): string
    {
        return self::resolveAction($value)?->label() ?? (string) (is_scalar($value) || $value instanceof Stringable ? $value : '');
    }

    private static function resolveAction(mixed $value): ?AuditAction
    {
        if ($value instanceof AuditAction) {
            return $value;
        }

        return is_string($value) ? AuditAction::tryFrom($value) : null;
    }
}
