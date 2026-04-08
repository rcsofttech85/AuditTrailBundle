<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;

interface EntityDataExtractorInterface
{
    /**
     * @param array<string> $ignored
     *
     * @return array<string, mixed>
     */
    public function extract(object $entity, array $ignored = [], ?EntityManagerInterface $entityManager = null): array;
}
