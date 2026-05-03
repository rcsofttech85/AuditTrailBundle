<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DataCollector;

use Override;
use Symfony\Bundle\FrameworkBundle\DataCollector\AbstractDataCollector;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

use function count;
use function is_string;

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
        $this->traceableCollector->refreshSnapshots();

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
     * @return list<array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string, ai_namespaces: list<string>, ai_summary: ?string, ai_severity: ?string, ai_anomaly_score: float|int|null, ai_hints: list<string>, ai_tags: list<string>}>
     */
    public function getAudits(): array
    {
        /** @var list<array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string, ai_namespaces: list<string>, ai_summary: ?string, ai_severity: ?string, ai_anomaly_score: float|int|null, ai_hints: list<string>, ai_tags: list<string>}> */
        return $this->data['audits'] ?? [];
    }

    public function getAiAuditCount(): int
    {
        return count(array_filter(
            $this->getAudits(),
            static fn (array $audit): bool => $audit['ai_namespaces'] !== [],
        ));
    }

    /**
     * @return array<string, int>
     */
    public function getAiSeverityBreakdown(): array
    {
        $severities = array_filter(
            array_column($this->getAudits(), 'ai_severity'),
            static fn (mixed $severity): bool => is_string($severity) && $severity !== '',
        );

        return array_count_values($severities);
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
