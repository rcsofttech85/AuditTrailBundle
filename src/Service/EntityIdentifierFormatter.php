<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

use function count;
use function is_object;
use function is_scalar;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final readonly class EntityIdentifierFormatter
{
    public function __construct(
        private DoctrineEntityIdentifierExtractor $identifierExtractor,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<mixed> $ids
     */
    public function formatIdentifierValues(array $ids, object $entity, EntityManagerInterface $entityManager): ?string
    {
        $normalizedIds = $this->normalizeIdentifierValues($ids, $entityManager);
        if ($normalizedIds === []) {
            return null;
        }

        if (count($normalizedIds) === 1) {
            return $normalizedIds[0];
        }

        try {
            return json_encode($normalizedIds, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            $this->logger?->warning('Failed to encode composite identifier values.', [
                'entity_class' => $entity::class,
                'exception' => $this->normalizeExceptionContext($exception),
            ]);

            return null;
        }
    }

    /**
     * @param list<mixed> $ids
     *
     * @return list<string>
     */
    private function normalizeIdentifierValues(array $ids, EntityManagerInterface $entityManager): array
    {
        $formattedIds = [];

        foreach ($ids as $value) {
            $formatted = $this->normalizeIdentifierValue($value, $entityManager);
            if ($formatted === null) {
                return [];
            }

            $formattedIds[] = $formatted;
        }

        return $formattedIds;
    }

    private function normalizeIdentifierValue(mixed $value, EntityManagerInterface $entityManager): ?string
    {
        $formatted = $this->formatScalarIdentifier($value);
        if ($formatted !== null) {
            return $formatted;
        }

        if (!is_object($value)) {
            return null;
        }

        $nestedIds = $this->identifierExtractor->extract($value, $entityManager);
        if ($nestedIds === []) {
            return null;
        }

        return $this->formatIdentifierValues(array_values($nestedIds), $value, $entityManager);
    }

    private function formatScalarIdentifier(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        if (is_scalar($id) || $id instanceof Stringable) {
            return (string) $id;
        }

        return null;
    }

    /**
     * @return array{type: class-string<Throwable>, message: string}
     */
    private function normalizeExceptionContext(Throwable $exception): array
    {
        return [
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ];
    }
}
