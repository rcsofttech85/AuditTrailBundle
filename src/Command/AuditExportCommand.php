<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'audit:export',
    description: 'Export audit logs to JSON or CSV format',
)]
final class AuditExportCommand extends Command
{
    private const FORMAT_JSON = 'json';
    private const FORMAT_CSV = 'csv';
    private const VALID_FORMATS = [self::FORMAT_JSON, self::FORMAT_CSV];

    private const DEFAULT_LIMIT = 1000;
    private const MAX_LIMIT = 100000;

    public function __construct(
        private readonly AuditLogRepository $repository,
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

        // Validate format
        $format = $this->parseFormat($input, $io);
        if (null === $format) {
            return Command::FAILURE;
        }

        // Validate limit
        $limit = $this->parseLimit($input, $io);
        if (null === $limit) {
            return Command::FAILURE;
        }

        // Build filters
        $filters = $this->buildFilters($input, $io);
        if (null === $filters) {
            return Command::FAILURE;
        }

        // Fetch audit logs
        /** @var array<AuditLog> $audits */
        $audits = $this->repository->findWithFilters($filters, $limit);

        if ([] === $audits) {
            $io->warning('No audit logs found matching the criteria.');

            return Command::SUCCESS;
        }

        $io->note(sprintf('Found %s audit logs', number_format(count($audits))));

        // Format data
        $data = $this->formatAudits($audits, $format);

        // Write output
        $outputFile = $input->getOption('output');
        if (is_string($outputFile) && '' !== $outputFile) {
            $this->writeToFile($io, $outputFile, $data, count($audits));
        } else {
            $output->writeln($data);
        }

        return Command::SUCCESS;
    }

    private function parseFormat(InputInterface $input, SymfonyStyle $io): ?string
    {
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? strtolower($formatOption) : self::FORMAT_JSON;

        if (!in_array($format, self::VALID_FORMATS, true)) {
            $io->error(sprintf(
                'Invalid format "%s". Valid formats: %s',
                $format,
                implode(', ', self::VALID_FORMATS)
            ));

            return null;
        }

        return $format;
    }

    private function parseLimit(InputInterface $input, SymfonyStyle $io): ?int
    {
        $limitOption = $input->getOption('limit');
        $limit = is_numeric($limitOption) ? (int) $limitOption : self::DEFAULT_LIMIT;

        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            $io->error(sprintf('Limit must be between 1 and %d', self::MAX_LIMIT));

            return null;
        }

        return $limit;
    }

    /**
     * @return array{entityClass?: string, action?: string, from?: \DateTimeImmutable, to?: \DateTimeImmutable}|null
     */
    private function buildFilters(InputInterface $input, SymfonyStyle $io): ?array
    {
        $filters = [];

        if ($entity = $input->getOption('entity')) {
            if (is_string($entity)) {
                $filters['entityClass'] = $entity;
            }
        }

        if ($action = $input->getOption('action')) {
            if (is_string($action)) {
                $availableActions = $this->getAvailableActions();
                if (!in_array($action, $availableActions, true)) {
                    $io->error(sprintf(
                        'Invalid action "%s". Available actions: %s',
                        $action,
                        implode(', ', $availableActions)
                    ));

                    return null;
                }
                $filters['action'] = $action;
            }
        }

        if ($from = $input->getOption('from')) {
            if (is_string($from)) {
                try {
                    $filters['from'] = new \DateTimeImmutable($from);
                } catch (\Exception $e) {
                    $io->error(sprintf('Invalid "from" date: %s. Error: %s', $from, $e->getMessage()));

                    return null;
                }
            }
        }

        if ($to = $input->getOption('to')) {
            if (is_string($to)) {
                try {
                    $filters['to'] = new \DateTimeImmutable($to);
                } catch (\Exception $e) {
                    $io->error(sprintf('Invalid "to" date: %s. Error: %s', $to, $e->getMessage()));

                    return null;
                }
            }
        }

        return $filters;
    }

    /**
     * @param array<AuditLog> $audits
     */
    private function formatAudits(array $audits, string $format): string
    {
        $rows = $this->convertAuditsToArray($audits);

        return match ($format) {
            self::FORMAT_JSON => $this->formatAsJson($rows),
            self::FORMAT_CSV => $this->formatAsCsv($rows),
            default => throw new \InvalidArgumentException(sprintf('Unsupported format: %s', $format)),
        };
    }

    /**
     * @param array<AuditLog> $audits
     *
     * @return array<array<string, mixed>>
     */
    private function convertAuditsToArray(array $audits): array
    {
        $rows = [];

        foreach ($audits as $audit) {
            $rows[] = [
                'id' => $audit->getId(),
                'entity_class' => $audit->getEntityClass(),
                'entity_id' => $audit->getEntityId(),
                'action' => $audit->getAction(),
                'old_values' => $audit->getOldValues(),
                'new_values' => $audit->getNewValues(),
                'changed_fields' => $audit->getChangedFields(),
                'user_id' => $audit->getUserId(),
                'username' => $audit->getUsername(),
                'ip_address' => $audit->getIpAddress(),
                'user_agent' => $audit->getUserAgent(),
                'created_at' => $audit->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ];
        }

        return $rows;
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function formatAsJson(array $rows): string
    {
        return json_encode($rows, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<array<string, mixed>> $rows
     */
    private function formatAsCsv(array $rows): string
    {
        if ([] === $rows) {
            return '';
        }

        $output = fopen('php://temp', 'r+');
        if (false === $output) {
            throw new \RuntimeException('Failed to open temp stream for CSV generation');
        }

        try {
            // Write headers
            fputcsv($output, array_keys($rows[0]), ',', '"', '\\');

            // Write data rows
            foreach ($rows as $row) {
                $csvRow = array_map(
                    fn ($value) => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value,
                    $row
                );
                fputcsv($output, $csvRow, ',', '"', '\\');
            }

            rewind($output);
            $csv = stream_get_contents($output);

            return false !== $csv ? $csv : '';
        } finally {
            fclose($output);
        }
    }

    private function writeToFile(SymfonyStyle $io, string $outputFile, string $data, int $count): void
    {
        $directory = dirname($outputFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            $io->error(sprintf('Failed to create directory: %s', $directory));

            return;
        }

        $result = file_put_contents($outputFile, $data);
        if (false === $result) {
            $io->error(sprintf('Failed to write to file: %s', $outputFile));

            return;
        }

        $io->success(sprintf(
            'Exported %s audit logs to %s (%s)',
            number_format($count),
            $outputFile,
            $this->formatFileSize(strlen($data))
        ));
    }

    private function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = (int) floor((strlen((string) $bytes) - 1) / 3);

        return sprintf('%.2f %s', $bytes / (1024 ** $factor), $units[$factor]);
    }

    /**
     * @return array<string>
     */
    private function getAvailableActions(): array
    {
        return [
            AuditLog::ACTION_CREATE,
            AuditLog::ACTION_UPDATE,
            AuditLog::ACTION_DELETE,
            AuditLog::ACTION_SOFT_DELETE,
            AuditLog::ACTION_RESTORE,
        ];
    }
}
