<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;

class MultiEntityTransactionTest extends AbstractFunctionalTestCase
{
    public function testMultipleEntitiesInSingleTransactionHaveSameHash(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity1 = new TestEntity('Entity 1');
        $entity2 = new TestEntity('Entity 2');

        $em->persist($entity1);
        $em->persist($entity2);
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(2, $auditLogs);

        self::assertSame($auditLogs[0]->getTransactionHash(), $auditLogs[1]->getTransactionHash());
        self::assertNotEmpty($auditLogs[0]->getTransactionHash());
    }

    public function testMultipleFlushesHaveDifferentHashes(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity1 = new TestEntity('Entity 1');
        $em->persist($entity1);
        $em->flush();

        $entity2 = new TestEntity('Entity 2');
        $em->persist($entity2);
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(2, $auditLogs);

        self::assertNotSame($auditLogs[0]->getTransactionHash(), $auditLogs[1]->getTransactionHash());
    }
}
