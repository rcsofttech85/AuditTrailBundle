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

final class AuditAccessMutationSuppressionTest extends AbstractFunctionalTestCase
{
    public function testAccessLogIsSuppressedWhenEntityIsMutatedInSameRequest(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $request = Request::create('/', 'GET');
        $requestStack->push($request);

        $post = new Post();
        $post->setTitle('Original Title');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();
        self::assertNotNull($postId);

        $loadedPost = $em->find(Post::class, $postId);
        self::assertNotNull($loadedPost);

        $loadedPost->setTitle('Updated Title');
        $em->flush();

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $request,
            new Response()
        ));

        /** @var AuditLog[] $accessLogs */
        $accessLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Post::class,
            'entityId' => (string) $postId,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Post::class,
            'entityId' => (string) $postId,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertCount(0, $accessLogs, 'Mutating a loaded entity in the same request must not produce an access log.');
        self::assertCount(1, $updateLogs, 'The actual entity update should still be audited.');
    }
}
