<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;

class AuditRenderer
{
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
    public function buildRow(AuditLog $audit, bool $showDetails): array
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

    public function formatChangedDetails(AuditLog $audit): string
    {
        $oldValues = $audit->getOldValues() ?? [];
        $newValues = $audit->getNewValues() ?? [];
        $changedFields = $audit->getChangedFields() ?? [];

        if ([] === $changedFields && [] === $oldValues && [] === $newValues) {
            return '-';
        }

        $fields = [] !== $changedFields ? $changedFields : array_unique([...array_keys($oldValues), ...array_keys($newValues)]);

        return implode("\n", array_map(function ($field) use ($oldValues, $newValues) {
            $old = $this->formatValue($oldValues[$field] ?? null);
            $new = $this->formatValue($newValues[$field] ?? null);

            return sprintf('%s: %s → %s', $field, $old, $new);
        }, $fields));
    }

    public function formatValue(mixed $value): string
    {
        return match (true) {
            null === $value => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => ($json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false ? $json : '[]',
            default => $this->truncateString((string) $value),
        };
    }

    private function truncateString(string $str): string
    {
        return strlen($str) > 50 ? substr($str, 0, 47).'...' : $str;
    }

    public function shortenClass(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }

    public function shortenHash(?string $hash): string
    {
        return (null !== $hash && '' !== $hash) ? substr($hash, 0, 8) : '-';
    }
}
