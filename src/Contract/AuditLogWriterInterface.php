<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

interface AuditLogWriterInterface
{
    public function insert(AuditLog $audit, EntityManagerInterface $em): void;
}
