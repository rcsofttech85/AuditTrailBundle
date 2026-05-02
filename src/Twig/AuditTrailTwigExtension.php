<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Twig;

use JsonException;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

use function is_array;
use function is_scalar;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * Twig extension providing rendering helpers for the AuditLog EasyAdmin UI.
 *
 * @codeCoverageIgnore
 */
final class AuditTrailTwigExtension extends AbstractExtension
{
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('audit_action_badge_class', $this->getActionBadgeClass(...)),
            new TwigFunction('audit_action_icon', $this->getActionIcon(...)),
            new TwigFunction('audit_format_json', $this->formatJson(...)),
        ];
    }

    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('audit_short_class', $this->shortenClass(...)),
            new TwigFilter('unique', 'array_unique'),
        ];
    }

    public function getActionBadgeClass(AuditAction|string $action): string
    {
        return AuditAction::tryFromScalar($action)?->badgeClass() ?? 'light';
    }

    public function getActionIcon(AuditAction|string $action): string
    {
        return AuditAction::tryFromScalar($action)?->icon() ?? 'fa-question-circle';
    }

    public function formatJson(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value)) {
            try {
                return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } catch (JsonException) {
                return '[unencodable data]';
            }
        }

        return is_scalar($value) ? (string) $value : '';
    }

    public function shortenClass(string $className): string
    {
        $lastBackslash = mb_strrpos($className, '\\');

        return $lastBackslash === false ? $className : mb_substr($className, $lastBackslash + 1);
    }
}
