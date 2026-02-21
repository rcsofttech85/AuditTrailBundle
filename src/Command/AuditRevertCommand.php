<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Exception;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_scalar;
use function is_string;
use function sprintf;

use const JSON_PRETTY_PRINT;

#[AsCommand(
    name: 'audit:revert',
    description: 'Revert an entity change based on an audit log entry',
)]
class AuditRevertCommand extends BaseAuditCommand
{
    public function __construct(
        AuditLogRepository $auditLogRepository,
        private readonly AuditReverterInterface $auditReverter,
    ) {
        parent::__construct($auditLogRepository);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('auditId', InputArgument::REQUIRED, 'The UUID of the audit log entry to revert')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without executing them')
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Allow destructive operations (e.g. reverting a creation)'
            )
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'The user performing the revert action'
            )
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Output raw result data (skip formatting)')
            ->addOption(
                'context',
                null,
                InputOption::VALUE_REQUIRED,
                'Custom context for the revert audit log (JSON string)',
                '{}'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $auditId = $this->parseAuditId($input);
        $context = $this->parseContext($input, $io);

        if ($context === null) {
            return Command::FAILURE;
        }

        $log = $this->fetchAuditLog($auditId, $io);

        if ($log === null) {
            return Command::FAILURE;
        }

        $this->displayRevertHeader($io, $auditId, $log, (bool) $input->getOption('dry-run'));

        return $this->performRevert($io, $input, $log, $context);
    }

    private function displayRevertHeader(SymfonyStyle $io, string $auditId, AuditLog $log, bool $dryRun): void
    {
        $io->title(sprintf('Reverting Audit Log #%s (%s)', $auditId, $log->action));
        $io->text(sprintf('Entity: %s:%s', $log->entityClass, $log->entityId));

        if ($dryRun) {
            $io->note('Running in DRY-RUN mode. No changes will be persisted.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function performRevert(SymfonyStyle $io, InputInterface $input, AuditLog $log, array $context): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');
        $raw = (bool) $input->getOption('raw');
        $user = $input->getOption('user');

        if (is_string($user) && $user !== '') {
            $context[AuditLogInterface::CONTEXT_USERNAME] = $user;
            $context[AuditLogInterface::CONTEXT_USER_ID] = $user;
        }

        try {
            $changes = $this->auditReverter->revert($log, $dryRun, $force, $context);
            $this->displayRevertResult($io, $changes, $raw);

            return Command::SUCCESS;
        } catch (Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function displayRevertResult(SymfonyStyle $io, array $changes, bool $raw): void
    {
        if ($raw) {
            $io->writeln((string) json_encode($changes, JSON_PRETTY_PRINT));

            return;
        }

        if ($changes === []) {
            $io->warning('No changes were applied (values might be identical or fields unmapped).');

            return;
        }

        $io->success('Revert successful.');
        $io->section('Changes Applied:');

        foreach ($changes as $field => $value) {
            $valStr = is_scalar($value) ? (string) $value : json_encode($value);
            $io->writeln(sprintf(' - %s: %s', $field, $valStr));
        }
    }
}
