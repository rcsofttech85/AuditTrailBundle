<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditAccessCooldownTest extends AbstractFunctionalTestCase
{
    public function testRequestLevelDeduplication(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();
        $this->clearTestCache();

        // Simulate a GET request to allow access logs
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/', 'GET'));

        $post = new CooldownPost();
        $post->setTitle('Deduplication Test');
        $em->persist($post);
        $em->flush();
        $em->clear();
        $this->clearTestCache(); // Clear cache after creation to test fresh access logging

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
        // Persistent cooldown test
        $this->bootTestKernel();
        $em = $this->getEntityManager();
        $this->clearTestCache();

        // Simulate a GET request to allow access logs
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/', 'GET'));

        $post = new CooldownPost();
        $post->setTitle('Cooldown Test');
        $em->persist($post);
        $em->flush();
        $em->clear();
        $this->clearTestCache(); // Clear cache after creation to test fresh access logging

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
        $this->bootTestKernel();
        $em = $this->getEntityManager();

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
