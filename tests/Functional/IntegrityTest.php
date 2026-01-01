<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class IntegrityTest extends KernelTestCase
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
    public function testAuditLogsAreSignedWhenIntegrityIsEnabled(): void
    {
        $options = [
            'audit_config' => [
                'integrity' => [
                    'enabled' => true,
                    'secret' => 'test-secret',
                ],
            ],
        ];

        self::bootKernel($options);
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->setupDatabase($em);

        $entity = new TestEntity('Integrity Test');
        $em->persist($entity);
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => 'create',
        ]);

        self::assertNotNull($auditLog);
        self::assertNotNull($auditLog->getSignature());
        self::assertSame(64, strlen($auditLog->getSignature()));
    }

    #[RunInSeparateProcess]
    public function testVerifyIntegrityCommandDetectsTampering(): void
    {
        $options = [
            'audit_config' => [
                'integrity' => [
                    'enabled' => true,
                    'secret' => 'test-secret',
                ],
            ],
        ];

        self::bootKernel($options);
        $container = self::getContainer();

        /** @var EntityManagerInterface $em */
        $em = $container->get('doctrine.orm.entity_manager');
        $this->setupDatabase($em);

        // 1. Create a valid audit log
        $entity = new TestEntity('Tamper Test');
        $em->persist($entity);
        $em->flush();

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $command = $application->find('audit:verify-integrity');
        $commandTester = new CommandTester($command);

        // 2. Verify it's initially valid
        $commandTester->execute([]);
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertTrue(str_contains($output, 'All 1 audit logs'), 'Output should contain success message');

        // 3. Tamper with the log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
        ]);
        self::assertNotNull($auditLog);

        // We need to use a direct SQL query to bypass the entity manager and sign logic if any
        $em->getConnection()->executeStatement(
            'UPDATE audit_log SET new_values = ? WHERE id = ?',
            [json_encode(['name' => 'TAMPERED'], JSON_THROW_ON_ERROR), $auditLog->getId()]
        );
        $em->clear();

        // 4. Verify tampering is detected
        $commandTester->execute([]);
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertTrue(str_contains($output, 'tampered audit logs'), 'Output should contain error message');
        self::assertTrue(str_contains($output, (string) $auditLog->getId()), 'Output should contain tampered log ID');
    }
}
