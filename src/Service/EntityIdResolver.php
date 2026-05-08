<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;

use function array_values;
use function is_object;

/**
 * @internal
 */
final readonly class EntityIdResolver implements EntityIdResolverInterface
{
    public function __construct(
        private DoctrineEntityIdentifierExtractor $identifierExtractor,
        private EntityIdentifierFormatter $identifierFormatter,
        private EntityPayloadIdentifierResolver $payloadIdentifierResolver,
        private EntityManagerResolver $entityManagerResolver,
    ) {
    }

    #[Override]
    public function resolve(object $object, AuditTransportContext $context): ?string
    {
        if (!$object instanceof AuditLogInterface) {
            return null;
        }

        $currentId = $object->entityId;

        if ($currentId !== null) {
            return $context->phase->isOnFlush() ? $currentId : null;
        }

        return $this->resolveFromContext($context);
    }

    #[Override]
    public function resolveFromEntity(object $entity, ?EntityManagerInterface $em = null): ?string
    {
        $em ??= $this->entityManagerResolver->resolveForObject($entity);
        if ($em === null) {
            return null;
        }

        $ids = $this->identifierExtractor->extract($entity, $em);
        if ($ids === []) {
            return null;
        }

        return $this->identifierFormatter->formatIdentifierValues(array_values($ids), $entity, $em);
    }

    /**
     * @param array<string, mixed> $values
     */
    #[Override]
    public function resolveFromValues(object $entity, array $values, EntityManagerInterface $em): ?string
    {
        return $this->payloadIdentifierResolver->resolve($entity, $values, $em);
    }

    private function resolveFromContext(AuditTransportContext $context): ?string
    {
        $entity = $context->entity;
        $em = $context->entityManager;

        if (!is_object($entity)) {
            return null;
        }

        return $this->resolveFromEntity($entity, $em);
    }
}
