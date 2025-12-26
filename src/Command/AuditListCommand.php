<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:list',
    description: 'List audit logs with optional filters',
)]
final class AuditListCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_OPTIONAL, 'Filter by entity class (partial match)')
            ->addOption('entity-id', null, InputOption::VALUE_OPTIONAL, 'Filter by entity ID')
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'Filter by user ID')
            ->addOption('transaction', 't', InputOption::VALUE_OPTIONAL, 'Filter by transaction hash')
            ->addOption('action', null, InputOption::VALUE_OPTIONAL, sprintf(
                'Filter by action (%s)',
                implode(', ', $this->getAvailableActions())
            ))
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Filter from date (e.g., "2024-01-01" or "-7 days")')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Filter to date')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of results', '50')
            ->addOption('details', 'd', InputOption::VALUE_NONE, 'Show detailed old → new value changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $filters = $this->buildFilters($input, $io);
        if (null === $filters) {
            return Command::FAILURE;
        }

        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : 50;

        if ($limit < 1 || $limit > 1000) {
            $io->error('Limit must be between 1 and 1000');

            return Command::FAILURE;
        }

        /** @var array<AuditLog> $audits */
        $audits = $this->repository->findWithFilters($filters, $limit);

        if ([] === $audits) {
            $io->info('No audit logs found matching the criteria.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Audit Logs (%d results)', count($audits)));

        $showDetails = (bool) $input->getOption('details');
        $this->renderTable($output, $audits, $showDetails);

        if (!$showDetails) {
            $io->note('Tip: Use --details (-d) to see old → new value changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array{entityClass?: string, entityId?: string, userId?: int, action?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable}|null
     */
    private function buildFilters(InputInterface $input, SymfonyStyle $io): ?array
    {
        $filters = [];

        if ($entity = $input->getOption('entity')) {
            if (is_string($entity)) {
                $filters['entityClass'] = $entity;
            }
        }

        if ($entityId = $input->getOption('entity-id')) {
            if (is_string($entityId)) {
                $filters['entityId'] = $entityId;
            }
        }

        if ($user = $input->getOption('user')) {
            $filters['userId'] = (int) $user;
        }

        if ($transaction = $input->getOption('transaction')) {
            if (is_string($transaction)) {
                $filters['transactionHash'] = $transaction;
            }
        }

        if ($action = $input->getOption('action')) {
            if (is_string($action)) {
                $availableActions = $this->getAvailableActions();
                if (!in_array($action, $availableActions, true)) {
                    $io->error(sprintf(
                        'Invalid action "%s". Available actions: %s',
                        $action,
                        implode(', ', $availableActions)
                    ));

                    return null;
                }
                $filters['action'] = $action;
            }
        }

        if ($from = $input->getOption('from')) {
            if (is_string($from)) {
                try {
                    $filters['from'] = new \DateTimeImmutable($from);
                } catch (\Exception $e) {
                    $io->error(sprintf('Invalid "from" date: %s. Error: %s', $from, $e->getMessage()));

                    return null;
                }
            }
        }

        if ($to = $input->getOption('to')) {
            if (is_string($to)) {
                try {
                    $filters['to'] = new \DateTimeImmutable($to);
                } catch (\Exception $e) {
                    $io->error(sprintf('Invalid "to" date: %s. Error: %s', $to, $e->getMessage()));

                    return null;
                }
            }
        }

        return $filters;
    }

    /**
     * @param array<AuditLog> $audits
     */
    private function renderTable(OutputInterface $output, array $audits, bool $showDetails = false): void
    {
        $table = new Table($output);

        if ($showDetails) {
            $table->setHeaders(['Entity ID', 'Action', 'User', 'Tx Hash', 'Changed Details', 'Created At']);
        } else {
            $table->setHeaders(['ID', 'Entity', 'Entity ID', 'Action', 'User', 'Tx Hash', 'Created At']);
        }

        foreach ($audits as $audit) {
            if ($showDetails) {
                $row = [
                    $audit->getEntityId(),
                    $audit->getAction(),
                    $audit->getUsername() ?? $audit->getUserId() ?? '-',
                    $this->shortenHash($audit->getTransactionHash()),
                    $this->formatChangedDetails($audit),
                    $audit->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            } else {
                $row = [
                    $audit->getId(),
                    $this->shortenClass($audit->getEntityClass()),
                    $audit->getEntityId(),
                    $audit->getAction(),
                    $audit->getUsername() ?? $audit->getUserId() ?? '-',
                    $this->shortenHash($audit->getTransactionHash()),
                    $audit->getCreatedAt()->format('Y-m-d H:i:s'),
                ];
            }

            $table->addRow($row);
        }

        $table->render();
    }

    private function formatChangedDetails(AuditLog $audit): string
    {
        $oldValues = $audit->getOldValues() ?? [];
        $newValues = $audit->getNewValues() ?? [];
        $changedFields = $audit->getChangedFields() ?? [];

        if ([] === $changedFields && [] === $oldValues && [] === $newValues) {
            return '-';
        }

        $details = [];

        // Use changedFields if available, otherwise merge keys from oldValues and newValues
        $fields = !empty($changedFields) ? $changedFields : array_unique(array_merge(array_keys($oldValues), array_keys($newValues)));

        foreach ($fields as $field) {
            $old = $oldValues[$field] ?? null;
            $new = $newValues[$field] ?? null;

            $oldStr = $this->formatValue($old);
            $newStr = $this->formatValue($new);

            $details[] = sprintf('%s: %s → %s', $field, $oldStr, $newStr);
        }

        return implode("\n", $details);
    }

    private function formatValue(mixed $value): string
    {
        if (null === $value) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
        }

        $strValue = (string) $value;

        // Truncate long values
        if (strlen($strValue) > 50) {
            return substr($strValue, 0, 47).'...';
        }

        return $strValue;
    }

    /**
     * @return array<string>
     */
    private function getAvailableActions(): array
    {
        return [
            AuditLog::ACTION_CREATE,
            AuditLog::ACTION_UPDATE,
            AuditLog::ACTION_DELETE,
            AuditLog::ACTION_SOFT_DELETE,
            AuditLog::ACTION_RESTORE,
        ];
    }

    private function shortenClass(string $class): string
    {
        return basename(str_replace('\\', '/', $class));
    }

    private function shortenHash(?string $hash): string
    {
        if (null === $hash) {
            return '-';
        }

        return substr($hash, 0, 8);
    }
}
