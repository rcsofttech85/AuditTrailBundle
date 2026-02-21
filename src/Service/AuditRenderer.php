<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditRendererInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Stringable;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

use function is_array;
use function is_bool;
use function is_scalar;
use function mb_strlen;
use function mb_substr;
use function sprintf;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final readonly class AuditRenderer implements AuditRendererInterface
{
    use ClassNameHelperTrait;

    /**
     * @param array<AuditLog> $audits
     */
    public function renderTable(OutputInterface $output, array $audits, bool $showDetails): void
    {
        $table = new Table($output);
        $headers = $showDetails
            ? ['Entity ID', 'Action', 'User', 'Tx Hash', 'Changed Details', 'Created At']
            : ['ID', 'Entity', 'Entity ID', 'Action', 'User', 'Tx Hash', 'Created At'];

        $table->setHeaders($headers);

        foreach ($audits as $audit) {
            $table->addRow($this->buildRow($audit, $showDetails));
        }

        $table->render();
    }

    /**
     * @return array<int, mixed>
     */
    #[Override]
    public function buildRow(AuditLog $audit, bool $showDetails): array
    {
        $user = $audit->username ?? (string) $audit->userId;
        if ($user === '') {
            $user = '-';
        }
        $hash = $this->shortenHash($audit->transactionHash);
        $date = $audit->createdAt->format('Y-m-d H:i:s');

        if ($showDetails) {
            return [
                $audit->entityId,
                $audit->action,
                $user,
                $hash,
                $this->formatChangedDetails($audit),
                $date,
            ];
        }

        return [
            $audit->id?->toRfc4122(),
            $this->shortenClass($audit->entityClass),
            $audit->entityId,
            $audit->action,
            $user,
            $hash,
            $date,
        ];
    }

    #[Override]
    public function formatChangedDetails(AuditLog $audit): string
    {
        $old = (array) $audit->oldValues;
        $new = (array) $audit->newValues;
        $changed = (array) $audit->changedFields;

        if ($this->isEmptyAudit($old, $new, $changed)) {
            return '-';
        }

        $fields = $this->determineFieldsToShow($changed, $old, $new);

        return $this->formatFields($fields, $old, $new);
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param array<int, string>   $changed
     */
    private function isEmptyAudit(array $old, array $new, array $changed): bool
    {
        return $old === [] && $new === [] && $changed === [];
    }

    /**
     * @param array<int, string>   $fields
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function formatFields(array $fields, array $oldValues, array $newValues): string
    {
        $lines = [];
        foreach ($fields as $field) {
            $lines[] = $this->formatFieldLine($field, $oldValues, $newValues);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     */
    private function formatFieldLine(string $field, array $oldValues, array $newValues): string
    {
        $old = $this->formatValue($oldValues[$field] ?? null);
        $new = $this->formatValue($newValues[$field] ?? null);

        return sprintf('%s: %s → %s', $field, $old, $new);
    }

    /**
     * @param array<int, string>   $changedFields
     * @param array<string, mixed> $oldValues
     * @param array<string, mixed> $newValues
     *
     * @return array<int, string>
     */
    private function determineFieldsToShow(array $changedFields, array $oldValues, array $newValues): array
    {
        if ($changedFields !== []) {
            return $changedFields;
        }

        return array_unique([...array_keys($oldValues), ...array_keys($newValues)]);
    }

    #[Override]
    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return $this->formatArrayValue($value);
        }

        if (is_scalar($value) || $value instanceof Stringable) {
            return $this->truncateString((string) $value);
        }

        return $this->truncateString(get_debug_type($value));
    }

    /**
     * @param array<mixed> $value
     */
    private function formatArrayValue(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json !== false ? $json : '[]';
    }

    private function truncateString(string $str): string
    {
        // Strip ANSI escape sequences to prevent terminal injection
        $str = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $str) ?? $str;

        return mb_strlen($str) > 50 ? mb_substr($str, 0, 47).'...' : $str;
    }

    public function shortenHash(?string $hash): string
    {
        return ($hash !== null && $hash !== '') ? substr($hash, 0, 8) : '-';
    }
}
