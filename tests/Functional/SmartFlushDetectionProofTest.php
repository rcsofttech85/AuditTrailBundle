<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntityWithUuid;

use function sprintf;

/**
 * PROOF TEST: Demonstrates that UUID entities use single flush (optimized path)
 * while auto-increment entities use double flush (required for ID resolution).
 *
 * This test instruments Doctrine's event system to count EXACTLY how many
 * flush cycles occur for each entity type, proving the smart flush detection
 * in EntityProcessor::dispatchOrSchedule() works correctly.
 */
final class SmartFlushDetectionProofTest extends AbstractFunctionalTestCase
{
    private int $flushCount = 0;

    /** @var list<array{phase: string, auditLogCount: int, hasPendingInserts: bool}> */
    private array $flushLog = [];

    /**
     * Attach a Doctrine event listener that counts flush cycles.
     * Each $em->flush() triggers one onFlush + one postFlush event pair.
     */
    private function attachFlushCounter(EntityManagerInterface $em): void
    {
        $this->flushCount = 0;
        $this->flushLog = [];

        $listener = new class($this) {
            public function __construct(private SmartFlushDetectionProofTest $test)
            {
            }

            public function onFlush(OnFlushEventArgs $args): void
            {
                $em = $args->getObjectManager();
                $uow = $em->getUnitOfWork();

                // Count how many AuditLog entities are being persisted in this flush
                $auditLogCount = 0;
                foreach ($uow->getScheduledEntityInsertions() as $entity) {
                    if ($entity instanceof AuditLog) {
                        ++$auditLogCount;
                    }
                }

                $this->test->recordFlush('onFlush', $auditLogCount, $uow->getScheduledEntityInsertions() !== []);
            }

            public function postFlush(PostFlushEventArgs $args): void
            {
                $this->test->recordFlush('postFlush', 0, false);
            }
        };

        // Register with very low priority so it runs AFTER the AuditSubscriber
        $em->getEventManager()->addEventListener([Events::onFlush], $listener);
        $em->getEventManager()->addEventListener([Events::postFlush], $listener);
    }

    /**
     * @internal Called by the counting listener
     */
    public function recordFlush(string $phase, int $auditLogCount, bool $hasPendingInserts): void
    {
        if ($phase === 'onFlush') {
            ++$this->flushCount;
        }

        $this->flushLog[] = [
            'phase' => $phase,
            'auditLogCount' => $auditLogCount,
            'hasPendingInserts' => $hasPendingInserts,
        ];
    }

    /**
     * PROOF: Auto-increment entity INSERT requires 2 flushes.
     *
     * Flow:
     * 1. $em->flush() — Flush #1: TestEntity INSERT + AuditLog scheduled (PENDING_ID)
     * 2. postFlush: Resolve real entity ID → persist AuditLog → Flush #2
     */
    public function testAutoIncrementEntityRequiresDoubleFlush(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();
        $this->attachFlushCounter($em);

        // Create an auto-increment entity (ID = null until INSERT)
        $entity = new TestEntity('auto-increment-proof');
        $em->persist($entity);

        // This single application flush call...
        $em->flush();

        // ...actually triggers TWO flush cycles
        self::assertGreaterThanOrEqual(2, $this->flushCount, sprintf(
            "PROOF FAILED: Auto-increment entity should trigger >= 2 flush cycles.\n".
            "Expected: >= 2 (Flush #1 for entity, Flush #2 for AuditLog with resolved ID)\n".
            "Actual:   %d flush cycle(s)\n\nFlush log:\n%s",
            $this->flushCount,
            $this->formatFlushLog()
        ));

        // Verify audit was still created correctly
        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotEmpty($auditLogs, 'AuditLog should exist for auto-increment entity');

        $audit = $auditLogs[array_key_last($auditLogs)];
        self::assertNotEquals('pending', $audit->entityId, 'Entity ID should be resolved, not PENDING');
        self::assertIsNumeric($audit->entityId, 'Auto-increment entity ID should be numeric');
    }

    /**
     * PROOF: UUID entity INSERT requires only 1 flush.
     *
     * Flow:
     * 1. $em->flush() — Flush #1: TestEntityWithUuid INSERT + AuditLog INSERT
     *    (both in same UoW because UUID is known client-side)
     * 2. No Flush #2 needed!
     */
    public function testUuidEntityRequiresSingleFlush(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();
        $this->attachFlushCounter($em);

        // Create a UUID entity (ID generated client-side, BEFORE the INSERT)
        $entity = new TestEntityWithUuid('uuid-proof');
        $em->persist($entity);

        // This single application flush call...
        $em->flush();

        // ...triggers only ONE flush cycle (smart detection recognized UUID)
        self::assertSame(1, $this->flushCount, sprintf(
            "PROOF FAILED: UUID entity should trigger exactly 1 flush cycle.\n".
            "Expected: 1 (AuditLog included in same UoW as entity)\n".
            "Actual:   %d flush cycle(s)\n\nFlush log:\n%s",
            $this->flushCount,
            $this->formatFlushLog()
        ));

        // Verify audit was still created correctly
        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntityWithUuid::class,
            'action' => 'create',
        ]);

        self::assertNotEmpty($auditLogs, 'AuditLog should exist for UUID entity');

        $audit = $auditLogs[array_key_last($auditLogs)];
        self::assertNotEquals('pending', $audit->entityId, 'Entity ID should be resolved, not PENDING');
        self::assertTrue(
            \Symfony\Component\Uid\Uuid::isValid($audit->entityId),
            sprintf('Entity ID should be a valid UUID, got: %s', $audit->entityId)
        );
    }

    /**
     * PROOF: Both entity types produce identical audit data — only flush count differs.
     */
    public function testBothStrategiesProduceIdenticalAuditData(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        // Create auto-increment entity
        $autoEntity = new TestEntity('comparison-test');
        $em->persist($autoEntity);
        $em->flush();

        // Create UUID entity
        $uuidEntity = new TestEntityWithUuid('comparison-test');
        $em->persist($uuidEntity);
        $em->flush();

        // Fetch audits for both
        $autoAudit = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);
        $uuidAudit = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntityWithUuid::class,
            'action' => 'create',
        ]);

        self::assertNotNull($autoAudit, 'Auto-increment audit should exist');
        self::assertNotNull($uuidAudit, 'UUID audit should exist');

        // Both should have the same structure
        self::assertSame('create', $autoAudit->action);
        self::assertSame('create', $uuidAudit->action);

        // Both should have resolved (non-pending) entity IDs
        self::assertNotEquals('pending', $autoAudit->entityId, 'Auto-increment ID should be resolved');
        self::assertNotEquals('pending', $uuidAudit->entityId, 'UUID ID should be resolved');

        // Both should have newValues containing the name
        self::assertIsArray($autoAudit->newValues);
        self::assertIsArray($uuidAudit->newValues);
        self::assertArrayHasKey('name', $autoAudit->newValues);
        self::assertArrayHasKey('name', $uuidAudit->newValues);
        self::assertSame('comparison-test', $autoAudit->newValues['name']);
        self::assertSame('comparison-test', $uuidAudit->newValues['name']);
    }

    private function formatFlushLog(): string
    {
        $lines = [];
        foreach ($this->flushLog as $i => $entry) {
            $lines[] = sprintf(
                '  [%d] %s — AuditLogs in UoW: %d, Has pending inserts: %s',
                $i,
                $entry['phase'],
                $entry['auditLogCount'],
                $entry['hasPendingInserts'] ? 'yes' : 'no'
            );
        }

        return implode("\n", $lines);
    }
}
