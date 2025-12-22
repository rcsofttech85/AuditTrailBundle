<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'audit:cleanup',
    description: 'Deletes old audit logs based on retention policy'
)]
final class CleanupAuditLogsCommand extends Command
{
    public function __construct(
        private AuditLogRepository $repository,
        #[Autowire(param: 'audit_trail.retention_days')] private int $retentionDays = 365,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('This command deletes audit logs older than the configured retention period.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $before = new \DateTimeImmutable("-{$this->retentionDays} days");

        $deleted = $this->repository->deleteOldLogs($before);

        $output->writeln(sprintf(
            '<info>Deleted %d audit logs older than %d days.</info>',
            $deleted,
            $this->retentionDays
        ));

        return Command::SUCCESS;
    }
}
