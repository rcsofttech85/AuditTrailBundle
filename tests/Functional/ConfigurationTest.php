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

class ConfigurationTest extends KernelTestCase
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
    public function testBundleDisabled(): void
    {
        $options = [
            'audit_config' => [
                'enabled' => false,
            ],
        ];

        self::bootKernel($options);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Disabled Test');
        $em->persist($entity);
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(0, $auditLogs, 'No audit logs should be created when bundle is disabled.');
    }

    #[RunInSeparateProcess]
    public function testIgnoredEntities(): void
    {
        $options = [
            'audit_config' => [
                'ignored_entities' => [TestEntity::class],
            ],
        ];

        self::bootKernel($options);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Ignored Test');
        $em->persist($entity);
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(0, $auditLogs, 'No audit logs should be created for ignored entities.');
    }

    #[RunInSeparateProcess]
    public function testIgnoredProperties(): void
    {
        $options = [
            'audit_config' => [
                'ignored_properties' => ['name'],
            ],
        ];

        self::bootKernel($options);
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Initial');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Changed');
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findBy(['action' => 'update']);
        self::assertCount(0, $auditLogs, 'No audit log should be created if only ignored properties changed.');
    }
}
