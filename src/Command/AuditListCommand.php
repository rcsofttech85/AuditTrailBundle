<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Service\AuditRenderer;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

use function count;
use function is_string;
use function sprintf;

#[AsCommand(
    name: 'audit:list',
    description: 'List audit logs with optional filters',
)]
final class AuditListCommand extends BaseAuditCommand
{
    use ClassNameHelperTrait;

    public function __construct(
        AuditLogRepository $repository,
        private readonly AuditRenderer $renderer,
    ) {
        parent::__construct($repository);
    }

    protected function configure(): void
    {
        $this
            ->addOption('entity', null, InputOption::VALUE_OPTIONAL, 'Filter by entity class (partial match)')
            ->addOption('entity-id', null, InputOption::VALUE_OPTIONAL, 'Filter by entity ID')
            ->addOption('user', null, InputOption::VALUE_OPTIONAL, 'Filter by user ID')
            ->addOption('transaction', 't', InputOption::VALUE_OPTIONAL, 'Filter by transaction hash')
            ->addOption('action', null, InputOption::VALUE_OPTIONAL, 'Filter by action')
            ->addOption('from', null, InputOption::VALUE_OPTIONAL, 'Filter from date')
            ->addOption('to', null, InputOption::VALUE_OPTIONAL, 'Filter to date')
            ->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Maximum number of results', '50')
            ->addOption('details', 'd', InputOption::VALUE_NONE, 'Show detailed old → new value changes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $limit = $this->validateLimit($input, $io);
        if ($limit === null) {
            return Command::FAILURE;
        }

        if (!$this->validateAction($input, $io)) {
            return Command::FAILURE;
        }

        try {
            $filters = $this->buildFilters($input);
        } catch (Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $audits = $this->auditLogRepository->findWithFilters($filters, $limit);

        if ($audits === []) {
            $io->info('No audit logs found matching the criteria.');

            return Command::SUCCESS;
        }

        $this->displayResults($io, $output, $input, $audits);

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFilters(InputInterface $input): array
    {
        $filters = array_filter([
            'entityClass' => $input->getOption('entity'),
            'entityId' => $input->getOption('entity-id'),
            'userId' => $this->extractUserOption($input),
            'transactionHash' => $input->getOption('transaction'),
            'action' => $input->getOption('action'),
        ], static fn ($v): bool => $v !== null && $v !== '');

        return $this->addDateFilters($filters, $input);
    }

    private function extractUserOption(InputInterface $input): ?string
    {
        $user = $input->getOption('user');

        return (is_string($user) && $user !== '') ? $user : null;
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function addDateFilters(array $filters, InputInterface $input): array
    {
        foreach (['from', 'to'] as $key) {
            $val = $input->getOption($key);
            if (is_string($val) && $val !== '') {
                try {
                    $filters[$key] = new DateTimeImmutable($val);
                } catch (Exception $e) {
                    throw new InvalidArgumentException(sprintf('Invalid %s date format: %s', $key, $e->getMessage()));
                }
            }
        }

        return $filters;
    }

    /**
     * @param array<AuditLogInterface> $audits
     */
    private function displayResults(
        SymfonyStyle $io,
        OutputInterface $output,
        InputInterface $input,
        array $audits,
    ): void {
        $io->title(sprintf('Audit Logs (%d results)', count($audits)));
        $this->renderer->renderTable($output, $audits, (bool) $input->getOption('details'));

        if (true !== $input->getOption('details')) {
            $io->note('Tip: Use --details (-d) to see old → new value changes.');
        }
    }
}
