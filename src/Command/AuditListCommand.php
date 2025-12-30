<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Service\AuditRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
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
        private readonly AuditRenderer $renderer,
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
            ->addOption('action', null, InputOption::VALUE_OPTIONAL, 'Filter by action')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Filter from date')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Filter to date')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of results', '50')
            ->addOption('details', 'd', InputOption::VALUE_NONE, 'Show detailed old → new value changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limitRaw = $input->getOption('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 50;
        if ($limit < 1 || $limit > 1000) {
            $io->error('Limit must be between 1 and 1000.');

            return Command::FAILURE;
        }

        $action = $input->getOption('action');
        if (
            is_string($action) && '' !== $action && !in_array($action, [
                AuditLog::ACTION_CREATE,
                AuditLog::ACTION_UPDATE,
                AuditLog::ACTION_DELETE,
                AuditLog::ACTION_SOFT_DELETE,
                AuditLog::ACTION_RESTORE,
            ], true)
        ) {
            $io->error('Invalid action specified.');

            return Command::FAILURE;
        }

        try {
            $filters = $this->buildFilters($input);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $audits = $this->repository->findWithFilters($filters, $limit);

        if ([] === $audits) {
            $io->info('No audit logs found matching the criteria.');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Audit Logs (%d results)', count($audits)));
        $this->renderer->renderTable($output, $audits, (bool) $input->getOption('details'));

        if (true !== $input->getOption('details')) {
            $io->note('Tip: Use --details (-d) to see old → new value changes.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(InputInterface $input): array
    {
        $filters = array_filter([
            'entityClass' => $input->getOption('entity'),
            'entityId' => $input->getOption('entity-id'),
            'userId' => (is_string($user = $input->getOption('user')) && '' !== $user) ? (int) $user : null,
            'transactionHash' => $input->getOption('transaction'),
            'action' => $input->getOption('action'),
        ], fn ($v) => null !== $v && '' !== $v);

        foreach (['from', 'to'] as $key) {
            $val = $input->getOption($key);
            if (is_string($val) && '' !== $val) {
                try {
                    $filters[$key] = new \DateTimeImmutable($val);
                } catch (\Exception $e) {
                    throw new \InvalidArgumentException(sprintf('Invalid %s date format: %s', $key, $e->getMessage()));
                }
            }
        }

        return $filters;
    }
}
