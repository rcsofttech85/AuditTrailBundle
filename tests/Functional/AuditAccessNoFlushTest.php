<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Post;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;

class AuditAccessNoFlushTest extends AbstractFunctionalTestCase
{
    public function testAccessLogNotSavedWithoutFlush(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        // Simulate a GET request to allow access logs
        $request = Request::create('/', 'GET');
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push($request);

        // Persist a test entity
        $post = new Post();
        $post->setTitle('Top Secret');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();

        $loadedPost = $em->find(Post::class, $postId);
        self::assertNotNull($loadedPost);

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $request,
            new Response()
        ));

        // Verification: check if AuditLog exists
        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);

        self::assertCount(1, $logs, 'Should have created one access log after kernel termination');
    }
}
