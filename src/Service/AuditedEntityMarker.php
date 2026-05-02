<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditAccessHandlerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Throwable;

use function sprintf;

final readonly class AuditedEntityMarker
{
    public function __construct(
        private AuditAccessHandlerInterface $accessHandler,
        private EntityIdResolverInterface $idResolver,
    ) {
    }

    public function mark(object $entity, EntityManagerInterface $entityManager): void
    {
        $id = $this->resolveEntityId($entity, $entityManager);
        if ($id === AuditLogInterface::PENDING_ID) {
            return;
        }

        try {
            $class = $entityManager->getClassMetadata($entity::class)->getName();
        } catch (Throwable) {
            $class = $entity::class;
        }

        $this->accessHandler->markAsAudited(sprintf('%s:%s', $class, $id));
    }

    public function resolveEntityId(object $entity, EntityManagerInterface $entityManager): string
    {
        return $this->idResolver->resolveFromEntity($entity, $entityManager);
    }
}
