<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AuditAccessCooldownTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function setupDatabase(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        // Clear cache
        $container = self::getContainer();
        if ($container->has('audit_test.cache')) {
            /** @var \Psr\Cache\CacheItemPoolInterface $cache */
            $cache = $container->get('audit_test.cache');
            $cache->clear();
        }
    }

    public function testRequestLevelDeduplication(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->setupDatabase($em);

        $post = new CooldownPost();
        $post->setTitle('Deduplication Test');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();

        // Load the entity multiple times in the same request
        $em->find(CooldownPost::class, $postId);
        $em->flush(); // Save first log

        $em->clear();
        $em->find(CooldownPost::class, $postId);
        $em->flush(); // Attempt second log (should be skipped)

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);

        self::assertCount(1, $logs, 'Should have only ONE access log due to request-level deduplication');
    }

    public function testPersistentCooldown(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->setupDatabase($em);

        $post = new CooldownPost();
        $post->setTitle('Cooldown Test');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();

        // First access
        $em->find(CooldownPost::class, $postId);
        $em->flush(); // Save first access log

        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);
        self::assertCount(1, $logs, 'First access should create exactly one log');

        // Shutdown and reboot to simulate new request
        self::ensureKernelShutdown();
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');

        // Second access (within cooldown)
        $em->find(CooldownPost::class, $postId);
        $em->flush();

        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);
        self::assertCount(1, $logs, 'Second access within cooldown should NOT create a new log');
    }
}
