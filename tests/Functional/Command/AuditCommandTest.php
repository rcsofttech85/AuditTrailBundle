<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Command;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function is_array;
use function is_string;

class AuditCommandTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        $kernel = parent::createKernel($options);
        if ($kernel instanceof TestKernel && isset($options['audit_config'])) {
            assert(is_array($options['audit_config']));
            $kernel->setAuditConfig($options['audit_config']);
        }

        return $kernel;
    }

    private function setupDatabase(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    #[RunInSeparateProcess]
    public function testAuditListCommand(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        // Create some audit logs
        $entity = new TestEntity('Test 1');
        $em->persist($entity);
        $em->flush();

        assert(self::$kernel !== null);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:list');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);
        $output = $commandTester->getDisplay();

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertStringContainsString('Audit Logs (1 results)', $output);
        self::assertStringContainsString('TestEntity', $output);
    }

    #[RunInSeparateProcess]
    public function testAuditDiffCommand(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Initial Name');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Updated Name');
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'update']);
        self::assertNotNull($auditLog);

        assert(self::$kernel !== null);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:diff');
        $commandTester = new CommandTester($command);

        $commandTester->execute(['identifier' => (string) $auditLog->getId()]);
        $output = $commandTester->getDisplay();

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertStringContainsString('Initial Name', $output);
        self::assertStringContainsString('Updated Name', $output);
    }

    #[RunInSeparateProcess]
    public function testAuditRevertCommand(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Original');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Changed');
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'update']);
        self::assertNotNull($auditLog);

        assert(self::$kernel !== null);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:revert');
        $commandTester = new CommandTester($command);

        // Test Dry Run
        $commandTester->execute(['auditId' => (string) $auditLog->getId(), '--dry-run' => true]);
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('Running in DRY-RUN mode', $output);

        $em->clear();
        $reloaded = $em->find(TestEntity::class, $entity->getId());
        assert($reloaded instanceof TestEntity);
        self::assertSame('Changed', $reloaded->getName());

        // Test Actual Revert
        $commandTester->execute(['auditId' => (string) $auditLog->getId()]);
        self::assertSame(0, $commandTester->getStatusCode());

        $em->clear();
        $reloaded = $em->find(TestEntity::class, $entity->getId());
        assert($reloaded instanceof TestEntity);
        self::assertSame('Original', $reloaded->getName());
    }

    #[RunInSeparateProcess]
    public function testAuditExportCommand(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Export Test');
        $em->persist($entity);
        $em->flush();

        assert(self::$kernel !== null);
        $application = new Application(self::$kernel);
        $command = $application->find('audit:export');
        $commandTester = new CommandTester($command);

        $tempFile = sys_get_temp_dir().'/audit_export.json';
        $commandTester->execute(['--output' => $tempFile, '--format' => 'json']);

        self::assertSame(0, $commandTester->getStatusCode());
        self::assertFileExists($tempFile);
        $content = file_get_contents($tempFile);
        assert(is_string($content));
        self::assertStringContainsString('Export Test', $content);
        unlink($tempFile);
    }

    #[RunInSeparateProcess]
    public function testAuditPurgeCommand(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.entity_manager');
        assert($em instanceof EntityManagerInterface);
        $this->setupDatabase($em);

        $entity = new TestEntity('Purge Test');
        $em->persist($entity);
        $em->flush();

        self::assertCount(1, $em->getRepository(AuditLog::class)->findAll());

        assert(self::$kernel !== null);
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
