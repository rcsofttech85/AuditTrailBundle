<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Author;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\ConditionalPost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\DateTimePost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\PostStatus;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\SensitivePost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Tag;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntityWithIgnored;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function assert;
use function in_array;
use function strlen;

/**
 * End-to-end verification of every feature in the README.
 *
 * Each test method corresponds to a specific README claim and validates it
 * with real Doctrine operations against an in-memory SQLite database.
 */
class PressureItem
{
    public string $foo = 'bar';
}

class FeatureVerificationTest extends AbstractFunctionalTestCase
{
    // ─── F2. Update Tracking ────────────────────────────────────────────

    public function testF2EntityUpdateProducesAuditLogWithOldAndNewValues(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Before Update');
        $em->persist($entity);
        $em->flush();

        $entity->setName('After Update');
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotNull($log, 'Update action should produce an audit log');
        self::assertNotNull($log->oldValues, 'oldValues should be populated on update');
        self::assertNotNull($log->newValues, 'newValues should be populated on update');
        self::assertSame('Before Update', $log->oldValues['name'] ?? null);
        self::assertSame('After Update', $log->newValues['name'] ?? null);
        self::assertNotNull($log->changedFields, 'changedFields should be populated on update');
        self::assertContains('name', $log->changedFields);
    }

    // ─── F3. Delete Tracking ────────────────────────────────────────────

    public function testF3EntityDeleteProducesAuditLog(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('To Delete');
        $em->persist($entity);
        $em->flush();

        $entityId = (string) $entity->getId();

        $em->remove($entity);
        $em->flush();

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntity::class,
            'entityId' => $entityId,
        ]);

        $deleteLog = null;
        foreach ($logs as $l) {
            if (in_array($l->action, [AuditLogInterface::ACTION_DELETE, AuditLogInterface::ACTION_SOFT_DELETE], true)) {
                $deleteLog = $l;
                break;
            }
        }

        self::assertNotNull($deleteLog, 'Delete action should produce an audit log');
        self::assertSame($entityId, $deleteLog->entityId);
    }

    // ─── F4. Ignored Properties ─────────────────────────────────────────

    public function testF4IgnoredPropertyChangesDoNotProduceAuditLog(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntityWithIgnored('Ignored Test');
        $em->persist($entity);
        $em->flush();

        // Change only the ignored property
        $entity->setIgnoredProp('should-not-audit');
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => TestEntityWithIgnored::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertCount(0, $updateLogs, 'Changes to ignored properties should NOT produce an update audit log');
    }

    public function testF4IgnoredPropertyExcludedFromCreateValues(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntityWithIgnored('Ignored Create');
        $entity->setIgnoredProp('secret-internal');
        $em->persist($entity);
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntityWithIgnored::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($log);
        self::assertNotNull($log->newValues);
        self::assertArrayNotHasKey('ignoredProp', $log->newValues, 'Ignored property should NOT appear in newValues');
        self::assertArrayHasKey('name', $log->newValues, 'Non-ignored property should appear in newValues');
    }

    // ─── F5. Sensitive Data Masking ─────────────────────────────────────

    public function testF5SensitiveFieldIsMaskedInAuditLog(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new SensitivePost();
        $entity->setTitle('Public Title');
        $entity->setSecret('super-secret-value');
        $em->persist($entity);
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => SensitivePost::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($log, 'Create action should produce an audit log');
        self::assertNotNull($log->newValues);
        self::assertArrayHasKey('secret', $log->newValues, 'Secret field should be present in newValues');
        self::assertSame('****', $log->newValues['secret'], 'Secret field should be masked with ****');
        self::assertSame('Public Title', $log->newValues['title'], 'Non-sensitive field should NOT be masked');
    }

    // ─── F6. Conditional Auditing ───────────────────────────────────────

    public function testF6ConditionalAuditingSkipsWhenExpressionIsFalse(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        // This entity should NOT be audited because title == 'skip-me'
        $skipped = new ConditionalPost();
        $skipped->setTitle('skip-me');
        $em->persist($skipped);
        $em->flush();

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => ConditionalPost::class,
        ]);

        self::assertCount(0, $logs, 'Entity with title "skip-me" should NOT be audited');
    }

    public function testF6ConditionalAuditingAllowsWhenExpressionIsTrue(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        // This entity SHOULD be audited because title != 'skip-me'
        $allowed = new ConditionalPost();
        $allowed->setTitle('audit-me');
        $em->persist($allowed);
        $em->flush();

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => ConditionalPost::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertCount(1, $logs, 'Entity with title "audit-me" SHOULD be audited');
    }

    // ─── F7. DateTime Field Handling ────────────────────────────────────

    public function testF7DateTimeFieldSerializedCorrectlyOnCreate(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new DateTimePost();
        $entity->setTitle('DateTime Test');
        $entity->setPublishedAt(new DateTimeImmutable('2026-01-15 10:30:00'));
        $em->persist($entity);
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($log, 'Create action should produce an audit log');
        self::assertNotNull($log->newValues);
        self::assertArrayHasKey('publishedAt', $log->newValues, 'DateTime field should be in newValues');
        // The serialized value should be a string (ATOM format), not an object
        self::assertIsString($log->newValues['publishedAt'], 'DateTime should be serialized as string');
    }

    public function testF7DateTimeFieldUpdateProducesValidSignature(): void
    {
        $options = [
            'audit_config' => [
                'integrity' => ['enabled' => true, 'secret' => 'test-secret'],
            ],
        ];

        $this->bootTestKernel($options);
        $em = $this->getEntityManager();

        $entity = new DateTimePost();
        $entity->setTitle('Signed DateTime');
        $entity->setPublishedAt(new DateTimeImmutable('2026-01-15 10:30:00'));
        $em->persist($entity);
        $em->flush();

        $entity->setPublishedAt(new DateTimeImmutable('2026-02-20 14:00:00'));
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotNull($log, 'Update should produce an audit log');
        self::assertNotNull($log->signature, 'Log should be signed when integrity is enabled');
        self::assertSame(64, strlen($log->signature), 'Signature should be a valid SHA256 hex string');
    }

    // ─── F8. Deep Collection Tracking ───────────────────────────────────

    public function testF8ManyToManyAddIsTracked(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $author = new Author('Jane Doe');
        $tag1 = new Tag('php');
        $tag2 = new Tag('symfony');
        $em->persist($tag1);
        $em->persist($tag2);
        $em->persist($author);
        $em->flush();

        // Now add tags to author
        $author->addTag($tag1);
        $author->addTag($tag2);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotEmpty($updateLogs, 'ManyToMany add should produce an update audit log');

        $collectionLog = $updateLogs[array_key_last($updateLogs)];
        self::assertNotNull($collectionLog->newValues, 'newValues should contain collection changes');
        self::assertArrayHasKey('tags', $collectionLog->newValues, 'tags field should be tracked');
    }

    public function testF8ManyToManyRemoveIsTracked(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $author = new Author('John Doe');
        $tag = new Tag('laravel');
        $author->addTag($tag);
        $em->persist($author);
        $em->flush();

        // Remove the tag
        $author->removeTag($tag);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        // Find the removal log (should have old IDs with the tag, new IDs without)
        $removalLog = null;
        foreach ($updateLogs as $l) {
            if ($l->oldValues !== null && isset($l->oldValues['tags']) && isset($l->newValues['tags'])) {
                $removalLog = $l;
                break;
            }
        }

        self::assertNotNull($removalLog, 'ManyToMany remove should produce an update audit log with old/new tags');
    }

    // ─── F9. Safe Revert Support ────────────────────────────────────────

    public function testF9RevertUpdateRestoresOldValues(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Original Name');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Modified Name');
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotNull($updateLog, 'Update log should exist before revert');

        // Perform the revert
        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        $changes = $reverter->revert($updateLog);

        self::assertArrayHasKey('name', $changes, 'Revert should report the name field as changed');

        // Verify the entity was actually reverted
        $em->clear();
        $reverted = $em->find(TestEntity::class, $entity->getId());
        self::assertNotNull($reverted);
        self::assertSame('Original Name', $reverted->getName(), 'Entity should be reverted to original name');

        // Verify a revert audit log was created
        $revertLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_REVERT,
        ]);
        self::assertNotNull($revertLog, 'Revert should produce its own audit log');
    }

    public function testF9RevertDateTimeUpdateWithIntegrityDoesNotCrash(): void
    {
        $options = [
            'audit_config' => [
                'integrity' => ['enabled' => true, 'secret' => 'test-secret'],
            ],
        ];

        $this->bootTestKernel($options);
        $em = $this->getEntityManager();

        // Create entity with DateTime field
        $entity = new DateTimePost();
        $entity->setTitle('Revert DateTime');
        $entity->setPublishedAt(new DateTimeImmutable('2026-01-15 10:30:00'));
        $em->persist($entity);
        $em->flush();

        // Update the DateTime field
        $entity->setPublishedAt(new DateTimeImmutable('2026-02-20 14:00:00'));
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotNull($updateLog, 'Update log should exist');
        self::assertNotNull($updateLog->signature, 'Update log should be signed');

        // NOW REVERT — this is where the crash happened
        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        // If AuditReverter doesn't serialize the denormalized DateTime objects,
        // this call will throw: Fatal error: (string) DateTimeImmutable
        $changes = $reverter->revert($updateLog);

        self::assertArrayHasKey('publishedAt', $changes, 'Revert should report publishedAt as changed');

        // Verify the revert audit log was created and signed too
        $revertLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_REVERT,
        ]);
        self::assertNotNull($revertLog, 'Revert should produce its own audit log');
        self::assertNotNull($revertLog->signature, 'Revert audit log should be signed');

        // Verify the revert log values are serialized strings, not raw objects
        if ($revertLog->oldValues !== null && isset($revertLog->oldValues['publishedAt'])) {
            self::assertIsString(
                $revertLog->oldValues['publishedAt'],
                'Reverted DateTime should be serialized as string in audit log, not a raw object'
            );
        }
    }

    public function testF11EnumSupport(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $post = new DateTimePost();
        $post->setTitle('Enum Test');
        $post->setStatus(PostStatus::PUBLISHED);
        $em->persist($post);
        $em->flush();

        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($log);
        self::assertIsArray($log->newValues);
        self::assertSame('published', $log->newValues['status'], 'Enum should be serialized to its backed value');
    }

    public function testF12ZeroExcusesNonStringableObjectSafety(): void
    {
        // This test uses a mock object that is NOT stringable and has NO getId()
        // and places it in a nested array in the context to stress test ValueSerializer
        $options = [
            'audit_config' => [
                'integrity' => ['enabled' => true, 'secret' => 'pressure-secret'],
            ],
        ];

        $this->bootTestKernel($options);
        $em = $this->getEntityManager();
        $dispatcher = self::getContainer()->get(AuditDispatcherInterface::class);
        assert($dispatcher instanceof AuditDispatcherInterface);

        $nonStringable = new PressureItem();

        $post = new DateTimePost();
        $post->setTitle('Pressure Test');

        /** @var AuditServiceInterface $auditService */
        $auditService = $this->getService(AuditServiceInterface::class);
        $log = $auditService->createAuditLog(
            $post,
            AuditLogInterface::ACTION_CREATE,
            null,
            ['title' => 'Pressure Test'],
            ['deep' => ['nested' => ['object' => $nonStringable]]]
        );

        // Ensure DISPATCH works without crashing during signature generation
        // AuditIntegrityService will be called during dispatch if integrity is enabled
        $dispatcher->dispatch($log, $em, 'post_flush');

        self::assertNotNull($log->signature, 'Log should be signed even with weird objects in context');
        self::assertSame(
            PressureItem::class,
            $log->context['deep']['nested']['object'],
            'Non-stringable object should fail gracefully to its class name'
        );
    }

    // ─── F13. Access Auditing ──────────────────────────────────────────

    public function testF13AccessAuditingProducesLogOnRead(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        // Setup Request context
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/', 'GET'));

        $entity = new CooldownPost();
        $entity->setTitle('Top Secret');
        $em->persist($entity);
        $em->flush();
        $em->clear();

        // Read (Trigger postLoad)
        $em->find(CooldownPost::class, $entity->getId());
        // DoctrineAuditTransport only persists, we need to flush to save access audit to DB
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);

        self::assertNotNull($log, 'Access auditing should produce a log on entity load');
        self::assertSame('Opening cooldown file', $log->context['message'] ?? null);
    }
}
