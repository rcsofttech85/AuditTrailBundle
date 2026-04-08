<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Http\AuditRequestAttributes;
use Symfony\Component\HttpFoundation\Request;

use function array_filter;
use function array_map;
use function array_values;
use function in_array;
use function is_bool;
use function is_string;
use function str_contains;
use function strtolower;

final readonly class AuditAccessIntentResolver
{
    private const array ALLOWED_READ_CRUD_ACTIONS = ['detail', 'show', 'view', 'read'];

    private const array BLOCKED_READ_INTENT_KEYWORDS = ['edit', 'update', 'new', 'create', 'delete', 'remove', 'revert'];

    /**
     * @param array<string> $auditedMethods
     */
    public function isExplicitReadIntentRequest(Request $request, array $auditedMethods): bool
    {
        if (!$this->isAuditedRequest($request, $auditedMethods)) {
            return false;
        }

        $explicitIntent = $request->attributes->get(AuditRequestAttributes::ACCESS_INTENT);
        if (is_bool($explicitIntent)) {
            return $explicitIntent;
        }

        $crudActionDecision = $this->resolveCrudActionReadIntent($request);
        if ($crudActionDecision !== null) {
            return $crudActionDecision;
        }

        return !$this->containsBlockedReadIntentSignal($request);
    }

    /**
     * @param array<string> $auditedMethods
     */
    private function isAuditedRequest(Request $request, array $auditedMethods): bool
    {
        $method = $request->getMethod();

        return in_array($method, ['GET', 'HEAD'], true)
            && in_array($method, $auditedMethods, true);
    }

    private function resolveCrudActionReadIntent(Request $request): ?bool
    {
        $crudAction = $request->attributes->get('crudAction');
        if (!is_string($crudAction) || $crudAction === '') {
            return null;
        }

        return in_array(strtolower($crudAction), self::ALLOWED_READ_CRUD_ACTIONS, true);
    }

    private function containsBlockedReadIntentSignal(Request $request): bool
    {
        foreach ($this->collectReadIntentSignals($request) as $signal) {
            if ($this->containsBlockedReadIntentKeyword($signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectReadIntentSignals(Request $request): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): ?string => is_string($value) && $value !== '' ? strtolower($value) : null,
            [
                $request->attributes->get('_route'),
                $request->attributes->get('_controller'),
                $request->attributes->get('_route_params')['action'] ?? null,
            ]
        ), static fn (?string $value): bool => $value !== null));
    }

    private function containsBlockedReadIntentKeyword(string $signal): bool
    {
        return array_any(
            self::BLOCKED_READ_INTENT_KEYWORDS,
            static fn (string $keyword): bool => str_contains($signal, $keyword),
        );
    }
}
