<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Rcsofttech\AuditTrailBundle\Tests\Functional\TestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

use function assert;

/**
 * Ensures that audit logs created via CLI commands have proper user attribution.
 */
class AuditUserAttributionTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    private function setupDatabase(EntityManagerInterface $em): void
    {
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    #[RunInSeparateProcess]
    public function testAuditRevertWithUserOption(): void
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

    #[RunInSeparateProcess]
    public function testAuditRevertDefaultCliUser(): void
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
