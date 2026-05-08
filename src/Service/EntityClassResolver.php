<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Throwable;

use function get_parent_class;
use function is_string;

final class EntityClassResolver
{
    /** @var array<string, string> */
    private array $resolvedClassNames = [];

    public function __construct(
        private readonly ?EntityManagerResolver $entityManagerResolver = null,
    ) {
    }

    public function resolve(object $entity, ?EntityManagerInterface $entityManager = null): string
    {
        $runtimeClass = $entity::class;

        if (isset($this->resolvedClassNames[$runtimeClass])) {
            return $this->resolvedClassNames[$runtimeClass];
        }

        $entityManager ??= $this->entityManagerResolver?->resolveForObject($entity);
        if (!$entityManager instanceof EntityManagerInterface) {
            return $runtimeClass;
        }

        return $this->resolvedClassNames[$runtimeClass] = $this->resolveCanonicalClass($runtimeClass, $entityManager);
    }

    /**
     * @param class-string<object> $runtimeClass
     */
    private function resolveCanonicalClass(string $runtimeClass, EntityManagerInterface $entityManager): string
    {
        try {
            return $entityManager->getClassMetadata($runtimeClass)->getName();
        } catch (Throwable) {
        }

        $parentClass = get_parent_class($runtimeClass);

        while (is_string($parentClass)) {
            /** @var class-string<object> $mappedClass */
            $mappedClass = $parentClass;

            try {
                return $entityManager->getClassMetadata($mappedClass)->getName();
            } catch (Throwable) {
            }

            $parentClass = get_parent_class($mappedClass);
        }

        return $runtimeClass;
    }
}
