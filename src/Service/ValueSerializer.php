<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\Common\Collections\Collection;
use Psr\Log\LoggerInterface;

class ValueSerializer
{
    private const int MAX_SERIALIZATION_DEPTH = 5;
    private const int MAX_COLLECTION_ITEMS = 100;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function serialize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_SERIALIZATION_DEPTH) {
            return '[max depth reached]';
        }

        return match (true) {
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof Collection => $this->serializeCollection($value, $depth),
            \is_object($value) => $this->serializeObject($value),
            \is_array($value) => array_map(
                fn ($v) => $this->serialize($v, $depth + 1),
                $value
            ),
            \is_resource($value) => sprintf('[resource: %s]', get_resource_type($value)),
            default => $value,
        };
    }

    public function serializeAssociation(mixed $value): mixed
    {
        if (null === $value) {
            return null;
        }

        if ($value instanceof Collection) {
            return $this->serializeCollection($value, 0, true);
        }

        if (\is_object($value)) {
            return $this->extractEntityIdentifier($value);
        }

        return null;
    }

    /**
     * @param Collection<int|string, mixed> $value
     */
    private function serializeCollection(Collection $value, int $depth, bool $onlyIdentifiers = false): mixed
    {
        $count = $value->count();

        if ($count > self::MAX_COLLECTION_ITEMS) {
            $this->logger?->warning('Collection exceeds max items for audit', [
                'count' => $count,
                'max' => self::MAX_COLLECTION_ITEMS,
            ]);

            return [
                '_truncated' => true,
                '_total_count' => $count,
                '_sample' => array_map(
                    fn ($item) => $onlyIdentifiers ? $this->extractEntityIdentifier($item) : $this->serialize($item, $depth + 1),
                    $value->slice(0, self::MAX_COLLECTION_ITEMS)
                ),
            ];
        }

        return $value->map(function ($item) use ($depth, $onlyIdentifiers) {
            return $onlyIdentifiers ? $this->extractEntityIdentifier($item) : $this->serialize($item, $depth + 1);
        })->toArray();
    }

    private function serializeObject(object $value): mixed
    {
        if (method_exists($value, 'getId')) {
            return $value->getId();
        }

        if (method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value::class;
    }

    private function extractEntityIdentifier(object $entity): mixed
    {
        if (method_exists($entity, 'getId')) {
            return $entity->getId();
        }

        return $entity::class;
    }
}
