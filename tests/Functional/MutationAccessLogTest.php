<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\CooldownPost;

class MutationAccessLogTest extends AbstractFunctionalTestCase
{
    public function testNoAdditionalAccessLogOnCreate(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();
        $this->clearTestCache();

        $post = new CooldownPost();
        $post->setTitle('Mutation Test');
        $em->persist($post);
        $em->flush();

        // Accessing it via refresh should trigger postLoad
        $em->refresh($post);
        $em->flush();

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
        ]);

        $actions = array_map(static fn (AuditLog $log) => $log->getAction(), $logs);

        self::assertContains(AuditLogInterface::ACTION_CREATE, $actions, 'Should have a create log');
        self::assertNotContains(AuditLogInterface::ACTION_ACCESS, $actions, 'Should NOT have an access log in the same request after creation');
    }

    public function testNoAdditionalAccessLogOnUpdate(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();
        $this->clearTestCache();

        $post = new CooldownPost();
        $post->setTitle('Initial Title');
        $em->persist($post);
        $em->flush();

        // Clear logs to focus on update
        $this->clearAuditLogs($em);

        // Perform update
        $post->setTitle('Updated Title');
        $em->flush();

        // Accessing it via refresh should trigger postLoad
        $em->refresh($post);
        $em->flush();

        /** @var AuditLog[] $logs */
        $logs = $em->getRepository(AuditLog::class)->findBy([
            'entityClass' => CooldownPost::class,
        ]);

        $actions = array_map(static fn (AuditLog $log) => $log->getAction(), $logs);

        self::assertContains(AuditLogInterface::ACTION_UPDATE, $actions, 'Should have an update log');
        self::assertNotContains(AuditLogInterface::ACTION_ACCESS, $actions, 'Should NOT have an access log in the same request after update');
    }

    private function clearAuditLogs(\Doctrine\ORM\EntityManagerInterface $em): void
    {
        $em->createQuery('DELETE FROM '.AuditLog::class)->execute();
        // Do not clear the EM here to avoid detaching $post in the update test
    }
}
