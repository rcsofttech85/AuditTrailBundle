<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use BackedEnum;
use DateTimeInterface;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\PersistentCollection;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Stringable;
use UnitEnum;

use function is_array;
use function is_object;
use function is_resource;
use function method_exists;
use function sprintf;

final readonly class ValueSerializer implements ValueSerializerInterface
{
    private const int MAX_SERIALIZATION_DEPTH = 5;

    private const int MAX_COLLECTION_ITEMS = 100;

    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[Override]
    public function serialize(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= self::MAX_SERIALIZATION_DEPTH) {
            return '[max depth reached]';
        }

        return match (true) {
            $value === null => null,
            $value instanceof DateTimeInterface => $value->format(DateTimeInterface::ATOM),
            $value instanceof UnitEnum => $this->serializeEnum($value),
            $value instanceof Collection => $this->serializeCollection($value, $depth),
            is_object($value) => $this->serializeObject($value, $depth),
            is_array($value) => array_map(
                fn ($v) => $this->serialize($v, $depth + 1),
                $value
            ),
            is_resource($value) => sprintf('[resource: %s]', get_resource_type($value)),
            default => $value,
        };
    }

    #[Override]
    public function serializeAssociation(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Collection) {
            return $this->serializeCollection($value, 0, true);
        }

        if (is_object($value)) {
            return $this->extractEntityIdentifier($value);
        }

        return null;
    }

    /**
     * @param Collection<int|string, mixed> $value
     */
    private function serializeCollection(Collection $value, int $depth, bool $onlyIdentifiers = false): mixed
    {
        // Optimization: Prevent N+1 queries by checking if the collection is initialized.
        if ($value instanceof PersistentCollection && !$value->isInitialized()) {
            return [
                '_state' => 'uninitialized',
                '_total_count' => 'unknown',
            ];
        }

        $count = $value->count();

        if ($count > self::MAX_COLLECTION_ITEMS) {
            $this->logger?->warning('Collection exceeds max items for audit', [
                'count' => $count,
                'max' => self::MAX_COLLECTION_ITEMS,
            ]);

            $sample = $value->slice(0, self::MAX_COLLECTION_ITEMS);

            return [
                '_truncated' => true,
                '_total_count' => $count,
                '_sample' => array_map(
                    fn ($item) => $onlyIdentifiers && is_object($item)
                    ? $this->extractEntityIdentifier($item)
                    : $this->serialize($item, $depth + 1),
                    $sample
                ),
            ];
        }

        $items = $value->toArray();

        return array_map(
            fn ($item) => $onlyIdentifiers && is_object($item)
            ? $this->extractEntityIdentifier($item)
            : $this->serialize($item, $depth + 1),
            $items
        );
    }

    private function serializeObject(object $value, int $depth = 0): mixed
    {
        if (method_exists($value, 'getId')) {
            $id = $value->getId();

            // Handle IDs that are themselves objects (e.g. UUID objects)
            return is_object($id) ? $this->serialize($id, $depth + 1) : $id;
        }

        if ($value instanceof Stringable || method_exists($value, '__toString')) {
            return (string) $value;
        }

        return $value::class;
    }

    private function serializeEnum(UnitEnum $value): mixed
    {
        return $value instanceof BackedEnum ? $value->value : $value->name;
    }

    private function extractEntityIdentifier(object $entity): mixed
    {
        if (method_exists($entity, 'getId')) {
            $id = $entity->getId();

            // Recurse for object identifiers (like Uuid)
            return is_object($id) ? $this->serialize($id) : $id;
        }

        return $entity::class;
    }
}
