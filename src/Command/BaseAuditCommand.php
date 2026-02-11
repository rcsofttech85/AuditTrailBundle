<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Command;

use InvalidArgumentException;
use JsonException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Util\ClassNameHelperTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function is_array;
use function is_string;
use function sprintf;

use const FILTER_VALIDATE_INT;
use const JSON_THROW_ON_ERROR;

/**
 * Base class for audit-related commands to reduce logic duplication.
 */
abstract class BaseAuditCommand extends Command
{
    use ClassNameHelperTrait;

    protected const array VALID_ACTIONS = [
        AuditLogInterface::ACTION_CREATE,
        AuditLogInterface::ACTION_UPDATE,
        AuditLogInterface::ACTION_DELETE,
        AuditLogInterface::ACTION_SOFT_DELETE,
        AuditLogInterface::ACTION_RESTORE,
    ];

    public function __construct(
        protected readonly AuditLogRepository $auditLogRepository,
    ) {
        parent::__construct();
    }

    protected function validateLimit(InputInterface $input, SymfonyStyle $io): ?int
    {
        $limitRaw = $input->getOption('limit');
        $limit = is_numeric($limitRaw) ? (int) $limitRaw : 50;

        if ($limit < 1 || $limit > 1000) {
            $io->error('Limit must be between 1 and 1000.');

            return null;
        }

        return $limit;
    }

    protected function validateAction(InputInterface $input, SymfonyStyle $io): bool
    {
        $action = $input->getOption('action');

        if (is_string($action) && $action !== '' && !in_array($action, self::VALID_ACTIONS, true)) {
            $io->error('Invalid action specified.');

            return false;
        }

        return true;
    }

    protected function parseAuditId(InputInterface $input): int
    {
        $auditIdInput = $input->getArgument('auditId');
        if ($auditIdInput === null) {
            return 0;
        }

        $auditId = filter_var($auditIdInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($auditId === false) {
            throw new InvalidArgumentException('auditId must be a valid audit log ID');
        }

        return $auditId;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function parseContext(InputInterface $input, SymfonyStyle $io): ?array
    {
        $contextOption = $input->getOption('context');
        $contextString = is_string($contextOption) ? $contextOption : '';

        if ($contextString === '{}' || $contextString === '') {
            return [];
        }

        try {
            $context = json_decode($contextString, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($context)) {
                throw new InvalidArgumentException('Context must be a valid JSON object (array).');
            }

            /** @var array<string, mixed> $context */
            return $context;
        } catch (JsonException $e) {
            $io->error(sprintf('Invalid JSON context: %s', $e->getMessage()));

            return null;
        }
    }

    protected function fetchAuditLog(int $auditId, SymfonyStyle $io): ?AuditLogInterface
    {
        $log = $this->auditLogRepository->find($auditId);

        if (!$log instanceof AuditLogInterface) {
            $io->error(sprintf('Audit log with ID %d not found.', $auditId));

            return null;
        }

        return $log;
    }
}
