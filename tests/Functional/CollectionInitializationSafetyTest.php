<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\PersistentCollection;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\LazyChild;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\LazyParent;

/**
 * Guards against the audit pass initializing an uninitialized collection while
 * resolving collection changes during flush.
 *
 * When an owning entity's to-many collection is `EXTRA_LAZY`, `count()` issues a
 * fresh `SELECT COUNT(*)` while the collection is uninitialized, but returns the
 * frozen in-memory size once it has been initialized. If the audit change
 * resolver iterates the live collection to compute the recorded ids, it forces
 * Doctrine to initialize it from the pre-commit database state mid-flush. The
 * collection then stays frozen at that early size and every later `count()` in
 * the same request is wrong. The resolver must instead compute ids from the
 * snapshot plus the pending insert/delete diffs and leave the collection lazy.
 */
final class CollectionInitializationSafetyTest extends AbstractFunctionalTestCase
{
    public function testAuditKeepsExtraLazyCollectionCountAccurateAcrossFlushes(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $parent = new LazyParent('parent');
        $em->persist($parent);
        $em->flush();

        $parentId = $parent->getId();
        self::assertNotNull($parentId);

        // Reload so the EXTRA_LAZY collection comes back lazy/uninitialized.
        $em->clear();
        $parent = $em->find(LazyParent::class, $parentId);
        self::assertInstanceOf(LazyParent::class, $parent);
        $children = $parent->getChildren();
        self::assertInstanceOf(PersistentCollection::class, $children);
        self::assertFalse(
            $children->isInitialized(),
            'precondition: a freshly loaded EXTRA_LAZY collection is uninitialized',
        );

        // First flush: add ONE child through the collection API. add() marks the
        // collection dirty without initializing it (no contains() guard), which is
        // exactly the state the audit resolver receives — uninitialized with a
        // pending insert diff. The audit pass runs during this flush.
        $firstChild = new LazyChild('child-1', $parent);
        $parent->getChildren()->add($firstChild);
        $em->persist($firstChild);
        $em->flush();

        // Later flushes: add the remaining children through the OWNING side only,
        // so the inverse collection is never touched again. If the audit pass froze
        // it at its first-flush size above, it can no longer grow.
        foreach (['child-2', 'child-3', 'child-4', 'child-5'] as $name) {
            $em->persist(new LazyChild($name, $parent));
            $em->flush();
        }

        // Sanity: the database really holds all five rows.
        self::assertSame(
            5,
            $em->getRepository(LazyChild::class)->count(['parent' => $parent]),
        );

        // The bug signal: with the collection wrongly initialized mid-flush, this
        // count() returns the frozen in-memory size (1) instead of a fresh
        // COUNT(*) (5).
        self::assertCount(
            5,
            $parent->getChildren(),
            'audit must not freeze the uninitialized EXTRA_LAZY collection at its mid-flush size',
        );
    }
}
