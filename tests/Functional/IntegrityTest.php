<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function strlen;

use const JSON_THROW_ON_ERROR;

class IntegrityTest extends AbstractFunctionalTestCase
{
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

        $this->bootTestKernel($options);
        $em = $this->getEntityManager();

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

        $this->bootTestKernel($options);
        $em = $this->getEntityManager();

        // Create a valid audit log
        $entity = new TestEntity('Tamper Test');
        $em->persist($entity);
        $em->flush();

        $kernel = self::$kernel;
        assert($kernel instanceof KernelInterface);
        $application = new Application($kernel);
        $command = $application->find('audit:verify-integrity');
        $commandTester = new CommandTester($command);

        // Verify it's initially valid
        $logs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(1, $logs, 'Should have 1 log before verification');
        $commandTester->execute([]);
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        self::assertTrue(str_contains($output, 'verified'), 'Output should contain success message');
        self::assertTrue(str_contains($output, 'successfully'), 'Output should contain success message');

        // Tamper with the log
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
        ]);
        self::assertNotNull($auditLog);

        $em->getConnection()->executeStatement(
            'UPDATE audit_log SET new_values = ? WHERE id = ?',
            [json_encode(['name' => 'TAMPERED'], JSON_THROW_ON_ERROR), $auditLog->getId()]
        );
        $em->clear();

        // Verify tampering is detected
        $commandTester->execute([]);
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertTrue(str_contains($output, 'tampered audit logs'), 'Output should contain error message');
        self::assertTrue(str_contains($output, (string) $auditLog->getId()), 'Output should contain tampered log ID');
    }
}
