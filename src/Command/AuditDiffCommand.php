<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

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

#[AsCommand(
    name: 'audit:diff',
    description: 'Shows a human-readable diff view for audit logs'
)]
class AuditDiffCommand extends Command
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        private readonly DiffGeneratorInterface $diffGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'Audit Log ID OR Entity Class')
            ->addArgument('entityId', InputArgument::OPTIONAL, 'Entity ID (if identifier is Entity Class)')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'No normalization')
            ->addOption('include-timestamps', null, InputOption::VALUE_NONE, 'Include timestamp fields (createdAt, updatedAt, etc.)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output in JSON format')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $identifier = $input->getArgument('identifier');
        $entityId = $input->getArgument('entityId');

        $auditLogs = [];

        if (is_numeric($identifier) && null === $entityId) {
            $log = $this->auditLogRepository->find((int) $identifier);
            if ($log instanceof AuditLog) {
                $auditLogs[] = $log;
            }
        } else {
            if (null === $entityId) {
                $io->error('Entity ID is required when providing an Entity Class.');

                return Command::FAILURE;
            }

            $auditLogs = $this->auditLogRepository->findWithFilters(
                ['entityClass' => $identifier, 'entityId' => $entityId],
                1
            );
        }

        if ([] === $auditLogs) {
            $io->error('No audit log found.');

            return Command::FAILURE;
        }

        /** @var AuditLog $log */
        $log = $auditLogs[0];

        $options = [
            'raw' => $input->getOption('raw'),
            'include_timestamps' => $input->getOption('include-timestamps'),
        ];

        $diff = $this->diffGenerator->generate($log->getOldValues(), $log->getNewValues(), $options);

        if (true === $input->getOption('json')) {
            $json = json_encode($diff, JSON_PRETTY_PRINT);
            $output->writeln(false !== $json ? $json : '{}');

            return Command::SUCCESS;
        }

        $io->title(sprintf('Audit Diff for %s #%s', $log->getEntityClass(), $log->getEntityId()));
        $io->definitionList(
            ['Log ID' => $log->getId()],
            ['Action' => strtoupper($log->getAction())],
            ['Date' => $log->getCreatedAt()->format('Y-m-d H:i:s')],
            ['User' => $log->getUsername() ?? 'System']
        );

        if ([] === $diff) {
            $io->info('No semantic changes found.');

            return Command::SUCCESS;
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

        return Command::SUCCESS;
    }

    private function formatValue(mixed $value): string
    {
        if (null === $value) {
            return '<fg=gray>NULL</>';
        }

        if (is_bool($value)) {
            return $value ? '<fg=green>TRUE</>' : '<fg=red>FALSE</>';
        }

        return (string) $value;
    }
}
