<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\MappingException as PersistenceMappingException;
use Psr\Log\LoggerInterface;
use Throwable;

use function array_values;

final readonly class DoctrineEntityIdentifierExtractor
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function extract(object $entity, EntityManagerInterface $entityManager): array
    {
        $metadata = $this->tryGetClassMetadata($entity, $entityManager);
        if ($metadata !== null) {
            $ids = $metadata->getIdentifierValues($entity);
            if ($ids !== []) {
                /** @var array<string, mixed> $ids */
                return $ids;
            }
        }

        $uow = $entityManager->getUnitOfWork();
        if ($uow->isInIdentityMap($entity)) {
            $ids = $uow->getEntityIdentifier($entity);
            if ($ids !== []) {
                /** @var array<string, mixed> $ids */
                return $ids;
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function resolveIdentifierFieldNames(object $entity, EntityManagerInterface $entityManager): array
    {
        $metadata = $this->tryGetClassMetadata($entity, $entityManager);

        return $metadata === null ? [] : array_values($metadata->getIdentifierFieldNames());
    }

    /**
     * @return ClassMetadata<object>|null
     */
    private function tryGetClassMetadata(object $entity, EntityManagerInterface $entityManager): ?ClassMetadata
    {
        try {
            if ($entityManager->getMetadataFactory()->isTransient($entity::class)) {
                return null;
            }

            return $entityManager->getClassMetadata($entity::class);
        } catch (PersistenceMappingException) {
            return null;
        } catch (Throwable $exception) {
            $this->logger?->debug('Unable to read Doctrine metadata while resolving audit entity identifier.', [
                'entity_class' => $entity::class,
                'exception' => $exception,
            ]);

            return null;
        }
    }
}
