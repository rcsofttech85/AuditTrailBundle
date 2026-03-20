<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DataCollector;

use Override;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function count;

/**
 * Symfony Profiler DataCollector for the AuditTrailBundle.
 *
 * Displays audit log activity in the web debug toolbar and profiler panel.
 */
final class AuditDataCollector extends AbstractDataCollector
{
    public function __construct(
        private readonly TraceableAuditCollector $traceableCollector,
    ) {
    }

    #[Override]
    public function collect(Request $request, Response $response, ?Throwable $exception = null): void
    {
        $this->data = [
            'audits' => $this->traceableCollector->collectedAudits,
        ];
    }

    public static function getTemplate(): string
    {
        return '@AuditTrail/Collector/audit.html.twig';
    }

    public function getAuditCount(): int
    {
        return count($this->getAudits());
    }

    /**
     * @return list<array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string}>
     */
    public function getAudits(): array
    {
        /** @var list<array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string}> */
        return $this->data['audits'] ?? [];
    }

    /**
     * @return array<string, int>
     */
    public function getActionBreakdown(): array
    {
        $actions = array_column($this->getAudits(), 'action');

        return array_count_values($actions);
    }

    #[Override]
    public function getName(): string
    {
        return 'audit_trail';
    }
}
