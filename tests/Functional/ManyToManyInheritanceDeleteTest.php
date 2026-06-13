<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\PersistentCollection;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\PendingAuditPlanMaterializer;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Club;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Membership;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\PremiumMembership;
use Rcsofttech\AuditTrailBundle\ValueObject\PendingAuditPlan;

use function array_map;
use function array_slice;

/**
 * Regression test for issue #105: deleting an entity that is the target of a
 * ManyToMany association whose target class uses inheritance crashed during
 * flush with "ResultSetMappingBuilder does not currently support your
 * inheritance scheme." because the delete impact analyzer read the owner
 * collection through Doctrine's Criteria fast-path, which ManyToManyPersister
 * does not support for inherited targets.
 */
final class ManyToManyInheritanceDeleteTest extends AbstractFunctionalTestCase
{
    public function testDeletingInheritedManyToManyTargetDoesNotCrashAndAuditsOwner(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $club = new Club('chess club');
        $memberships = [];
        foreach (['alice', 'bob', 'carol', 'dave', 'erin'] as $label) {
            $membership = new PremiumMembership($label);
            $club->addMembership($membership);
            $memberships[] = $membership;
        }

        $em->persist($club);
        $em->flush();

        $clubId = $club->getId();
        self::assertNotNull($clubId);

        $membershipIds = [];
        foreach ($memberships as $membership) {
            $membershipId = $membership->getId();
            self::assertNotNull($membershipId);
            $membershipIds[] = $membershipId;
        }

        $deletedMembershipId = $membershipIds[0];
        $expectedOldIds = array_map('strval', $membershipIds);
        $expectedNewIds = array_map('strval', array_slice($membershipIds, 1));

        $em->clear();

        $club = $em->find(Club::class, $clubId);
        $deletedMembership = $em->find(Membership::class, $deletedMembershipId);
        self::assertInstanceOf(Club::class, $club);
        self::assertInstanceOf(PremiumMembership::class, $deletedMembership);

        $clubMemberships = $club->getMemberships();
        self::assertInstanceOf(PersistentCollection::class, $clubMemberships);
        self::assertFalse($clubMemberships->isInitialized());

        // Before the fix this flush threw:
        // "ResultSetMappingBuilder does not currently support your inheritance scheme."
        $em->remove($deletedMembership);
        $em->flush();

        $clubAudit = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => Club::class,
            'entityId' => (string) $clubId,
            'action' => AuditAction::Update,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($clubAudit, 'Deleting a membership must create an update audit log for the related club.');
        $actualOldIds = $clubAudit->oldValues['memberships'] ?? null;
        $actualNewIds = $clubAudit->newValues['memberships'] ?? null;
        self::assertIsArray($actualOldIds);
        self::assertIsArray($actualNewIds);
        self::assertEqualsCanonicalizing($expectedOldIds, $actualOldIds);
        self::assertEqualsCanonicalizing($expectedNewIds, $actualNewIds);

        // The membership row is actually gone: four of the original five remain.
        self::assertSame(4, $em->getRepository(Membership::class)->count([]));
    }

    public function testMaterializingDeferredInheritedManyToManyCollectionDoesNotCrash(): void
    {
        TestKernel::$publicServiceIds = [PendingAuditPlanMaterializer::class];

        self::bootKernel();
        $em = $this->getEntityManager();

        $club = new Club('deferred audit club');
        $membership = new PremiumMembership('zara');
        $club->addMembership($membership);

        $em->persist($club);
        $em->flush();

        $clubId = $club->getId();
        $membershipId = $membership->getId();
        self::assertNotNull($clubId);
        self::assertNotNull($membershipId);

        $em->clear();

        $club = $em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);

        $clubMemberships = $club->getMemberships();
        self::assertInstanceOf(PersistentCollection::class, $clubMemberships);
        self::assertFalse($clubMemberships->isInitialized());
        self::assertFalse($clubMemberships->isDirty());

        $materializer = $this->getService(PendingAuditPlanMaterializer::class);
        self::assertInstanceOf(PendingAuditPlanMaterializer::class, $materializer);

        $audit = $materializer->materialize(
            PendingAuditPlan::forDeferredCollections(
                $club,
                AuditAction::Update,
                ['memberships' => []],
                [],
                ['memberships'],
            ),
            $em,
        );

        self::assertEqualsCanonicalizing([(string) $membershipId], $audit->newValues['memberships'] ?? null);
        self::assertTrue($clubMemberships->isInitialized());
    }

    public function testIdsOnlySerializationOfDeferredInheritedManyToManyCollectionReturnsIds(): void
    {
        TestKernel::$publicServiceIds = [ValueSerializerInterface::class];

        self::bootKernel([
            'audit_config' => [
                'collection_serialization_mode' => 'ids_only',
            ],
        ]);
        $em = $this->getEntityManager();

        $club = new Club('ids only club');
        $membership = new PremiumMembership('yara');
        $club->addMembership($membership);

        $em->persist($club);
        $em->flush();

        $clubId = $club->getId();
        $membershipId = $membership->getId();
        self::assertNotNull($clubId);
        self::assertNotNull($membershipId);

        $em->clear();

        $club = $em->find(Club::class, $clubId);
        self::assertInstanceOf(Club::class, $club);

        $clubMemberships = $club->getMemberships();
        self::assertInstanceOf(PersistentCollection::class, $clubMemberships);
        self::assertFalse($clubMemberships->isInitialized());

        $serializer = $this->getService(ValueSerializerInterface::class);
        self::assertInstanceOf(ValueSerializerInterface::class, $serializer);

        self::assertEqualsCanonicalizing([(string) $membershipId], $serializer->serializeAssociation($clubMemberships));
        self::assertTrue($clubMemberships->isInitialized());
    }
}
