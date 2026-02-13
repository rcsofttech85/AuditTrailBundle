<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Command;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function assert;

/**
 * Ensures that audit logs created via CLI commands have proper user attribution.
 */
class AuditUserAttributionTest extends AbstractFunctionalTestCase
{
    public function testAuditRevertWithUserOption(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Original');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Changed');
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'update']);
        self::assertNotNull($auditLog);

        assert(self::$kernel instanceof \Symfony\Component\HttpKernel\KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:revert');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'auditId' => (string) $auditLog->getId(),
            '--user' => 'admin_tester',
        ]);

        self::assertSame(0, $commandTester->getStatusCode());

        $revertLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'revert']);
        self::assertNotNull($revertLog);
        self::assertEquals('admin_tester', $revertLog->getUsername());
        self::assertEquals('admin_tester', $revertLog->getUserId());
    }

    public function testAuditRevertDefaultCliUser(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Original');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Changed');
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'update']);
        self::assertNotNull($auditLog);

        assert(self::$kernel instanceof \Symfony\Component\HttpKernel\KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:revert');
        $commandTester = new CommandTester($command);

        $commandTester->execute([
            'auditId' => (string) $auditLog->getId(),
        ]);

        self::assertSame(0, $commandTester->getStatusCode());

        $revertLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'revert']);
        self::assertNotNull($revertLog);

        // Should have cli: prefix and machine defaults
        self::assertStringStartsWith('cli:', (string) $revertLog->getUsername());
        self::assertStringStartsWith('cli:', (string) $revertLog->getUserId());
        self::assertEquals(gethostbyname((string) gethostname()), $revertLog->getIpAddress());
        self::assertStringContainsString('cli-console', (string) $revertLog->getUserAgent());
        self::assertStringContainsString((string) gethostname(), (string) $revertLog->getUserAgent());
    }
}
