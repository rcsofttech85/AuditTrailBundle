<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Util;

/**
 * @internal
 */
trait ClassNameHelperTrait
{
    /**
     * Shortens a fully qualified class name to its base name.
     */
    protected function shortenClass(string $className): string
    {
        $lastBackslash = strrpos($className, '\\');

        return false === $lastBackslash ? $className : substr($className, $lastBackslash + 1);
    }
}
