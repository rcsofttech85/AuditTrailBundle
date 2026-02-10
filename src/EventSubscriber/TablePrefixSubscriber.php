<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class TablePrefixSubscriber
{
    public function __construct(
        private readonly string $tablePrefix,
        private readonly string $tableSuffix,
    ) {
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $classMetadata = $args->getClassMetadata();

        if (AuditLog::class !== $classMetadata->getName()) {
            return;
        }

        if ($this->tablePrefix === '' && $this->tableSuffix === '') {
            return;
        }

        $tableName = $classMetadata->getTableName();
        $classMetadata->setPrimaryTable([
            'name' => "{$this->tablePrefix}_{$tableName}_{$this->tableSuffix}",
        ]);
    }
}
