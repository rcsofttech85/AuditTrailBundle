<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

#[AllowMockObjectsWithoutExpectations]
class TransactionSafetyTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        TestKernel::$useThrowingTransport = true;
    }

    protected function tearDown(): void
    {
        TestKernel::$useThrowingTransport = false;

        self::ensureKernelShutdown();

        parent::tearDown();
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = parent::createKernel($options);
        if ($kernel instanceof TestKernel && isset($options['audit_config'])) {
            $kernel->setAuditConfig($options['audit_config']);
        }

        return $kernel;
    }

    private function setupDatabase(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    /**
     * Helper method to get a fresh EntityManager after an exception.
     *
     * @param array<string, mixed> $options
     */
    private function getFreshEntityManager(array $options): EntityManagerInterface
    {
        self::ensureKernelShutdown();
        self::bootKernel($options);
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);

        return $em;
    }

    #[RunInSeparateProcess]
    public function testAtomicModeRollsBackDataOnTransportFailure(): void
    {
        // Configure for Atomic Mode (defer = false)
        // This means transport is called during onFlush.
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'fail_on_transport_error' => true,
                'transports' => ['doctrine' => true],
            ],
        ];

        try {
            self::bootKernel($options);
            $container = self::getContainer();

            /** @var EntityManagerInterface $em */
            $em = $container->get('doctrine.orm.entity_manager');
            $this->setupDatabase($em);

            // Create Entity
            $entity = new TestEntity('Atomic Test');
            $em->persist($entity);

            // Flush - Expect Exception from Transport
            $exceptionThrown = false;
            try {
                $em->flush();
            } catch (\RuntimeException $e) {
                $exceptionThrown = true;
                self::assertEquals('Transport failed intentionally.', $e->getMessage());
            }

            self::assertTrue($exceptionThrown, 'Flush should have failed due to transport exception.');

            // Verify Entity is NOT in Database (Rollback happened)
            // Verify Entity is NOT in Database (Rollback happened)
            $em = $this->getFreshEntityManager($options);
            $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Atomic Test']);
            self::assertNull($savedEntity, 'Entity should NOT be saved in Atomic mode if transport fails.');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[RunInSeparateProcess]
    public function testDeferredModePersistsDataEvenIfTransportFails(): void
    {
        // Configure for Deferred Mode (defer = true)
        // This means transport is called during postFlush (after commit).
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'transports' => ['doctrine' => true],
            ],
        ];

        try {
            self::bootKernel($options);
            $container = self::getContainer();

            /** @var EntityManagerInterface $em */
            $em = $container->get('doctrine.orm.entity_manager');
            $this->setupDatabase($em);

            // Create Entity
            $entity = new TestEntity('Deferred Test');
            $em->persist($entity);

            // Flush - Transport error should be caught silently (fail_on_transport_error defaults to false)
            // The transaction should have been committed before transport is called
            $em->flush();

            // Verify Entity IS in Database (Commit happened)
            $em = $this->getFreshEntityManager($options);
            $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Test']);
            self::assertNotNull($savedEntity, 'Entity SHOULD be saved in Deferred mode even if transport fails.');
        } catch (\Exception $e) {
            throw $e;
        }
    }

    #[RunInSeparateProcess]
    public function testDeferredModeWithFailOnErrorThrowsButPersistsData(): void
    {
        // Configure for Deferred Mode with fail_on_transport_error = true
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'fail_on_transport_error' => true,
                'transports' => ['doctrine' => true],
            ],
        ];

        try {
            self::bootKernel($options);
            $container = self::getContainer();

            /** @var EntityManagerInterface $em */
            $em = $container->get('doctrine.orm.entity_manager');
            $this->setupDatabase($em);

            // Create Entity
            $entity = new TestEntity('Deferred Fail Test');
            $em->persist($entity);

            // Flush - Expect Exception (but data should still be committed)
            $exceptionThrown = false;
            try {
                $em->flush();
            } catch (\RuntimeException $e) {
                $exceptionThrown = true;
                self::assertEquals('Transport failed intentionally.', $e->getMessage());
            }

            self::assertTrue($exceptionThrown, 'Flush should have thrown transport exception.');

            // Verify Entity IS in Database (data was committed before transport error)
            $em = $this->getFreshEntityManager($options);
            $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Fail Test']);
            self::assertNotNull(
                $savedEntity,
                'Entity SHOULD be saved in Deferred mode even if transport fails and exception is thrown.'
            );
        } catch (\Exception $e) {
            throw $e;
        }
    }
}
