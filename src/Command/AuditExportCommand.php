<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use DateTimeImmutable;
use Exception;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Service\AuditExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function dirname;
use function in_array;
use function is_string;
use function sprintf;
use function strlen;

#[AsCommand(
    name: 'audit:export',
    description: 'Export audit logs to JSON or CSV format',
)]
final class AuditExportCommand extends Command
{
    private const string FORMAT_JSON = 'json';

    private const string FORMAT_CSV = 'csv';

    private const array VALID_FORMATS = [self::FORMAT_JSON, self::FORMAT_CSV];

    private const int DEFAULT_LIMIT = 1000;

    private const int MAX_LIMIT = 100000;

    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuditExporter $exporter,
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
                sprintf('Output format: %s', implode(' or ', self::VALID_FORMATS)),
                self::FORMAT_JSON
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
                sprintf('Filter by action (%s)', implode(', ', $this->getAvailableActions()))
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
                sprintf('Maximum number of results (max: %d)', self::MAX_LIMIT),
                (string) self::DEFAULT_LIMIT
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

        $format = $this->parseFormat($input, $io);
        $limit = $this->parseLimit($input, $io);

        if ($format === null || $limit === null) {
            return Command::FAILURE;
        }

        $filters = $this->buildFilters($input, $io);

        if ($filters === null) {
            return Command::FAILURE;
        }

        $audits = $this->repository->findWithFilters($filters, $limit);

        if ($audits === []) {
            $io->warning('No audit logs found matching the criteria.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Found %s audit logs', number_format(count($audits))));

        $data = $this->exporter->formatAudits($audits, $format);
        $outputFile = $input->getOption('output');

        if (is_string($outputFile) && $outputFile !== '') {
            $this->writeToFile($io, $outputFile, $data, count($audits));
        } else {
            $output->writeln($data);
        }

        return Command::SUCCESS;
    }

    private function parseFormat(InputInterface $input, SymfonyStyle $io): ?string
    {
        $format = strtolower((string) $input->getOption('format'));

        if (!in_array($format, self::VALID_FORMATS, true)) {
            $io->error(sprintf('Invalid format "%s". Valid: %s', $format, implode(', ', self::VALID_FORMATS)));

            return null;
        }

        return $format;
    }

    private function parseLimit(InputInterface $input, SymfonyStyle $io): ?int
    {
        $limit = (int) $input->getOption('limit');

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $io->error(sprintf('Limit must be between 1 and %d', self::MAX_LIMIT));

            return null;
        }

        return $limit;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildFilters(InputInterface $input, SymfonyStyle $io): ?array
    {
        $filters = [];

        $this->addEntityFilter($filters, $input);

        if (!$this->addActionFilter($filters, $input, $io)) {
            return null;
        }

        if (!$this->addDateFilter($filters, $input, 'from', $io)) {
            return null;
        }

        if (!$this->addDateFilter($filters, $input, 'to', $io)) {
            return null;
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function addEntityFilter(array &$filters, InputInterface $input): void
    {
        $entity = $input->getOption('entity');
        if (is_string($entity) && $entity !== '') {
            $filters['entityClass'] = $entity;
        }
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function addActionFilter(array &$filters, InputInterface $input, SymfonyStyle $io): bool
    {
        $action = $input->getOption('action');
        if (is_string($action) && $action !== '') {
            if (!$this->validateAction($action, $io)) {
                return false;
            }
            $filters['action'] = $action;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function addDateFilter(array &$filters, InputInterface $input, string $param, SymfonyStyle $io): bool
    {
        $date = $input->getOption($param);
        if (is_string($date) && $date !== '') {
            $parsedDate = $this->parseDate($date, $param, $io);
            if ($parsedDate === null) {
                return false;
            }
            $filters[$param] = $parsedDate;
        }

        return true;
    }

    private function validateAction(string $action, SymfonyStyle $io): bool
    {
        $available = $this->getAvailableActions();
        if (!in_array($action, $available, true)) {
            $io->error(sprintf('Invalid action "%s". Available: %s', $action, implode(', ', $available)));

            return false;
        }

        return true;
    }

    private function parseDate(string $date, string $param, SymfonyStyle $io): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($date);
        } catch (Exception $e) {
            $io->error(sprintf('Invalid "%s" date: %s. Error: %s', $param, $date, $e->getMessage()));

            return null;
        }
    }

    private function writeToFile(SymfonyStyle $io, string $outputFile, string $data, int $count): void
    {
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0o755, true) && !is_dir($directory)) {
            $io->error(sprintf('Failed to create directory: %s', $directory));

            return;
        }

        if (false === file_put_contents($outputFile, $data)) {
            $io->error(sprintf('Failed to write to file: %s', $outputFile));

            return;
        }

        $io->success(sprintf(
            'Exported %s audit logs to %s (%s)',
            number_format($count),
            $outputFile,
            $this->exporter->formatFileSize(strlen($data))
        ));
    }

    /**
     * @return array<string>
     */
    private function getAvailableActions(): array
    {
        return [
            AuditLogInterface::ACTION_CREATE,
            AuditLogInterface::ACTION_UPDATE,
            AuditLogInterface::ACTION_DELETE,
            AuditLogInterface::ACTION_SOFT_DELETE,
            AuditLogInterface::ACTION_RESTORE,
        ];
    }
}
