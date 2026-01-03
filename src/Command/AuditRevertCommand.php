<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:revert',
    description: 'Revert an entity change based on an audit log entry',
)]
class AuditRevertCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly AuditReverterInterface $auditReverter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('auditId', InputArgument::REQUIRED, 'The ID of the audit log entry to revert')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without executing them')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Allow destructive operations (e.g. reverting a creation)'
            )
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output raw result data (skip formatting)')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Custom context for the revert audit log (JSON string)', '{}')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $auditIdInput = $input->getArgument('auditId');
        $auditId = filter_var($auditIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (false === $auditId) {
            throw new \InvalidArgumentException('auditId must be a valid audit log ID');
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $raw = (bool) $input->getOption('raw');
        $contextString = (string) $input->getOption('context');



        $context = [];
        if ('{}' !== $contextString && '' !== $contextString) {
            try {
                $context = json_decode($contextString, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($context)) {
                    throw new \InvalidArgumentException('Context must be a valid JSON object (array).');
                }
            } catch (\JsonException $e) {
                $io->error(sprintf('Invalid JSON context: %s', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        $log = $this->auditLogRepository->find($auditId);

        if (!$log instanceof AuditLogInterface) {
            $io->error(sprintf('Audit log with ID %d not found.', $auditId));

            return Command::FAILURE;
        }

        $io->title(sprintf('Reverting Audit Log #%d (%s)', $auditId, $log->getAction()));
        $io->text(sprintf('Entity: %s:%s', $log->getEntityClass(), $log->getEntityId()));

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode. No changes will be persisted.');
        }

        try {
            $changes = $this->auditReverter->revert($log, $dryRun, $force, $context);

            if ($raw) {
                $io->writeln((string) json_encode($changes, JSON_PRETTY_PRINT));
            } else {
                if ([] === $changes) {
                    $io->warning('No changes were applied (values might be identical or fields unmapped).');
                } else {
                    $io->success('Revert successful.');
                    $io->section('Changes Applied:');
                    foreach ($changes as $field => $value) {
                        $valStr = is_scalar($value) ? (string) $value : json_encode($value);
                        $io->writeln(sprintf(' - %s: %s', $field, $valStr));
                    }
                }
            }

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}
