<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use RuntimeException;

/**
 * Tests transaction safety behavior - atomic mode rollback vs deferred mode persistence.
 *
 * These tests MUST run in separate processes because they intentionally trigger
 * Doctrine transaction rollbacks (via ThrowingTransport). This rollback destroys
 * DAMA's internal savepoint (DAMA_TEST), corrupting the static connection state
 * for subsequent tests. Process isolation prevents this corruption from leaking.
 */
final class TransactionSafetyTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Enable throwing transport for this test final class specifically
        TestKernel::$useThrowingTransport = true;
    }

    /**
     * Get a fresh EntityManager after a failed flush (which closes the EM).
     *
     * @param array<string, mixed> $options
     */
    private function getFreshEntityManager(array $options): \Doctrine\ORM\EntityManagerInterface
    {
        self::bootKernel($options);

        return $this->getEntityManager();
    }

    public function testAtomicModeRollsBackDataOnTransportFailure(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'fail_on_transport_error' => true,
                'transports' => ['doctrine' => true],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Atomic Test');
        $em->persist($entity);

        $exceptionThrown = false;
        try {
            $em->flush();
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            self::assertEquals('Transport failed intentionally.', $e->getMessage());
        }

        self::assertTrue($exceptionThrown, 'Flush should have failed due to transport exception.');

        // After failed flush, EM is closed. Get a fresh one.
        $em = $this->getFreshEntityManager($options);
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Atomic Test']);
        self::assertNull($savedEntity, 'Entity should NOT be saved in Atomic mode if transport fails.');
    }

    public function testDeferredModePersistsDataEvenIfTransportFails(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'transports' => ['doctrine' => true],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Deferred Test');
        $em->persist($entity);
        $em->flush();

        $em->clear();
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Test']);
        self::assertNotNull($savedEntity, 'Entity SHOULD be saved in Deferred mode even if transport fails.');
    }

    public function testDeferredModeWithFailOnErrorThrowsButPersistsData(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'fail_on_transport_error' => true,
                'transports' => ['doctrine' => true],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Deferred Fail Test');
        $em->persist($entity);

        $exceptionThrown = false;
        try {
            $em->flush();
        } catch (RuntimeException $e) {
            $exceptionThrown = true;
            self::assertEquals('Transport failed intentionally.', $e->getMessage());
        }

        self::assertTrue($exceptionThrown, 'Flush should have thrown transport exception.');

        $em->clear();
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Fail Test']);
        self::assertNotNull(
            $savedEntity,
            'Entity SHOULD be saved in Deferred mode even if transport fails and exception is thrown.'
        );
    }
}
