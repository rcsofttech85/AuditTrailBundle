<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Post;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class AuditAccessTest extends KernelTestCase
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

    public function testAccessAuditLogIsCreatedOnRead(): void
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

        //  Clear current logs (though fresh DB should be empty, good practice)
        $em->createQuery('DELETE FROM Rcsofttech\AuditTrailBundle\Entity\AuditLog')->execute();

        //  Read the entity (Trigger postLoad)
        $loadedPost = $em->find(Post::class, $postId);
        self::assertNotNull($loadedPost);

        // Flush to ensure AuditLog is persisted to DB (since DoctrineTransport only persists, doesn't flush)
        $em->flush();

        // Verification: check if AuditLog with ACTION_ACCESS exists
        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findAll();

        self::assertCount(1, $logs, 'Should have created exactly one access log');
        self::assertSame(AuditLogInterface::ACTION_ACCESS, $logs[0]->getAction());
        self::assertSame(Post::class, $logs[0]->getEntityClass());
        self::assertSame((string) $postId, $logs[0]->getEntityId());
        self::assertSame('Opening secret file', $logs[0]->getContext()['message'] ?? null);
    }
}
