<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Contract\AuditDispatcherInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditReverterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditServiceInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\EventSubscriber\AuditKernelSubscriber;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Author;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\ConditionalPost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\DateTimePost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\PostStatus;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Project;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\ProjectTask;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\SensitivePost;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\Tag;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntityWithIgnored;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntityWithUuid;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function in_array;
use function sort;
use function strlen;

/**
 * End-to-end verification of every feature.
 */
final class FeatureVerificationTest extends AbstractFunctionalTestCase
{
    public function testF8ScalarUpdateAndClearingUuidTagCollectionUsesReadableIds(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Entity\UuidAuthor('Before');
        $tagA = new Entity\UuidTag('Tag A');
        $tagB = new Entity\UuidTag('Tag B');
        $tagC = new Entity\UuidTag('Tag C');
        $author->addTag($tagA);
        $author->addTag($tagB);
        $author->addTag($tagC);

        $em->persist($author);
        $em->flush();

        $expectedTagIds = [
            (string) $tagA->getId(),
            (string) $tagB->getId(),
            (string) $tagC->getId(),
        ];
        sort($expectedTagIds);

        $authorId = $author->getId();
        self::assertNotNull($authorId);

        $em->clear();

        $reloadedAuthor = $em->find(Entity\UuidAuthor::class, $authorId);
        self::assertInstanceOf(Entity\UuidAuthor::class, $reloadedAuthor);

        $reloadedAuthor->setName('After');
        $reloadedAuthor->getTags()->clear();
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => Entity\UuidAuthor::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($log);
        self::assertSame('Before', $log->oldValues['name'] ?? null);
        self::assertSame('After', $log->newValues['name'] ?? null);
        self::assertSame([], $log->newValues['tags'] ?? null);
        self::assertIsArray($log->oldValues['tags'] ?? null);
        self::assertNotContains('[invalid utf-8]', $log->oldValues['tags']);

        $actualTagIds = $log->oldValues['tags'];
        sort($actualTagIds);
        self::assertSame($expectedTagIds, $actualTagIds);
        self::assertContains('name', $log->changedFields ?? []);
        self::assertContains('tags', $log->changedFields ?? []);
    }

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

    public function testF3CreateAuditResolvesEntityIdInImmediateDatabaseMode(): void
    {
        self::bootKernel([
            'audit_config' => [
                'defer_transport_until_commit' => false,
                'transports' => [
                    'database' => ['enabled' => true, 'async' => false],
                ],
            ],
        ]);
        $em = $this->getEntityManager();

        $entity = new TestEntity('Immediate Mode');
        $em->persist($entity);
        $em->flush();

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ], ['id' => 'DESC']);

        self::assertNotNull($log, 'Create action should produce an audit log in immediate mode');
        self::assertNotSame(AuditLogInterface::PENDING_ID, $log->entityId, 'Entity ID should be resolved');
        self::assertSame((string) $entity->getId(), $log->entityId);
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

    public function testF8CreateWithManyToManyDoesNotProduceRedundantUpdateLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Tagged On Create');
        $tag1 = new Tag('php');
        $tag2 = new Tag('symfony');
        $author->addTag($tag1);
        $author->addTag($tag2);

        $em->persist($author);
        $em->flush();

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
        ], ['id' => 'ASC']);

        self::assertCount(1, $logs, 'Creating an entity with initial many-to-many values should produce only one audit log');
        self::assertSame(AuditLogInterface::ACTION_CREATE, $logs[0]->action);
        self::assertNotNull($logs[0]->newValues);
        self::assertArrayHasKey('tags', $logs[0]->newValues);
        self::assertCount(2, $logs[0]->newValues['tags']);
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

    public function testF8ScalarAndManyToManyChangesShareSingleUpdateLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Original Name');
        $tag = new Tag('php');
        $em->persist($author);
        $em->persist($tag);
        $em->flush();

        $author->setName('Updated Name');
        $author->addTag($tag);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['id' => 'ASC']);

        self::assertCount(1, $updateLogs, 'A scalar field update and collection update in the same flush should produce one audit log');

        $log = $updateLogs[0];
        self::assertNotNull($log->oldValues);
        self::assertNotNull($log->newValues);
        self::assertSame('Original Name', $log->oldValues['name'] ?? null);
        self::assertSame('Updated Name', $log->newValues['name'] ?? null);
        self::assertSame([], $log->oldValues['tags'] ?? null);
        self::assertCount(1, $log->newValues['tags'] ?? []);
        self::assertNotNull($log->changedFields);
        self::assertContains('name', $log->changedFields);
        self::assertContains('tags', $log->changedFields);
    }

    public function testF8ScalarUpdateAndClearingLastTagCollectionShareSingleUpdateLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Original Name');
        $tag = new Tag('php');
        $author->addTag($tag);
        $em->persist($author);
        $em->flush();
        $authorId = $author->getId();
        self::assertNotNull($authorId);

        $em->clear();

        /** @var Author|null $author */
        $author = $em->find(Author::class, $authorId);
        self::assertInstanceOf(Author::class, $author);

        $author->setName('Updated Name');
        $author->getTags()->clear();
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['id' => 'ASC']);

        self::assertCount(1, $updateLogs, 'A scalar update and clearing the last tag collection in the same flush should produce one audit log');

        $log = $updateLogs[0];
        self::assertNotNull($log->oldValues);
        self::assertNotNull($log->newValues);
        self::assertSame('Original Name', $log->oldValues['name'] ?? null);
        self::assertSame('Updated Name', $log->newValues['name'] ?? null);
        self::assertCount(1, $log->oldValues['tags'] ?? []);
        self::assertSame([], $log->newValues['tags'] ?? null);
        self::assertNotNull($log->changedFields);
        self::assertContains('name', $log->changedFields);
        self::assertContains('tags', $log->changedFields);
    }

    public function testF8DeletingTagProducesOwningEntityTagUpdateLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Delete Tag Case');
        $tag = new Tag('obsolete');
        $author->addTag($tag);
        $em->persist($author);
        $em->flush();

        $em->remove($tag);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['id' => 'ASC']);

        $tagRemovalLog = null;
        foreach ($updateLogs as $log) {
            if (($log->oldValues['tags'] ?? null) !== null && ($log->newValues['tags'] ?? null) === []) {
                $tagRemovalLog = $log;
                break;
            }
        }

        self::assertNotNull($tagRemovalLog, 'Deleting a tag should produce an update audit log for the owning entity tags field');
        self::assertNotNull($tagRemovalLog->changedFields);
        self::assertContains('tags', $tagRemovalLog->changedFields);
    }

    public function testF8DeletingTagAndUpdatingScalarFieldSharesSingleUpdateLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Before Delete');
        $tag = new Tag('legacy');
        $author->addTag($tag);
        $em->persist($author);
        $em->flush();

        $author->setName('After Delete');
        $em->remove($tag);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['id' => 'ASC']);

        self::assertCount(1, $updateLogs, 'Deleting a tag and updating a scalar field in the same flush should produce one update log');

        $log = $updateLogs[0];
        self::assertNotNull($log->oldValues);
        self::assertNotNull($log->newValues);
        self::assertSame('Before Delete', $log->oldValues['name'] ?? null);
        self::assertSame('After Delete', $log->newValues['name'] ?? null);
        self::assertCount(1, $log->oldValues['tags'] ?? []);
        self::assertSame([], $log->newValues['tags'] ?? null);
        self::assertNotNull($log->changedFields);
        self::assertContains('name', $log->changedFields);
        self::assertContains('tags', $log->changedFields);
    }

    public function testF8DeletingMultipleTagsProducesSingleAggregatedUpdateLog(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Author('Delete Multiple Tags');
        $tag1 = new Tag('one');
        $tag2 = new Tag('two');
        $tag3 = new Tag('three');
        $author->addTag($tag1);
        $author->addTag($tag2);
        $author->addTag($tag3);
        $em->persist($author);
        $em->flush();

        $em->remove($tag1);
        $em->remove($tag2);
        $em->flush();

        /** @var AuditLog[] $updateLogs */
        $updateLogs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => Author::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['id' => 'ASC']);

        self::assertCount(1, $updateLogs, 'Deleting multiple related tags in one flush should produce one aggregated update log');

        $log = $updateLogs[0];
        self::assertNotNull($log->oldValues);
        self::assertNotNull($log->newValues);
        self::assertCount(3, $log->oldValues['tags'] ?? []);
        self::assertCount(1, $log->newValues['tags'] ?? []);
        self::assertNotNull($log->changedFields);
        self::assertContains('tags', $log->changedFields);
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
        self::assertSame(['name' => 'Modified Name'], $revertLog->oldValues, 'Revert log should capture the value before the revert was applied.');
        self::assertSame(['name' => 'Original Name'], $revertLog->newValues, 'Revert log should capture the restored value.');
    }

    public function testF9RevertUpdateRestoresUuidRootEntity(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntityWithUuid('Original UUID Name');
        $em->persist($entity);
        $em->flush();

        $entityId = $entity->getId();
        self::assertNotNull($entityId);

        $entity->setName('Updated UUID Name');
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntityWithUuid::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);

        self::assertNotNull($updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);
        $changes = $reverter->revert($updateLog);

        self::assertArrayHasKey('name', $changes);

        $em->clear();

        $reverted = $em->find(TestEntityWithUuid::class, $entityId);
        self::assertInstanceOf(TestEntityWithUuid::class, $reverted);
        self::assertSame('Original UUID Name', $reverted->getName());
    }

    public function testF9RevertRestoresInverseSideOneToManyCollection(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $project = new Project('Platform Rewrite');
        $taskA = new ProjectTask('Discovery');
        $taskB = new ProjectTask('Delivery');
        $project->addProjectTask($taskA);
        $project->addProjectTask($taskB);
        $em->persist($project);
        $em->flush();

        $projectId = $project->id;
        $taskAId = $taskA->id;
        $taskBId = $taskB->id;
        self::assertNotNull($projectId);
        self::assertNotNull($taskAId);
        self::assertNotNull($taskBId);

        $project->removeProjectTask($taskA);
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => Project::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);
        $reverter->revert($updateLog);

        $em->clear();

        $reloadedProject = $em->find(Project::class, $projectId);
        self::assertInstanceOf(Project::class, $reloadedProject);
        self::assertCount(2, $reloadedProject->tasks);

        $taskIds = [];
        foreach ($reloadedProject->tasks as $task) {
            $taskIds[] = $task->id;
        }
        sort($taskIds);

        self::assertSame([$taskAId, $taskBId], $taskIds);

        $reloadedTaskA = $em->find(ProjectTask::class, $taskAId);
        self::assertInstanceOf(ProjectTask::class, $reloadedTaskA);
        self::assertInstanceOf(Project::class, $reloadedTaskA->project);
        self::assertSame($projectId, $reloadedTaskA->project->id);
    }

    public function testF9RevertedCreateLogIsRecognizedAsAlreadyReverted(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Created Then Reverted');
        $em->persist($entity);
        $em->flush();

        /** @var AuditLog|null $createLog */
        $createLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'entityId' => (string) $entity->getId(),
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($createLog, 'Create log should exist before revert.');

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        $reverter->revert($createLog, force: true);

        $em->clear();

        /** @var AuditLog|null $persistedCreateLog */
        $persistedCreateLog = $em->getRepository(AuditLog::class)->find($createLog->id);
        self::assertNotNull($persistedCreateLog, 'Original create log should still exist.');

        $repository = $em->getRepository(AuditLog::class);
        self::assertTrue($repository->isReverted($persistedCreateLog), 'A reverted create log must be recognized as already reverted.');
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

    public function testF9DryRunRevertSupportsUuidTagCollections(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Entity\UuidAuthor('Original Name');
        $tagA = new Entity\UuidTag('Tag A');
        $tagB = new Entity\UuidTag('Tag B');
        $author->addTag($tagA);
        $author->addTag($tagB);
        $em->persist($author);
        $em->flush();

        $author->setName('Updated Name');
        $author->getTags()->clear();
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => Entity\UuidAuthor::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);

        $changes = $reverter->revert($updateLog, dryRun: true);

        self::assertSame('Original Name', $changes['name'] ?? null);
        self::assertArrayHasKey('tags', $changes);
        self::assertCount(2, $changes['tags']);
    }

    public function testF9RevertRestoresUuidTagCollections(): void
    {
        self::bootKernel();
        $em = $this->getEntityManager();

        $author = new Entity\UuidAuthor('Original Name');
        $tagA = new Entity\UuidTag('Tag A');
        $tagB = new Entity\UuidTag('Tag B');
        $author->addTag($tagA);
        $author->addTag($tagB);
        $em->persist($author);
        $em->flush();

        $authorId = $author->getId();
        self::assertNotNull($authorId);

        $expectedTagIds = [(string) $tagA->getId(), (string) $tagB->getId()];
        sort($expectedTagIds);

        $author->setName('Updated Name');
        $author->getTags()->clear();
        $em->flush();

        /** @var AuditLog|null $updateLog */
        $updateLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => Entity\UuidAuthor::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ], ['createdAt' => 'DESC']);

        self::assertNotNull($updateLog);

        $reverter = self::getContainer()->get(AuditReverterInterface::class);
        assert($reverter instanceof AuditReverterInterface);
        $reverter->revert($updateLog);

        $em->clear();

        $reverted = $em->find(Entity\UuidAuthor::class, $authorId);
        self::assertInstanceOf(Entity\UuidAuthor::class, $reverted);
        self::assertSame('Original Name', $reverted->getName());

        $actualTagIds = [];
        foreach ($reverted->getTags() as $tag) {
            $actualTagIds[] = (string) $tag->getId();
        }
        sort($actualTagIds);

        self::assertSame($expectedTagIds, $actualTagIds);
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

        $dispatcher->dispatch($log, $em, AuditPhase::PostFlush);

        self::assertNotNull($log->signature, 'Log should be signed even with weird objects in context');
        self::assertIsArray($log->context['deep'] ?? null);
        self::assertIsArray($log->context['deep']['nested'] ?? null);
        self::assertSame(
            stdClass::class,
            $log->context['deep']['nested']['object'] ?? null,
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

        $subscriber = $this->getService(AuditKernelSubscriber::class);
        assert($subscriber instanceof AuditKernelSubscriber);

        $kernel = $this->getService('kernel');
        assert($kernel instanceof KernelInterface);

        $subscriber->onKernelTerminate(new TerminateEvent(
            $kernel,
            $requestStack->getCurrentRequest() ?? Request::create('/', 'GET'),
            new Response()
        ));

        /** @var AuditLog|null $log */
        $log = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => CooldownPost::class,
            'action' => AuditLogInterface::ACTION_ACCESS,
        ]);

        self::assertNotNull($log, 'Access auditing should produce a log on entity load');
        self::assertSame('Opening cooldown file', $log->context['message'] ?? null);
    }
}
