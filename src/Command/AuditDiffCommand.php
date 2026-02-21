<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use DateTimeInterface;
use Rcsofttech\AuditTrailBundle\Contract\DiffGeneratorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function sprintf;

use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

#[AsCommand(
    name: 'audit:diff',
    description: 'Shows a human-readable diff view for audit logs'
)]
class AuditDiffCommand extends BaseAuditCommand
{
    public function __construct(
        AuditLogRepository $auditLogRepository,
        private readonly DiffGeneratorInterface $diffGenerator,
    ) {
        parent::__construct($auditLogRepository);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Audit Log UUID OR Entity Class')
            ->addArgument('entityId', InputArgument::OPTIONAL, 'Entity ID (if identifier is Entity Class)')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'No normalization')
            ->addOption(
                'include-timestamps',
                null,
                InputOption::VALUE_NONE,
                'Include timestamp fields (createdAt, updatedAt, etc.)'
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output in JSON format');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $log = $this->resolveAuditLog($input, $io);

        if ($log === null) {
            return Command::FAILURE;
        }

        $diff = $this->diffGenerator->generate($log->oldValues, $log->newValues, [
            'raw' => $input->getOption('raw'),
            'include_timestamps' => $input->getOption('include-timestamps'),
        ]);

        if (true === $input->getOption('json')) {
            $json = json_encode($diff, JSON_PRETTY_PRINT);
            $output->writeln($json !== false ? $json : '{}');

            return Command::SUCCESS;
        }

        $this->renderDiff($io, $output, $log, $diff);

        return Command::SUCCESS;
    }

    private function resolveAuditLog(InputInterface $input, SymfonyStyle $io): ?AuditLog
    {
        $identifier = $input->getArgument('identifier');
        $entityId = $input->getArgument('entityId');

        if (!is_string($identifier)) {
            return null;
        }

        $entityId = is_string($entityId) ? $entityId : null;

        return Uuid::isValid($identifier) && $entityId === null
            ? $this->fetchAuditLog($identifier, $io)
            : $this->fetchByEntityClassAndId($identifier, $entityId, $io);
    }

    private function fetchByEntityClassAndId(
        string $entityClass,
        ?string $entityId,
        SymfonyStyle $io,
    ): ?AuditLog {
        if ($entityId === null) {
            $io->error('Entity ID is required when providing an Entity Class.');

            return null;
        }

        $logs = $this->auditLogRepository->findWithFilters(
            ['entityClass' => $entityClass, 'entityId' => $entityId],
            1
        );

        return $logs[0] ?? null;
    }

    /**
     * @param array<string, array{old: mixed, new: mixed}> $diff
     */
    private function renderDiff(SymfonyStyle $io, OutputInterface $output, AuditLog $log, array $diff): void
    {
        $io->title(sprintf('Audit Diff for %s #%s', $log->entityClass, $log->entityId));
        $io->definitionList(
            ['Log ID' => $log->id?->toRfc4122()],
            ['Action' => strtoupper($log->action)],
            ['Date' => $log->createdAt->format('Y-m-d H:i:s')],
            ['User' => $log->username ?? 'System']
        );

        if ($diff === []) {
            $io->info('No semantic changes found.');

            return;
        }

        $table = new Table($output);
        $table->setHeaders(['Field', 'Old Value', 'New Value']);

        foreach ($diff as $field => $values) {
            $table->addRow([
                $field,
                $this->formatValue($values['old']),
                $this->formatValue($values['new']),
            ]);
        }

        $table->render();
    }

    private function formatValue(mixed $value): string
    {
        return match (true) {
            $value === null => '<fg=gray>NULL</>',
            is_bool($value) => $value ? '<fg=green>TRUE</>' : '<fg=red>FALSE</>',
            is_scalar($value) => (string) $value,
            is_array($value) => (($json = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )) !== false ? $json : '[]'),
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            default => get_debug_type($value),
        };
    }
}
