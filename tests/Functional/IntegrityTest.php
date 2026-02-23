<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function strlen;

use const JSON_THROW_ON_ERROR;

final class IntegrityTest extends AbstractFunctionalTestCase
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

        self::bootKernel($options);
        $em = $this->getEntityManager();

        $entity = new TestEntity('Integrity Test');
        $em->persist($entity);
        $em->flush();

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_CREATE,
        ]);

        self::assertNotNull($auditLog);
        self::assertNotNull($auditLog->signature);
        self::assertSame(64, strlen($auditLog->signature));
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

        $kernel = self::bootKernel($options);
        $em = $this->getEntityManager();

        // Create a valid audit log
        $entity = new TestEntity('Tamper Test');
        $em->persist($entity);
        $em->flush();

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
        self::assertNotNull($auditLog->id);

        $affected = $em->getConnection()->executeStatement(
            'UPDATE audit_log SET new_values = ? WHERE id = ?',
            [json_encode(['name' => 'TAMPERED'], JSON_THROW_ON_ERROR), $auditLog->id->toBinary()]
        );
        self::assertEquals(1, $affected, 'Tampering UPDATE should affect exactly 1 row');
        $em->clear();

        // Verify tampering is detected
        $commandTester->execute([]);
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('tampered', $output);
        self::assertStringContainsString('audit', $output);
        self::assertStringContainsString((string) $auditLog->id, $output);
    }
}
