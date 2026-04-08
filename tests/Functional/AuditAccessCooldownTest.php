<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;

final class AuditAccessCooldownTest extends AbstractFunctionalTestCase
{
    public function testRequestLevelDeduplication(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();
        $this->clearTestCache();

        // Simulate a GET request to allow access logs
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/', 'GET');
        $requestStack->push($request);

        $post = new CooldownPost();
        $post->setTitle('Deduplication Test');
        $em->persist($post);
        $em->flush();
        $em->clear();
        $this->clearTestCache(); // Clear cache after creation to test fresh access logging

        $postId = $post->getId();

        // Load the entity multiple times in the same request
        $em->find(CooldownPost::class, $postId);
        $em->clear();
        $em->find(CooldownPost::class, $postId);

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $request,
            new Response()
        ));

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
        self::bootKernel();
        $em = $this->getEntityManager();
        $this->clearTestCache();

        // Simulate a GET request to allow access logs
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/', 'GET');
        $requestStack->push($request);

        $post = new CooldownPost();
        $post->setTitle('Cooldown Test');
        $em->persist($post);
        $em->flush();
        $em->clear();
        $this->clearTestCache(); // Clear cache after creation to test fresh access logging

        $postId = $post->getId();

        // First access
        $em->find(CooldownPost::class, $postId);

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $request,
            new Response()
        ));

        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);
        self::assertCount(1, $logs, 'First access should create exactly one log');

        // Shutdown and reboot to simulate new request
        self::bootKernel();
        $em = $this->getEntityManager();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $secondRequest = Request::create('/', 'GET');
        $requestStack->push($secondRequest);

        // Second access (within cooldown)
        $em->find(CooldownPost::class, $postId);

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $secondRequest,
            new Response()
        ));

        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);
        self::assertCount(1, $logs, 'Second access within cooldown should NOT create a new log');
    }
}
