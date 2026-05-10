<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Exception;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditExportInputFactory;
use Rcsofttech\AuditTrailBundle\Service\AuditExportResult;
use Rcsofttech\AuditTrailBundle\Service\AuditExportService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function number_format;
use function sprintf;

#[AsCommand(
    name: 'audit:export',
    description: 'Export audit logs to JSON or CSV format',
)]
final class AuditExportCommand extends Command
{
    public function __construct(
        private readonly AuditExportInputFactory $inputFactory,
        private readonly AuditExportService $exportService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_OPTIONAL,
                'Output format: json or csv',
                'json'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_OPTIONAL,
                'Output file path (defaults to stdout)'
            )
            ->addOption(
                'entity',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter by entity class (partial match)'
            )
            ->addOption(
                'action',
                null,
                InputOption::VALUE_OPTIONAL,
                sprintf('Filter by action (%s)', implode(', ', AuditAction::values()))
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter from date (e.g., "2024-01-01" or "-7 days")'
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_OPTIONAL,
                'Filter to date'
            )
            ->addOption(
                'limit',
                'l',
                InputOption::VALUE_OPTIONAL,
                'Maximum number of results (max: 100000)',
                '1000'
            )
            ->setHelp(
                <<<'HELP'
                    The <info>%command.name%</info> command exports audit logs to JSON or CSV format.

                    Examples:
                      <info>php %command.full_name% --format=json --output=audits.json</info>
                      <info>php %command.full_name% --format=csv --entity="App\Entity\User" --from="2024-01-01"</info>
                      <info>php %command.full_name% -f csv -o audits.csv --limit=5000</info>
                      <info>php %command.full_name% --action=update --from="-30 days"</info>
                    HELP
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $exportInput = $this->inputFactory->create($input, $io);

        if ($exportInput === null) {
            return Command::FAILURE;
        }

        try {
            $result = $this->exportService->export($exportInput);
        } catch (Exception $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($result->count === 0) {
            $io->warning('No audit logs found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->renderResult($io, $result);

        return Command::SUCCESS;
    }

    private function renderResult(SymfonyStyle $io, AuditExportResult $result): void
    {
        if (!$result->writesToFile()) {
            return;
        }

        $io->success(sprintf(
            'Exported %s audit logs to %s (%s)',
            number_format($result->count),
            $result->outputTarget,
            $result->formattedSize ?? '0 B'
        ));
    }
}
