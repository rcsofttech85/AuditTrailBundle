<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Attribute\AuditAccess;

final readonly class PendingAccessAudit
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $requestKey,
        public object $entity,
        public EntityManagerInterface $entityManager,
        public AuditAccess $access,
        public array $context,
    ) {
    }
}
