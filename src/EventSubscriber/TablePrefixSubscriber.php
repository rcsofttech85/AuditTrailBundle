<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Events;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;

use function array_filter;
use function implode;

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
        $updatedTable = [
            'name' => implode('_', array_filter([$this->tablePrefix, $tableName, $this->tableSuffix], static fn (string $part): bool => $part !== '')),
        ];

        if (isset($classMetadata->table)) {
            /** @var array{name: string, schema?: string, indexes?: array<string, array<string, mixed>>, uniqueConstraints?: array<string, array<string, mixed>>, options?: array<string, mixed>, quoted?: bool} $existingTable */
            $existingTable = $classMetadata->table;
            $updatedTable = [...$existingTable, ...$updatedTable];
        }

        $classMetadata->setPrimaryTable($updatedTable);
    }
}
