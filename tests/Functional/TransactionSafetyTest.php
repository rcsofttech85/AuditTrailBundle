<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use RuntimeException;

/**
 * Tests transaction safety behavior for rollback and deferred persistence.
 *
 * These tests run in separate processes because they intentionally trigger
 * Doctrine transaction rollbacks (via ThrowingTransport). This rollback destroys
 * DAMA's internal savepoint (DAMA_TEST), corrupting the static connection state
 * for subsequent tests. Process isolation prevents this corruption from leaking.
 */
final class TransactionSafetyTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestKernel::$useThrowingTransport = true;
    }

    /** @param array<string, mixed> $options */
    private function getFreshEntityManager(array $options): \Doctrine\ORM\EntityManagerInterface
    {
        self::bootKernel($options);

        return $this->getEntityManager();
    }

    private function flushAndCaptureFailure(\Doctrine\ORM\EntityManagerInterface $em): RuntimeException
    {
        try {
            $em->flush();
            self::fail('Expected flush to throw a RuntimeException.');
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }

    public function testAtomicModeRollsBackDataOnTransportFailure(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'fail_on_transport_error' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Atomic Test');
        $em->persist($entity);

        $exception = $this->flushAndCaptureFailure($em);
        self::assertSame('Transport failed intentionally.', $exception->getMessage());

        $em = $this->getFreshEntityManager($options);
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Atomic Test']);
        self::assertNull($savedEntity, 'Entity should NOT be saved in Atomic mode if transport fails.');
    }

    public function testDeferredModePersistsDataEvenIfTransportFails(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'transports' => ['database' => ['enabled' => true]],
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

    public function testImmediateModeWithFallbackPersistsDataWithoutUnsafeNestedFlushFailure(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'fail_on_transport_error' => false,
                'fallback_to_database' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Immediate Fallback Test');
        $em->persist($entity);
        $em->flush();
        $em->clear();

        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Immediate Fallback Test']);
        self::assertNotNull(
            $savedEntity,
            'Entity should be saved when immediate transport fails but database fallback is enabled.'
        );

        $auditLogs = $em->getRepository(\Rcsofttech\AuditTrailBundle\Entity\AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $savedEntity->getId(),
        ]);
        self::assertNotEmpty($auditLogs, 'Fallback should still persist an audit log in immediate mode.');
    }

    public function testDeferredModeWithFailOnErrorThrowsButPersistsData(): void
    {
        $options = [
            'audit_config' => [
                'defer_transport_until_commit' => true,
                'fail_on_transport_error' => true,
                'transports' => ['database' => ['enabled' => true]],
            ],
        ];

        self::bootKernel($options);

        $em = $this->getEntityManager();

        $entity = new TestEntity('Deferred Fail Test');
        $em->persist($entity);

        $exception = $this->flushAndCaptureFailure($em);
        self::assertSame('Transport failed intentionally.', $exception->getMessage());

        $em->clear();
        $savedEntity = $em->getRepository(TestEntity::class)->findOneBy(['name' => 'Deferred Fail Test']);
        self::assertNotNull(
            $savedEntity,
            'Entity SHOULD be saved in Deferred mode even if transport fails and exception is thrown.'
        );
    }
}
