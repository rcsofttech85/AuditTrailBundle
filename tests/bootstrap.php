<?php

declare(strict_types=1);

/**
 * Bootstrap for PHPUnit.
 *
 * Configures DAMA static connections and initializes the schema on the
 * configured test database connection.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Tests\Functional\TestKernel;
use Rcsofttech\AuditTrailBundle\Tests\TestDatabaseUrlResolver;
use Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures\DummySoftDeleteableFilter;

// Register dummy Gedmo filter if the real package is not installed.
// This alias is used by AuditReverterTest and related soft-delete tests.
if (!class_exists('Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter')) {
    class_alias(DummySoftDeleteableFilter::class, 'Gedmo\SoftDeleteable\Filter\SoftDeleteableFilter');
}

$configuredDatabaseUrl = TestDatabaseUrlResolver::resolve();
$usesPersistentExternalDatabase = is_string($configuredDatabaseUrl) && $configuredDatabaseUrl !== '';

// Keep DAMA static connections only for the default in-memory SQLite path.
// External databases persist on their own, and bootstrapping them through the
// static driver leaves different platforms in different transaction states.
StaticDriver::setKeepStaticConnections(!$usesPersistentExternalDatabase);

try {
    // Boot kernel to create schema on the static connection
    $kernel = new TestKernel('test', true);
    $kernel->boot();

    $container = $kernel->getContainer();
    /** @var EntityManagerInterface $em */
    $em = $container->get('doctrine.orm.entity_manager');

    // Verify metadata exists to avoid silent failures if configuration is broken
    $metadata = $em->getMetadataFactory()->getAllMetadata();
    if ($metadata === []) {
        throw new RuntimeException('No Doctrine metadata found. Ensure entities are correctly registered in TestKernel.');
    }

    $schemaTool = new SchemaTool($em);
    $existingTables = $em->getConnection()->createSchemaManager()->listTableNames();
    if ($existingTables !== []) {
        $schemaTool->dropSchema($metadata);
    }

    $schemaTool->createSchema($metadata);

    // Commit the schema baseline to DAMA's static connection for the default
    // in-memory SQLite path. The static driver manages its own outer
    // transaction state, so relying on Doctrine's transaction flag here leaves
    // PHPUnit believing a transaction is already open.
    if (!$usesPersistentExternalDatabase) {
        StaticDriver::commit();
    }

    // Shutdown kernel. The underlying static connection remains active in DAMA's registry.
    $kernel->shutdown();
} catch (Throwable $e) {
    throw new RuntimeException(sprintf("[FATAL] Test Bootstrap Failed: %s\n%s", $e->getMessage(), $e->getTraceAsString()), previous: $e);
}
