<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use JsonException;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogRepositoryInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

use function count;
use function is_string;
use function sprintf;

use const JSON_THROW_ON_ERROR;

#[AsCommand(
    name: 'audit:verify-integrity',
    description: 'Verifies the integrity of audit logs by checking their signatures.'
)]
final class VerifyIntegrityCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepositoryInterface $repository,
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
            'Verify a specific audit log by UUID'
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
        if (is_string($logId) && $logId !== '') {
            if (!Uuid::isValid($logId)) {
                $io->error('The --id option must be a valid UUID.');

                return Command::FAILURE;
            }

            return $this->verifySingleLog($logId, $io);
        }

        return $this->verifyAllLogs($io, $output);
    }

    private function verifySingleLog(string $id, SymfonyStyle $io): int
    {
        $log = $this->repository->find($id);
        if (!$log instanceof AuditLog) {
            $io->error(sprintf('Audit log with ID %s not found.', $id));

            return Command::FAILURE;
        }

        $io->title(sprintf('Verifying Audit Log #%s', $id));
        $io->writeln(sprintf('Entity: %s [%s]', $log->entityClass, $log->entityId));
        $io->writeln(sprintf('Action: %s', $log->action));
        $io->writeln(sprintf('Created: %s', $log->createdAt->format('Y-m-d H:i:s')));
        $io->newLine();

        if ($this->integrityService->verifySignature($log)) {
            $io->success('Signature verification passed. Log is authentic.');

            return Command::SUCCESS;
        }

        $io->error('Signature verification failed. Log has been tampered with!');

        if ($io->isVeryVerbose()) {
            $io->section('Debug Information');
            $io->writeln('<info>Expected Signature:</info> '.$this->integrityService->generateSignature($log));
            $io->writeln('<info>Actual Signature:  </info> '.$log->signature);
            $io->writeln('<info>Normalized Old Values:</info> '.$this->encodeDebugValue($log->oldValues));
            $io->writeln('<info>Normalized New Values:</info> '.$this->encodeDebugValue($log->newValues));
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
        foreach ($this->repository->findAllWithFilters() as $log) {
            if (!$this->integrityService->verifySignature($log)) {
                $tamperedLogs[] = [
                    'id' => $log->id?->toRfc4122(),
                    'entity' => $log->entityClass,
                    'entity_id' => $log->entityId,
                    'action' => $log->action,
                    'created_at' => $log->createdAt->format('Y-m-d H:i:s'),
                ];
            }

            $progressBar->advance();
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

    private function encodeDebugValue(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[unencodable data]';
        }
    }
}
