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
    private const array DEFAULT_AI_NAMESPACE_METADATA = [
        'summary' => null,
        'severity' => null,
        'anomaly_score' => null,
        'anomaly_hints' => null,
        'tags' => null,
    ];

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
        $snapshots = [];

        foreach ($this->auditObjects as $audit) {
            $snapshots[] = self::serializeAudit($audit);
        }

        $this->collectedAudits = $snapshots;
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
        $metadata = self::emptyAiMetadata();
        if (!is_array($rawAi)) {
            return $metadata;
        }

        foreach ($rawAi as $namespace => $namespaceMetadata) {
            if (!is_string($namespace) || !is_array($namespaceMetadata)) {
                continue;
            }

            $metadata = self::mergeAiNamespaceMetadata($metadata, $namespace, $namespaceMetadata);
        }

        return $metadata;
    }

    /**
     * @return array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * }
     */
    private static function emptyAiMetadata(): array
    {
        return [
            'namespaces' => [],
            'summary' => null,
            'severity' => null,
            'anomaly_score' => null,
            'anomaly_hints' => [],
            'tags' => [],
        ];
    }

    /**
     * @param array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * } $aggregatedMetadata
     * @param array<string, mixed> $namespaceMetadata
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
    private static function mergeAiNamespaceMetadata(array $aggregatedMetadata, string $namespace, array $namespaceMetadata): array
    {
        $normalizedMetadata = self::normalizeAiNamespaceMetadata($namespaceMetadata);
        $aggregatedMetadata = self::withNamespace($aggregatedMetadata, $namespace);
        $aggregatedMetadata = self::withSummary($aggregatedMetadata, $normalizedMetadata['summary']);
        $aggregatedMetadata = self::withSeverity($aggregatedMetadata, $normalizedMetadata['severity']);
        $aggregatedMetadata = self::withAnomalyScore($aggregatedMetadata, $normalizedMetadata['anomaly_score']);
        self::mergeUniqueStrings($aggregatedMetadata['anomaly_hints'], $normalizedMetadata['anomaly_hints']);
        self::mergeUniqueStrings($aggregatedMetadata['tags'], $normalizedMetadata['tags']);

        return $aggregatedMetadata;
    }

    /**
     * @param array<string, mixed> $namespaceMetadata
     *
     * @return array{
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * }
     */
    private static function normalizeAiNamespaceMetadata(array $namespaceMetadata): array
    {
        $namespaceMetadata = [
            ...self::DEFAULT_AI_NAMESPACE_METADATA,
            ...$namespaceMetadata,
        ];

        return [
            'summary' => self::stringOrNull($namespaceMetadata['summary']),
            'severity' => self::stringOrNull($namespaceMetadata['severity']),
            'anomaly_score' => self::numericOrNull($namespaceMetadata['anomaly_score']),
            'anomaly_hints' => self::stringList($namespaceMetadata['anomaly_hints']),
            'tags' => self::stringList($namespaceMetadata['tags']),
        ];
    }

    /**
     * @param array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * } $aggregatedMetadata
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
    private static function withNamespace(array $aggregatedMetadata, string $namespace): array
    {
        $aggregatedMetadata['namespaces'][] = $namespace;

        return $aggregatedMetadata;
    }

    /**
     * @param array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * } $aggregatedMetadata
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
    private static function withSummary(array $aggregatedMetadata, mixed $value): array
    {
        if ($aggregatedMetadata['summary'] === null) {
            $aggregatedMetadata['summary'] = self::stringOrNull($value);
        }

        return $aggregatedMetadata;
    }

    /**
     * @param array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * } $aggregatedMetadata
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
    private static function withSeverity(array $aggregatedMetadata, mixed $value): array
    {
        if ($aggregatedMetadata['severity'] === null) {
            $aggregatedMetadata['severity'] = self::stringOrNull($value);
        }

        return $aggregatedMetadata;
    }

    /**
     * @param array{
     *   namespaces: list<string>,
     *   summary: ?string,
     *   severity: ?string,
     *   anomaly_score: float|int|null,
     *   anomaly_hints: list<string>,
     *   tags: list<string>
     * } $aggregatedMetadata
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
    private static function withAnomalyScore(array $aggregatedMetadata, mixed $value): array
    {
        if ($aggregatedMetadata['anomaly_score'] === null) {
            $aggregatedMetadata['anomaly_score'] = self::numericOrNull($value);
        }

        return $aggregatedMetadata;
    }

    /**
     * @param list<string> $target
     * @param list<string> $values
     */
    private static function mergeUniqueStrings(array &$target, array $values): void
    {
        foreach ($values as $value) {
            if (!in_array($value, $target, true)) {
                $target[] = $value;
            }
        }
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
