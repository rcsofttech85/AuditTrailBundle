<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Bridge\Doctrine\ManagerRegistry;

class AuditLogRepositoryTest extends TestCase
{
    public function testFindByTransactionHash(): void
    {
        $registry = self::createStub(ManagerRegistry::class);
        $entityManager = self::createStub(EntityManagerInterface::class);
        $classMetadata = self::createStub(ClassMetadata::class);
        $queryBuilder = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);

        $registry->method('getManagerForClass')->willReturn($entityManager);
        $entityManager->method('getClassMetadata')->willReturn($classMetadata);
        $classMetadata->name = AuditLog::class;

        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);

        // Mock the QueryBuilder chain
        $queryBuilder->expects($this->once())->method('select')->willReturn($queryBuilder);
        $queryBuilder->expects($this->once())->method('from')->willReturn($queryBuilder);
        $queryBuilder->expects($this->once())->method('where')->willReturn($queryBuilder);
        $queryBuilder->expects($this->once())->method('setParameter')->willReturn($queryBuilder);
        $queryBuilder->expects($this->once())->method('orderBy')->willReturn($queryBuilder);
        $queryBuilder->expects($this->once())->method('getQuery')->willReturn($query);
        $query->expects($this->once())->method('getResult')->willReturn([]);

        $repository = new AuditLogRepository($registry);
        $result = $repository->findByTransactionHash('abc-123');

        self::assertSame([], $result);
    }
}
