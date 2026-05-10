<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;

use function sprintf;

final readonly class AuditedEntityMarker
{
    private EntityClassResolver $entityClassResolver;

    public function __construct(
        private AuditAccessHandlerInterface $accessHandler,
        private EntityIdResolverInterface $idResolver,
        ?EntityClassResolver $entityClassResolver = null,
    ) {
        $this->entityClassResolver = $entityClassResolver ?? new EntityClassResolver();
    }

    public function mark(object $entity, EntityManagerInterface $entityManager): void
    {
        $id = $this->resolveEntityId($entity, $entityManager);
        if ($id === null) {
            return;
        }

        $class = $this->entityClassResolver->resolve($entity, $entityManager);

        $this->accessHandler->markAsAudited(sprintf('%s:%s', $class, $id));
    }

    public function resolveEntityId(object $entity, EntityManagerInterface $entityManager): ?string
    {
        return $this->idResolver->resolveFromEntity($entity, $entityManager);
    }
}
