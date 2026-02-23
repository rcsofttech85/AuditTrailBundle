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
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function assert;
use function in_array;
use function strlen;

/**
 * End-to-end verification of every feature.
 */
final class FeatureVerificationTest extends AbstractFunctionalTestCase
{
    public function testF2EntityUpdateProducesAuditLogWithOldAndNewValues(): void
    {
        self::bootKernel();
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

    public function testF3EntityDeleteProducesAuditLog(): void
    {
        self::bootKernel();
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

    public function testF4IgnoredPropertyChangesDoNotProduceAuditLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntityWithIgnored('Ignored Test');
        $em->persist($entity);
        $em->flush();

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
        self::bootKernel();
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

    public function testF5SensitiveFieldIsMaskedInAuditLog(): void
    {
        self::bootKernel();
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

    public function testF6ConditionalAuditingSkipsWhenExpressionIsFalse(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

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
        self::bootKernel();
        $em = $this->getEntityManager();

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

    public function testF7DateTimeFieldSerializedCorrectlyOnCreate(): void
    {
        self::bootKernel();
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
        self::assertIsString($log->newValues['publishedAt'], 'DateTime should be serialized as string');
    }

    public function testF7DateTimeFieldUpdateProducesValidSignature(): void
    {
        $options = [
            'audit_config' => [
                'integrity' => ['enabled' => true, 'secret' => 'test-secret'],
            ],
        ];

        self::bootKernel($options);
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

    public function testF8ManyToManyAddIsTracked(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Jane Doe');
        $tag1 = new Tag('php');
        $tag2 = new Tag('symfony');
        $em->persist($tag1);
        $em->persist($tag2);
        $em->persist($author);
        $em->flush();

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
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('John Doe');
        $tag = new Tag('laravel');
        $author->addTag($tag);
        $em->persist($author);
        $em->flush();

        $author->removeTag($tag);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        $removalLog = null;
        foreach ($updateLogs as $l) {
            if ($l->oldValues !== null && isset($l->oldValues['tags']) && isset($l->newValues['tags'])) {
                $removalLog = $l;
                break;
            }
        }

        self::assertNotNull($removalLog, 'ManyToMany remove should produce an update audit log with old/new tags');
    }

    public function testF9RevertUpdateRestoresOldValues(): void
    {
        self::bootKernel();
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

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        $changes = $reverter->revert($updateLog);

        self::assertArrayHasKey('name', $changes, 'Revert should report the name field as changed');

        $em->clear();
        $reverted = $em->find(TestEntity::class, $entity->getId());
        self::assertNotNull($reverted);
        self::assertSame('Original Name', $reverted->getName(), 'Entity should be reverted to original name');

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

        self::bootKernel($options);
        $em = $this->getEntityManager();

        $entity = new DateTimePost();
        $entity->setTitle('Revert DateTime');
        $entity->setPublishedAt(new DateTimeImmutable('2026-01-15 10:30:00'));
        $em->persist($entity);
        $em->flush();

        $entity->setPublishedAt(new DateTimeImmutable('2026-02-20 14:00:00'));
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotNull($updateLog, 'Update log should exist');
        self::assertNotNull($updateLog->signature, 'Update log should be signed');

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        $changes = $reverter->revert($updateLog);

        self::assertArrayHasKey('publishedAt', $changes, 'Revert should report publishedAt as changed');

        $revertLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => DateTimePost::class,
            'action' => AuditLogInterface::ACTION_REVERT,
        ]);
        self::assertNotNull($revertLog, 'Revert should produce its own audit log');
        self::assertNotNull($revertLog->signature, 'Revert audit log should be signed');

        if ($revertLog->oldValues !== null && isset($revertLog->oldValues['publishedAt'])) {
            self::assertIsString(
                $revertLog->oldValues['publishedAt'],
                'Reverted DateTime should be serialized as string in audit log, not a raw object'
            );
        }
    }

    public function testF11EnumSupport(): void
    {
        self::bootKernel();
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
        $options = [
            'audit_config' => [
                'integrity' => ['enabled' => true, 'secret' => 'pressure-secret'],
            ],
        ];

        self::bootKernel($options);
        $em = $this->getEntityManager();
        $dispatcher = self::getContainer()->get(AuditDispatcherInterface::class);
        assert($dispatcher instanceof AuditDispatcherInterface);

        $nonStringable = new stdClass();

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

        $dispatcher->dispatch($log, $em, 'post_flush');

        self::assertNotNull($log->signature, 'Log should be signed even with weird objects in context');
        self::assertSame(
            stdClass::class,
            $log->context['deep']['nested']['object'],
            'Non-stringable object should fail gracefully to its class name'
        );
    }

    public function testF13AccessAuditingProducesLogOnRead(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/', 'GET'));

        $entity = new CooldownPost();
        $entity->setTitle('Top Secret');
        $em->persist($entity);
        $em->flush();
        $em->clear();

        $em->find(CooldownPost::class, $entity->getId());
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
