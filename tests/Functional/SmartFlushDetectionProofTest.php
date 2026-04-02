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

final class SmartFlushDetectionProofTest extends AbstractFunctionalTestCase
{
    private int $flushCount = 0;

    /** @var list<array{phase: string, auditLogCount: int, hasPendingInserts: bool}> */
    private array $flushLog = [];

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

    public function testAutoIncrementEntityRequiresDoubleFlush(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();
        $this->attachFlushCounter($em);

        $entity = new TestEntity('auto-increment-proof');
        $em->persist($entity);

        $em->flush();

        self::assertGreaterThanOrEqual(2, $this->flushCount, sprintf(
            "Auto-increment entities should trigger at least 2 flush cycles.\n".
            "Actual: %d flush cycle(s)\n\nFlush log:\n%s",
            $this->flushCount,
            $this->formatFlushLog()
        ));

        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotEmpty($auditLogs, 'AuditLog should exist for auto-increment entity');

        $audit = $auditLogs[array_key_last($auditLogs)];
        self::assertNotSame('pending', $audit->entityId, 'Entity ID should be resolved, not PENDING');
        self::assertIsNumeric($audit->entityId, 'Auto-increment entity ID should be numeric');
    }

    public function testUuidEntityRequiresSingleFlush(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();
        $this->attachFlushCounter($em);

        $entity = new TestEntityWithUuid('uuid-proof');
        $em->persist($entity);

        $em->flush();

        self::assertSame(1, $this->flushCount, sprintf(
            "UUID entities should trigger exactly 1 flush cycle.\n".
            "Actual: %d flush cycle(s)\n\nFlush log:\n%s",
            $this->flushCount,
            $this->formatFlushLog()
        ));

        $auditLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntityWithUuid::class,
            'action' => 'create',
        ]);

        self::assertNotEmpty($auditLogs, 'AuditLog should exist for UUID entity');

        $audit = $auditLogs[array_key_last($auditLogs)];
        self::assertNotSame('pending', $audit->entityId, 'Entity ID should be resolved, not PENDING');
        self::assertTrue(
            \Symfony\Component\Uid\Uuid::isValid($audit->entityId),
            sprintf('Entity ID should be a valid UUID, got: %s', $audit->entityId)
        );
    }

    public function testBothStrategiesProduceIdenticalAuditData(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $autoEntity = new TestEntity('comparison-test');
        $em->persist($autoEntity);
        $em->flush();

        $uuidEntity = new TestEntityWithUuid('comparison-test');
        $em->persist($uuidEntity);
        $em->flush();

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

        self::assertSame('create', $autoAudit->action);
        self::assertSame('create', $uuidAudit->action);

        self::assertNotSame('pending', $autoAudit->entityId, 'Auto-increment ID should be resolved');
        self::assertNotSame('pending', $uuidAudit->entityId, 'UUID ID should be resolved');

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
