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

class ConfigurationInteractionTest extends KernelTestCase
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
    public function testIgnoredEntityTakesPrecedenceOverAuditableAttribute(): void
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

        // TestEntity has #[Auditable(enabled: true)]
        $entity = new TestEntity('Should be ignored');
        $em->persist($entity);
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(0, $auditLogs, 'Audit log should NOT be created because entity is in ignored_entities');
    }

    #[RunInSeparateProcess]
    public function testGlobalIgnoredPropertiesApplyToAllEntities(): void
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

        $em->clear();
        $entity = $em->find(TestEntity::class, $entity->getId());
        assert($entity instanceof TestEntity);
        $entity->setName('Updated');
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findBy(['action' => 'update']);
        self::assertCount(0, $auditLogs, 'Update log should NOT be created because "name" is globally ignored');
    }

    #[RunInSeparateProcess]
    public function testAttributeIgnoredPropertiesAreRespectedDuringUpdate(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new Entity\TestEntityWithIgnored('Initial');
        $entity->setIgnoredProp('secret');
        $em->persist($entity);
        $em->flush();

        // Update ignored property
        $entity->setIgnoredProp('new secret');
        $em->flush();

        $auditLogs = $em->getRepository(AuditLog::class)->findBy(['action' => 'update']);
        self::assertCount(
            0,
            $auditLogs,
            'Update log should NOT be created because "ignoredProp" is ignored in the attribute'
        );
    }
}
