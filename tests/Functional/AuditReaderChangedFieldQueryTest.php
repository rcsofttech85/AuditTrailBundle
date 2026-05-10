<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Query\AuditChangedFieldMatcher;
use Rcsofttech\AuditTrailBundle\Query\AuditQuery;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryExecutor;
use Rcsofttech\AuditTrailBundle\Query\AuditQueryFilterFactory;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\DateTimePost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\PostStatus;

use function assert;

final class AuditReaderChangedFieldQueryTest extends AbstractFunctionalTestCase
{
    public function testChangedFieldQueryUsesNativeSqliteFilteringForCountsAndResults(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();
        $repository = $this->getService(AuditLogRepository::class);
        assert($repository instanceof AuditLogRepository);

        $query = new AuditQuery(
            new AuditQueryExecutor(
                $repository,
                new AuditQueryFilterFactory(),
                new AuditChangedFieldMatcher(),
            ),
        );

        $post = new DateTimePost();
        $post->setTitle('Initial Title');
        $em->persist($post);
        $em->flush();

        $post->setTitle('Renamed Title');
        $em->flush();

        $post->setPublishedAt(new DateTimeImmutable('2026-01-01T00:00:00+00:00'));
        $em->flush();

        $post->setStatus(PostStatus::PUBLISHED);
        $em->flush();

        $postId = $post->getId();
        self::assertNotNull($postId);

        $statusResults = $query
            ->entity(DateTimePost::class, (string) $postId)
            ->changedField('status')
            ->getResults();
        $statusEntry = $statusResults->first();

        self::assertCount(1, $statusResults);
        self::assertNotNull($statusEntry);
        self::assertSame(AuditAction::Update->value, $statusEntry->action);
        self::assertSame(['status'], $statusEntry->changedFields);
        self::assertSame(PostStatus::PUBLISHED->value, $statusEntry->newValues['status'] ?? null);

        self::assertSame(
            2,
            $query
                ->entity(DateTimePost::class, (string) $postId)
                ->changedField('publishedAt', 'status')
                ->count(),
        );

        self::assertTrue(
            $query
                ->entity(DateTimePost::class, (string) $postId)
                ->changedField('title')
                ->exists(),
        );
    }
}
