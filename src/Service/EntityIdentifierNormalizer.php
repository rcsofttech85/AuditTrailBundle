<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Uid\Uuid;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function array_values;
use function count;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final readonly class EntityIdentifierNormalizer
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @param class-string<object> $class
     */
    public function normalize(string $class, mixed $identifier): mixed
    {
        try {
            $targetMetadata = $this->em->getClassMetadata($class);
        } catch (Throwable) {
            return $identifier;
        }

        return $this->normalizeAssociationIdentifier($targetMetadata, $identifier);
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function normalizeAssociationIdentifier(ClassMetadata $targetMetadata, mixed $identifier): mixed
    {
        $identifierFields = array_values($targetMetadata->getIdentifierFieldNames());
        if ($identifierFields === []) {
            return $identifier;
        }

        if (!is_array($identifier) && count($identifierFields) === 1) {
            return $this->normalizeIdentifierValue(
                $this->resolveIdentifierFieldType($targetMetadata, $identifierFields[0]),
                $identifier,
            );
        }

        $identifier = $this->decodeCompositeIdentifierIfNeeded($identifierFields, $identifier);
        if (!is_array($identifier)) {
            return $identifier;
        }

        foreach ($identifierFields as $identifierField) {
            if (!array_key_exists($identifierField, $identifier)) {
                continue;
            }

            $identifier[$identifierField] = $this->normalizeIdentifierValue(
                $this->resolveIdentifierFieldType($targetMetadata, $identifierField),
                $identifier[$identifierField],
            );
        }

        return $identifier;
    }

    /**
     * @param list<string> $identifierFields
     */
    private function decodeCompositeIdentifierIfNeeded(array $identifierFields, mixed $identifier): mixed
    {
        if (!is_string($identifier)) {
            return $identifier;
        }

        $decodedIdentifier = $this->decodeCompositeIdentifier($identifierFields, $identifier);

        return is_array($decodedIdentifier) ? $decodedIdentifier : $identifier;
    }

    /**
     * @param list<string> $identifierFields
     *
     * @return array<string, mixed>|null
     */
    private function decodeCompositeIdentifier(array $identifierFields, string $identifier): ?array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($identifier, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        if (!array_is_list($decoded)) {
            /** @var array<string, mixed> $decoded */
            return $decoded;
        }

        if (count($decoded) !== count($identifierFields)) {
            return null;
        }

        $mapped = [];
        foreach ($identifierFields as $index => $field) {
            $mapped[$field] = $decoded[$index];
        }

        return $mapped;
    }

    /**
     * @param ClassMetadata<object> $targetMetadata
     */
    private function resolveIdentifierFieldType(ClassMetadata $targetMetadata, string $identifierField): ?string
    {
        try {
            return $targetMetadata->getTypeOfField($identifierField);
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeIdentifierValue(?string $type, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        try {
            return match ($type) {
                'uuid' => Uuid::fromString($value),
                'ulid' => Ulid::fromString($value),
                default => $value,
            };
        } catch (Throwable) {
            return $value;
        }
    }
}
