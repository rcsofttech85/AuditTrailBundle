<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Factory\AuditLogMessageFactory;
use Rcsofttech\AuditTrailBundle\MessageHandler\PersistAuditLogHandler;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Symfony\Component\Uid\Factory\UuidFactory;

use function usleep;

final class AsyncPersistOrderingTest extends AbstractFunctionalTestCase
{
    public function testAsyncPersistencePreservesOriginalAuditOrderingIds(): void
    {
        $entityManager = $this->getEntityManager();
        $registry = $this->getService('doctrine');
        if (!$registry instanceof ManagerRegistry) {
            self::fail('Doctrine registry is not available in the functional test container.');
        }

        $idResolver = self::createStub(EntityIdResolverInterface::class);
        $idResolver->method('resolve')->willReturn(null);
        $uuidFactory = self::getContainer()->get(UuidFactory::class);
        self::assertInstanceOf(UuidFactory::class, $uuidFactory);

        $factory = new AuditLogMessageFactory($idResolver, $uuidFactory);
        $handler = new PersistAuditLogHandler($registry, $this->getAuditLogWriter());

        $firstLog = new AuditLog(
            TestEntity::class,
            'first-entity',
            AuditAction::Update,
            createdAt: new DateTimeImmutable('2025-01-01T00:00:00+00:00'),
        );
        $firstMessage = $factory->createPersistMessage($this->createContext($entityManager, $firstLog));

        usleep(1000);

        $secondLog = new AuditLog(
            TestEntity::class,
            'second-entity',
            AuditAction::Update,
            createdAt: new DateTimeImmutable('2025-01-01T00:00:01+00:00'),
        );
        $secondMessage = $factory->createPersistMessage($this->createContext($entityManager, $secondLog));

        self::assertNotSame($firstMessage->auditId, $secondMessage->auditId);

        // Simulate the worker consuming the older message after the newer one.
        $handler($secondMessage);
        $handler($firstMessage);

        $entityManager->clear();

        $persistedLogs = $this->getAuditLogRepository()->findWithFilters([], 10);

        self::assertCount(2, $persistedLogs);
        self::assertSame(
            [$secondMessage->auditId, $firstMessage->auditId],
            array_map(static fn (AuditLog $log): ?string => $log->id?->toRfc4122(), $persistedLogs),
        );
    }

    private function createContext(EntityManagerInterface $entityManager, AuditLog $log): AuditTransportContext
    {
        return new AuditTransportContext(
            AuditPhase::PostFlush,
            $entityManager,
            $log,
        );
    }

    private function getAuditLogRepository(): AuditLogRepository
    {
        $repository = $this->getService(AuditLogRepository::class);
        if (!$repository instanceof AuditLogRepository) {
            self::fail('AuditLogRepository service is not available in the functional test container.');
        }

        return $repository;
    }

    private function getAuditLogWriter(): AuditLogWriterInterface
    {
        $writer = $this->getService(AuditLogWriterInterface::class);
        if (!$writer instanceof AuditLogWriterInterface) {
            self::fail('AuditLogWriterInterface service is not available in the functional test container.');
        }

        return $writer;
    }
}
