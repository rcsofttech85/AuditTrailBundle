<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\DataCollector;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\Service\ResetInterface;

use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Collects audit logs dispatched during the current request.
 *
 * This subscriber listens to AuditLogCreatedEvent and stores
 * serializable summaries for the Symfony Profiler DataCollector.
 */
final class TraceableAuditCollector implements ResetInterface
{
    /** @var list<AuditLog> */
    private array $auditObjects = [];

    /** @var list<array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string, ai_namespaces: list<string>, ai_summary: ?string, ai_severity: ?string, ai_anomaly_score: float|int|null, ai_hints: list<string>, ai_tags: list<string>}> */
    public private(set) array $collectedAudits = [];

    #[AsEventListener(event: AuditLogCreatedEvent::class)]
    public function onAuditLogCreated(AuditLogCreatedEvent $event): void
    {
        $this->auditObjects[] = $event->auditLog;
    }

    /**
     * Rebuild the serializable snapshots from the current AuditLog objects.
     *
     * The event fires before AI enrichment runs, so we defer serialization until
     * profiler collection time to capture the final in-request context state.
     */
    public function refreshSnapshots(): void
    {
        $this->collectedAudits = array_map(
            static fn (AuditLog $audit): array => self::serializeAudit($audit),
            $this->auditObjects,
        );
    }

    public function reset(): void
    {
        $this->auditObjects = [];
        $this->collectedAudits = [];
    }

    /**
     * @return array{entity_class: string, entity_id: string, action: string, changed_fields: list<string>|null, user: string|null, transaction_hash: string|null, created_at: string, ai_namespaces: list<string>, ai_summary: ?string, ai_severity: ?string, ai_anomaly_score: float|int|null, ai_hints: list<string>, ai_tags: list<string>}
     */
    private static function serializeAudit(AuditLog $audit): array
    {
        $aiMetadata = self::extractAiMetadata($audit->context);

        return [
            'entity_class' => $audit->entityClass,
            'entity_id' => $audit->entityId ?? '[unresolved]',
            'action' => $audit->action->value,
            'changed_fields' => $audit->changedFields !== null ? array_values($audit->changedFields) : null,
            'user' => $audit->username ?? $audit->userId,
            'transaction_hash' => $audit->transactionHash,
            'created_at' => $audit->createdAt->format('Y-m-d H:i:s.u'),
            'ai_namespaces' => $aiMetadata['namespaces'],
            'ai_summary' => $aiMetadata['summary'],
            'ai_severity' => $aiMetadata['severity'],
            'ai_anomaly_score' => $aiMetadata['anomaly_score'],
            'ai_hints' => $aiMetadata['anomaly_hints'],
            'ai_tags' => $aiMetadata['tags'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * }
     */
    private static function extractAiMetadata(array $context): array
    {
        $rawAi = $context['ai'] ?? null;

        if (!is_array($rawAi)) {
            return [
                'namespaces' => [],
                'summary' => null,
                'severity' => null,
                'anomaly_score' => null,
                'anomaly_hints' => [],
                'tags' => [],
            ];
        }

        $namespaces = [];
        $summary = null;
        $severity = null;
        $anomalyScore = null;
        $hints = [];
        $tags = [];

        foreach ($rawAi as $namespace => $metadata) {
            if (!is_string($namespace) || !is_array($metadata)) {
                continue;
            }

            $namespaces[] = $namespace;
            $summary ??= self::stringOrNull($metadata['summary'] ?? null);
            $severity ??= self::stringOrNull($metadata['severity'] ?? null);
            $anomalyScore ??= self::numericOrNull($metadata['anomaly_score'] ?? null);

            foreach (self::stringList($metadata['anomaly_hints'] ?? null) as $hint) {
                if (!in_array($hint, $hints, true)) {
                    $hints[] = $hint;
                }
            }

            foreach (self::stringList($metadata['tags'] ?? null) as $tag) {
                if (!in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        return [
            'namespaces' => $namespaces,
            'summary' => $summary,
            'severity' => $severity,
            'anomaly_score' => $anomalyScore,
            'anomaly_hints' => $hints,
            'tags' => $tags,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function numericOrNull(mixed $value): float|int|null
    {
        return is_int($value) || is_float($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
