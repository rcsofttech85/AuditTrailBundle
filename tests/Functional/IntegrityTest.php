<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function hash_hmac;
use function json_encode;

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
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $auditLog->signature);
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

        $entity = new TestEntity('Tamper Test');
        $em->persist($entity);
        $em->flush();

        $application = new Application($kernel);
        $command = $application->find('audit:verify-integrity');
        $commandTester = new CommandTester($command);

        $logs = $em->getRepository(AuditLog::class)->findAll();
        self::assertCount(1, $logs, 'Should have 1 log before verification');
        $commandTester->execute([]);
        self::assertSame(0, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();

        self::assertStringContainsString('verified', $output);
        self::assertStringContainsString('successfully', $output);

        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
        ]);
        self::assertNotNull($auditLog);
        self::assertNotNull($auditLog->id);

        $affected = $em->getConnection()->executeStatement(
            'UPDATE audit_log SET new_values = ? WHERE id = ?',
            [json_encode(['name' => 'TAMPERED'], JSON_THROW_ON_ERROR), $auditLog->id->toBinary()]
        );
        self::assertSame(1, $affected, 'Tampering UPDATE should affect exactly 1 row');
        $em->clear();

        $commandTester->execute([]);
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('tampered', $output);
        self::assertStringContainsString('audit', $output);
        self::assertStringContainsString((string) $auditLog->id, $output);
    }

    public function testVerifyIntegrityCommandDetectsChangedFieldsTampering(): void
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

        $entity = new TestEntity('Changed Fields Tamper');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Changed Fields Tamper Updated');
        $em->flush();

        /** @var AuditLog|null $auditLog */
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);
        self::assertNotNull($auditLog);
        self::assertNotNull($auditLog->id);

        $application = new Application($kernel);
        $command = $application->find('audit:verify-integrity');
        $commandTester = new CommandTester($command);

        $commandTester->execute([]);
        self::assertSame(0, $commandTester->getStatusCode());

        $affected = $em->getConnection()->executeStatement(
            'UPDATE audit_log SET changed_fields = ? WHERE id = ?',
            [json_encode(['tampered_field'], JSON_THROW_ON_ERROR), $auditLog->id->toBinary()]
        );
        self::assertSame(1, $affected);
        $em->clear();

        $commandTester->execute([]);
        self::assertSame(1, $commandTester->getStatusCode());
        $output = $commandTester->getDisplay();
        self::assertStringContainsString('tampered', $output);
        self::assertStringContainsString((string) $auditLog->id, $output);
    }

    public function testVerifyIntegrityCommandAcceptsLegacySignaturesForHistoricalRows(): void
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

        $entity = new TestEntity('Legacy Signature');
        $em->persist($entity);
        $em->flush();

        $entity->setName('Legacy Signature Updated');
        $em->flush();

        /** @var AuditLog|null $auditLog */
        $auditLog = $em->getRepository(AuditLog::class)->findOneBy([
            'entityClass' => TestEntity::class,
            'action' => AuditLogInterface::ACTION_UPDATE,
        ]);
        self::assertNotNull($auditLog);
        self::assertNotNull($auditLog->id);

        $legacyPayload = json_encode([
            'action' => $auditLog->action,
            'context' => [],
            'created_at' => $auditLog->createdAt->format('Y-m-d H:i:s'),
            'entity_class' => $auditLog->entityClass,
            'entity_id' => $auditLog->entityId,
            'ip_address' => $auditLog->ipAddress,
            'new_values' => ['name' => 's:Legacy Signature Updated'],
            'old_values' => ['name' => 's:Legacy Signature'],
            'transaction_hash' => $auditLog->transactionHash,
            'user_agent' => $auditLog->userAgent,
            'user_id' => $auditLog->userId,
            'username' => $auditLog->username,
        ], JSON_THROW_ON_ERROR);

        $affected = $em->getConnection()->executeStatement(
            'UPDATE audit_log SET signature = ? WHERE id = ?',
            [hash_hmac('sha256', $legacyPayload, 'test-secret'), $auditLog->id->toBinary()]
        );
        self::assertSame(1, $affected);
        $em->clear();

        $application = new Application($kernel);
        $command = $application->find('audit:verify-integrity');
        $commandTester = new CommandTester($command);

        $commandTester->execute(['--id' => (string) $auditLog->id]);
        self::assertSame(0, $commandTester->getStatusCode());
        self::assertStringContainsString('authentic', $commandTester->getDisplay());
    }
}
