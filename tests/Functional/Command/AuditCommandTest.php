<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Command;

use DateTimeImmutable;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function is_string;

class AuditCommandTest extends AbstractFunctionalTestCase
{
    public function testAuditListCommand(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        // Create some audit logs
        $entity = new TestEntity('Test 1');
        $em->persist($entity);
        $em->flush();

        assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:list');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);
        $output = $commandTester->getDisplay();

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertStringContainsString('Audit Logs (1 results)', $output);
        self::assertStringContainsString('TestEntity', $output);
    }

    public function testAuditDiffCommand(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Initial Name');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Updated Name');
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'update']);
        self::assertNotNull($auditLog);

        assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:diff');
        $commandTester = new CommandTester($command);

        $commandTester->execute(['identifier' => (string) $auditLog->id]);
        $output = $commandTester->getDisplay();

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertStringContainsString('Initial Name', $output);
        self::assertStringContainsString('Updated Name', $output);
    }

    public function testAuditRevertCommand(): void
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

        assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:revert');
        $commandTester = new CommandTester($command);

        // Test Dry Run
        $commandTester->execute(['auditId' => (string) $auditLog->id, '--dry-run' => true]);
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('DRY-RUN', $output);
        self::assertStringContainsString('mode', $output);

        $em->clear();
        $reloaded = $em->find(TestEntity::class, $entity->getId());
        assert($reloaded instanceof TestEntity);
        self::assertSame('Changed', $reloaded->getName());

        // Test Actual Revert
        $commandTester->setInputs(['yes']);
        $commandTester->execute(['auditId' => (string) $auditLog->id]);
        self::assertSame(0, $commandTester->getStatusCode());

        $em->clear();
        $reloaded = $em->find(TestEntity::class, $entity->getId());
        assert($reloaded instanceof TestEntity);
        self::assertSame('Original', $reloaded->getName());
    }

    public function testAuditExportCommand(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Export Test');
        $em->persist($entity);
        $em->flush();

        assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:export');
        $commandTester = new CommandTester($command);

        $tempFile = sys_get_temp_dir().'/audit_export.json';
        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
        $commandTester->execute(['--output' => $tempFile, '--format' => 'json']);

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertFileExists($tempFile);
        $content = file_get_contents($tempFile);
        assert(is_string($content));
        self::assertStringContainsString('Export Test', $content);
        unlink($tempFile);
    }

    public function testAuditPurgeCommand(): void
    {
        $this->bootTestKernel();
        $em = $this->getEntityManager();

        $entity = new TestEntity('Purge Test');
        $em->persist($entity);
        $em->flush();

        self::assertCount(1, $em->getRepository(AuditLog::class)->findAll());

        assert(self::$kernel instanceof KernelInterface);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:purge');
        $commandTester = new CommandTester($command);

        // Purge everything older than tomorrow
        $tomorrow = new DateTimeImmutable('+1 day')->format('Y-m-d');
        $commandTester->setInputs(['yes']); // Confirm purge
        $commandTester->execute(['--before' => $tomorrow]);

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertCount(0, $em->getRepository(AuditLog::class)->findAll());
    }
}
