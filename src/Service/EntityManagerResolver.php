<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use RuntimeException;

use function get_parent_class;
use function is_string;
use function sprintf;

final readonly class EntityManagerResolver
{
    public function __construct(
        private ?ManagerRegistry $managerRegistry = null,
    ) {
    }

    /**
     * @param class-string<object> $class
     */
    public function resolveForClass(string $class): ?EntityManagerInterface
    {
        if ($this->managerRegistry === null) {
            return null;
        }

        $currentClass = $class;

        while ($currentClass !== null) {
            $manager = $this->managerRegistry->getManagerForClass($currentClass);

            if ($manager instanceof EntityManagerInterface) {
                return $manager;
            }

            $parentClass = get_parent_class($currentClass);
            $currentClass = is_string($parentClass) ? $parentClass : null;
        }

        return null;
    }

    public function resolveForObject(object $entity): ?EntityManagerInterface
    {
        return $this->resolveForClass($entity::class);
    }

    /**
     * @param class-string<object> $class
     */
    public function requireForClass(string $class): EntityManagerInterface
    {
        $manager = $this->resolveForClass($class);
        if ($manager !== null) {
            return $manager;
        }

        throw new RuntimeException(sprintf('No Doctrine ORM entity manager is registered for "%s".', $class));
    }

    public function requireForObject(object $entity): EntityManagerInterface
    {
        return $this->requireForClass($entity::class);
    }
}
