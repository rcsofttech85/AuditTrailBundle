<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use function is_callable;
use function method_exists;
use function preg_replace;
use function strrpos;
use function substr;

final class AssociationMutatorInvoker
{
    public function invokeCollectionMutator(object $entity, string $prefix, object $item): bool
    {
        return $this->invokeMutator($entity, $prefix, $this->resolveShortClassName($item), $item);
    }

    public function invokeCounterpartMutator(object $entity, object $item, bool $adding): bool
    {
        return $this->invokeMutator($item, $adding ? 'add' : 'remove', $this->resolveShortClassName($entity), $entity);
    }

    private function invokeMutator(object $target, string $prefix, string $shortName, object $argument): bool
    {
        $method = $prefix.$shortName;

        if (!method_exists($target, $method) || !is_callable([$target, $method])) {
            return false;
        }

        /** @var callable(object): void $callable */
        $callable = [$target, $method];
        $callable($argument);

        return true;
    }

    private function resolveShortClassName(object $entity): string
    {
        $separator = strrpos($entity::class, '\\');
        $shortName = $separator === false ? $entity::class : substr($entity::class, $separator + 1);

        return (string) preg_replace('/[^A-Za-z0-9]/', '', $shortName);
    }
}
