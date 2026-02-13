<?php

declare(strict_types=1);

/**
 * Bootstrap for PHPUnit.
 *
 * Configures DAMA static connections and initializes the in-memory SQLite schema.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use DAMA\DoctrineTestBundle\Doctrine\DBAL\StaticDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Tests\Functional\TestKernel;

// This ensures that even after kernel shutdown, the underlying database connection
// (especially for in-memory SQLite) persists in DAMA's static registry.
StaticDriver::setKeepStaticConnections(true);

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
    $schemaTool->createSchema($metadata);

    // Commit the schema to the static connection.
    // DAMA will now use this state as the "baseline" for every test's transaction.
    StaticDriver::commit();

    // Shutdown kernel. The underlying static connection remains active in DAMA's registry.
    $kernel->shutdown();
} catch (Throwable $e) {
    fwrite(\STDERR, sprintf("\n[FATAL] Test Bootstrap Failed: %s\n", $e->getMessage()));
    fwrite(\STDERR, $e->getTraceAsString()."\n");
    exit(1);
}
