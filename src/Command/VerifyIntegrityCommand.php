<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function sprintf;

#[AsCommand(
    name: 'audit:verify-integrity',
    description: 'Verifies the integrity of audit logs by checking their signatures.'
)]
final class VerifyIntegrityCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuditIntegrityServiceInterface $integrityService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'id',
            null,
            InputOption::VALUE_REQUIRED,
            'Verify a specific audit log by ID'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->integrityService->isEnabled()) {
            $io->error('Audit integrity is not enabled in the configuration.');

            return Command::FAILURE;
        }

        $logId = $input->getOption('id');
        if (is_numeric($logId)) {
            return $this->verifySingleLog((int) $logId, $io);
        }

        return $this->verifyAllLogs($io, $output);
    }

    private function verifySingleLog(int $id, SymfonyStyle $io): int
    {
        $log = $this->repository->find($id);
        if ($log === null) {
            $io->error(sprintf('Audit log with ID %d not found.', $id));

            return Command::FAILURE;
        }

        $io->title(sprintf('Verifying Audit Log #%d', $id));
        $io->writeln(sprintf('Entity: %s [%s]', $log->getEntityClass(), $log->getEntityId()));
        $io->writeln(sprintf('Action: %s', $log->getAction()));
        $io->writeln(sprintf('Created: %s', $log->getCreatedAt()->format('Y-m-d H:i:s')));
        $io->newLine();

        if ($this->integrityService->verifySignature($log)) {
            $io->success('Signature verification passed. Log is authentic.');

            return Command::SUCCESS;
        }

        $io->error('Signature verification failed. Log has been tampered with!');

        if ($io->isVeryVerbose()) {
            $io->section('Debug Information');
            $io->writeln('<info>Expected Signature:</info> '.$this->integrityService->generateSignature($log));
            $io->writeln('<info>Actual Signature:  </info> '.$log->getSignature());
            $io->writeln('<info>Normalized Old Values:</info> '.json_encode($log->getOldValues()));
            $io->writeln('<info>Normalized New Values:</info> '.json_encode($log->getNewValues()));
        }

        return Command::FAILURE;
    }

    private function verifyAllLogs(SymfonyStyle $io, OutputInterface $output): int
    {
        $count = $this->repository->count([]);
        if ($count === 0) {
            $io->success('No audit logs found to verify.');

            return Command::SUCCESS;
        }

        $io->title('Verifying Audit Log Integrity');
        $progressBar = new ProgressBar($output, $count);
        $progressBar->start();

        $tamperedLogs = [];
        $batchSize = 100;
        $offset = 0;

        while ($offset < $count) {
            $logs = $this->repository->findBy([], ['id' => 'ASC'], $batchSize, $offset);
            foreach ($logs as $log) {
                if (!$this->integrityService->verifySignature($log)) {
                    $tamperedLogs[] = [
                        'id' => $log->getId(),
                        'entity' => $log->getEntityClass(),
                        'entity_id' => $log->getEntityId(),
                        'action' => $log->getAction(),
                        'created_at' => $log->getCreatedAt()->format('Y-m-d H:i:s'),
                    ];
                }
                $progressBar->advance();
            }
            $offset += $batchSize;
        }

        $progressBar->finish();
        $io->newLine(2);

        if ($tamperedLogs === []) {
            $io->success(sprintf('All %d audit logs verified successfully.', $count));

            return Command::SUCCESS;
        }

        $io->error(sprintf('Found %d tampered audit logs!', count($tamperedLogs)));
        $io->table(
            ['ID', 'Entity', 'Entity ID', 'Action', 'Created At'],
            $tamperedLogs
        );

        return Command::FAILURE;
    }
}
