<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use DateTimeImmutable;
use Exception;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function is_numeric;
use function is_string;
use function sprintf;
use function strtolower;

final class AuditExportInputFactory
{
    private const string FORMAT_JSON = 'json';

    private const string FORMAT_CSV = 'csv';

    private const array VALID_FORMATS = [self::FORMAT_JSON, self::FORMAT_CSV];

    private const int DEFAULT_LIMIT = 1000;

    private const int MAX_LIMIT = 100000;

    public function create(InputInterface $input, SymfonyStyle $io): ?AuditExportInput
    {
        $format = $this->parseFormat($input, $io);
        $limit = $this->parseLimit($input, $io);

        if ($format === null || $limit === null) {
            return null;
        }

        $filters = $this->buildFilters($input, $io);

        if ($filters === null) {
            return null;
        }

        return new AuditExportInput($this->resolveOutputTarget($input), $format, $limit, $filters);
    }

    private function resolveOutputTarget(InputInterface $input): string
    {
        $output = $input->getOption('output');

        return is_string($output) && $output !== '' ? $output : 'php://output';
    }

    private function parseFormat(InputInterface $input, SymfonyStyle $io): ?string
    {
        $formatOption = $input->getOption('format');
        $format = is_string($formatOption) ? strtolower($formatOption) : self::FORMAT_JSON;

        if (!in_array($format, self::VALID_FORMATS, true)) {
            $io->error(sprintf('Invalid format "%s". Valid: %s', $format, implode(', ', self::VALID_FORMATS)));

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
     * @return array<string, mixed>|null
     */
    private function buildFilters(InputInterface $input, SymfonyStyle $io): ?array
    {
        $filters = [];
        $entity = $input->getOption('entity');

        if (is_string($entity) && $entity !== '') {
            $filters['entityClass'] = $entity;
        }

        $action = $input->getOption('action');
        if (is_string($action) && $action !== '') {
            if (AuditAction::tryFrom($action) === null) {
                $io->error(sprintf('Invalid action "%s". Available: %s', $action, implode(', ', AuditAction::values())));

                return null;
            }

            $filters['action'] = AuditAction::from($action)->value;
        }

        foreach (['from', 'to'] as $parameter) {
            $date = $input->getOption($parameter);

            if (!is_string($date) || $date === '') {
                continue;
            }

            $parsedDate = $this->parseDate($date, $parameter, $io);

            if ($parsedDate === null) {
                return null;
            }

            $filters[$parameter] = $parsedDate;
        }

        return $filters;
    }

    private function parseDate(string $date, string $parameter, SymfonyStyle $io): ?DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($date);
        } catch (Exception $exception) {
            $io->error(sprintf('Invalid "%s" date: %s. Error: %s', $parameter, $date, $exception->getMessage()));

            return null;
        }
    }
}
