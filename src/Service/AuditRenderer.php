<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class AuditRenderer
{
    use ClassNameHelperTrait;

    /**
     * @param array<AuditLogInterface> $audits
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
    public function buildRow(AuditLogInterface $audit, bool $showDetails): array
    {
        $user = $audit->getUsername() ?? (string) $audit->getUserId();
        if ('' === $user) {
            $user = '-';
        }
        $hash = $this->shortenHash($audit->getTransactionHash());
        $date = $audit->getCreatedAt()->format('Y-m-d H:i:s');

        if ($showDetails) {
            return [
                $audit->getEntityId(),
                $audit->getAction(),
                $user,
                $hash,
                $this->formatChangedDetails($audit),
                $date,
            ];
        }

        return [
            $audit->getId(),
            $this->shortenClass($audit->getEntityClass()),
            $audit->getEntityId(),
            $audit->getAction(),
            $user,
            $hash,
            $date,
        ];
    }

    public function formatChangedDetails(AuditLogInterface $audit): string
    {
        $old = (array) $audit->getOldValues();
        $new = (array) $audit->getNewValues();
        $changed = (array) $audit->getChangedFields();

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
        return [] === $old && [] === $new && [] === $changed;
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
        if ([] !== $changedFields) {
            return $changedFields;
        }

        return array_unique([...array_keys($oldValues), ...array_keys($newValues)]);
    }

    public function formatValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return $this->formatArrayValue($value);
        }

        return $this->truncateString((string) $value);
    }

    /**
     * @param array<string, mixed> $value
     */
    private function formatArrayValue(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return false !== $json ? $json : '[]';
    }

    private function truncateString(string $str): string
    {
        return strlen($str) > 50 ? substr($str, 0, 47) . '...' : $str;
    }

    public function shortenHash(?string $hash): string
    {
        return (null !== $hash && '' !== $hash) ? substr($hash, 0, 8) : '-';
    }
}
