<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

use function array_key_exists;

final readonly class EntityPayloadIdentifierResolver
{
    public function __construct(
        private DoctrineEntityIdentifierExtractor $identifierExtractor,
        private EntityIdentifierFormatter $identifierFormatter,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     */
    public function resolve(object $entity, array $values, EntityManagerInterface $entityManager): ?string
    {
        $identifierFields = $this->identifierExtractor->resolveIdentifierFieldNames($entity, $entityManager);
        if ($identifierFields === []) {
            return null;
        }

        $identifierValues = [];

        foreach ($identifierFields as $identifierField) {
            if (!array_key_exists($identifierField, $values)) {
                $this->logger?->debug('Identifier field is missing from audit payload values.', [
                    'entity_class' => $entity::class,
                    'identifier_field' => $identifierField,
                ]);

                return null;
            }

            $identifierValues[] = $values[$identifierField];
        }

        $resolvedId = $this->identifierFormatter->formatIdentifierValues($identifierValues, $entity, $entityManager);
        if ($resolvedId !== null) {
            return $resolvedId;
        }

        $this->logger?->debug('Unable to resolve identifier values from audit payload.', [
            'entity_class' => $entity::class,
            'identifier_fields' => $identifierFields,
        ]);

        return null;
    }
}
