<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use DateTimeImmutable;
use Exception;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

#[AsCommand(
    name: 'audit:purge',
    description: 'Delete old audit logs before a given date',
)]
final class AuditPurgeCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuditIntegrityServiceInterface $integrityService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'before',
                null,
                InputOption::VALUE_REQUIRED,
                'Delete logs before this date (e.g., "30 days ago", "2024-01-01", "-1 year")'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show how many logs would be deleted without actually deleting'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Skip confirmation prompt'
            )
            ->addOption(
                'skip-integrity',
                null,
                InputOption::VALUE_NONE,
                'Skip integrity verification before purging (not recommended)'
            )
            ->setHelp(
                <<<'HELP'
                    The <info>%command.name%</info> command deletes old audit logs.

                    Examples:
                      <info>php %command.full_name% --before="30 days ago" --dry-run</info>
                      <info>php %command.full_name% --before="2024-01-01" --force</info>
                      <info>php %command.full_name% --before="1 year ago"</info>
                    HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Parse and validate the date
        $before = $this->parseBeforeDate($input, $io);
        if ($before === null) {
            return Command::FAILURE;
        }

        // Count logs that will be deleted
        $count = $this->repository->countOlderThan($before);

        if ($count === 0) {
            $io->info(sprintf('No audit logs found before %s.', $before->format('Y-m-d H:i:s')));

            return Command::SUCCESS;
        }

        // Display summary
        $this->displaySummary($io, $count, $before);

        // Handle dry-run mode
        if ((bool) $input->getOption('dry-run')) {
            $io->warning('Dry run mode - no logs were deleted.');

            return Command::SUCCESS;
        }

        // Confirm deletion
        if (!$this->confirmDeletion($input, $io, $count)) {
            $io->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        // Integrity check before deletion
        if (!((bool) $input->getOption('skip-integrity')) && $this->integrityService->isEnabled()) {
            $tamperedCount = $this->verifyIntegrityBeforePurge($io, $before);
            if ($tamperedCount > 0) {
                $io->error(sprintf(
                    'Aborting purge: %d tampered log(s) detected. Run "audit:verify-integrity" for details, or use --skip-integrity to force.',
                    $tamperedCount,
                ));

                return Command::FAILURE;
            }
        }

        // Perform deletion
        $io->section('Deleting audit logs...');
        $deleted = $this->repository->deleteOldLogs($before);

        $io->success(sprintf('Successfully deleted %s audit logs.', number_format($deleted)));

        return Command::SUCCESS;
    }

    private function parseBeforeDate(InputInterface $input, SymfonyStyle $io): ?DateTimeImmutable
    {
        $beforeStr = $input->getOption('before');

        if (!is_string($beforeStr) || $beforeStr === '') {
            $io->error('The --before option is required.');
            $io->note('Example: --before="30 days ago"');
            $io->note('Valid formats: "30 days ago", "2024-01-01", "-1 year", "last month"');

            return null;
        }

        try {
            return new DateTimeImmutable($beforeStr);
        } catch (Exception $e) {
            $io->error(sprintf('Invalid date format: %s', $beforeStr));
            $io->note(sprintf('Error: %s', $e->getMessage()));
            $io->note('Valid formats: "30 days ago", "2024-01-01", "-1 year", "last month"');

            return null;
        }
    }

    private function displaySummary(SymfonyStyle $io, int $count, DateTimeImmutable $before): void
    {
        $io->section('Purge Summary');
        $io->table(
            ['Setting', 'Value'],
            [
                ['Records to delete', number_format($count)],
                ['Before date', $before->format('Y-m-d H:i:s')],
            ]
        );
    }

    private function confirmDeletion(InputInterface $input, SymfonyStyle $io, int $count): bool
    {
        // Skip confirmation if --force is used
        if ((bool) $input->getOption('force')) {
            return true;
        }

        // Warn for large operations
        if ($count > 10000) {
            $io->warning(sprintf(
                'You are about to delete %s audit logs. This is a large operation.',
                number_format($count)
            ));
        }

        return $io->confirm(
            sprintf('Are you sure you want to delete %s audit logs?', number_format($count)),
            false
        );
    }

    private function verifyIntegrityBeforePurge(SymfonyStyle $io, DateTimeImmutable $before): int
    {
        $io->section('Verifying integrity of logs before purge...');
        $logs = $this->repository->findOlderThan($before);

        $tamperedCount = 0;
        foreach ($logs as $log) {
            if (!$this->integrityService->verifySignature($log)) {
                ++$tamperedCount;
                $io->warning(sprintf('Tampered log: %s', (string) $log->id));
            }
        }

        if ($tamperedCount === 0) {
            $io->info('All logs passed integrity verification.');
        }

        return $tamperedCount;
    }
}
