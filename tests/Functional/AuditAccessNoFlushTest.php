<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Post;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

use function assert;

class AuditAccessNoFlushTest extends KernelTestCase
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
    }

    public function testAccessLogNotSavedWithoutFlush(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->setupDatabase($em);

        // Persist a test entity
        $post = new Post();
        $post->setTitle('Top Secret');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();

        // Clear current logs
        $em->createQuery('DELETE FROM Rcsofttech\AuditTrailBundle\Entity\AuditLog')->execute();

        $loadedPost = $em->find(Post::class, $postId);
        self::assertNotNull($loadedPost);

        $subscriber = $container->get(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $container->get('kernel');
        assert($kernel instanceof \Symfony\Component\HttpKernel\HttpKernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            new Request(),
            new Response()
        ));

        // Verification: check if AuditLog exists
        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findAll();

        self::assertCount(1, $logs, 'Should have created one access log after kernel termination');
    }
}
