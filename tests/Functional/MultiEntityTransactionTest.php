<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class MultiEntityTransactionTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = parent::createKernel($options);
        if ($kernel instanceof TestKernel && isset($options['audit_config'])) {
            assert(is_array($options['audit_config']));
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

    #[RunInSeparateProcess]
    public function testMultipleEntitiesInSingleTransactionHaveSameHash(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

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

    #[RunInSeparateProcess]
    public function testMultipleFlushesHaveDifferentHashes(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

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
