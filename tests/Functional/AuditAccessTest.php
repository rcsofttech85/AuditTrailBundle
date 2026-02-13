<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Post;

class AuditAccessTest extends AbstractFunctionalTestCase
{
    public function testAccessAuditLogIsCreatedOnRead(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        // Persist a test entity
        $post = new Post();
        $post->setTitle('Top Secret');
        $em->persist($post);
        $em->flush();
        $em->clear();

        $postId = $post->getId();

        //  Read the entity (Trigger postLoad)
        $loadedPost = $em->find(Post::class, $postId);
        self::assertNotNull($loadedPost);

        // Flush to ensure AuditLog is persisted to DB (since DoctrineTransport only persists, doesn't flush)
        $em->flush();

        // Verification: check if AuditLog with ACTION_ACCESS exists
        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy(['action' => AuditLogInterface::ACTION_ACCESS]);

        self::assertCount(1, $logs, 'Should have created exactly one access log');
        self::assertSame(AuditLogInterface::ACTION_ACCESS, $logs[0]->getAction());
        self::assertSame(Post::class, $logs[0]->getEntityClass());
        self::assertSame((string) $postId, $logs[0]->getEntityId());
        self::assertSame('Opening secret file', $logs[0]->getContext()['message'] ?? null);
    }
}
