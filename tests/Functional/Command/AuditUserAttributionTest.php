<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Command;

use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Tests\Functional\AbstractFunctionalTestCase;
use Rcsofttech\AuditTrailBundle\Tests\Functional\Entity\TestEntity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

use function assert;
use function filter_var;
use function getenv;
use function gethostbyname;
use function gethostname;
use function is_string;

use const FILTER_VALIDATE_IP;

/**
 * Ensures that audit logs created via CLI commands have proper user attribution.
 */
final class AuditUserAttributionTest extends AbstractFunctionalTestCase
{
    public function testAuditRevertWithUserOption(): void
    {
        self::bootKernel();
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

        $commandTester->execute([
            'auditId' => (string) $auditLog->id,
            '--user' => 'admin_tester',
        ]);

        self::assertSame(0, $commandTester->getStatusCode());

        $revertLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'revert']);
        self::assertNotNull($revertLog);
        self::assertSame('admin_tester', $revertLog->username);
        self::assertSame('admin_tester', $revertLog->userId);
    }

    public function testAuditRevertDefaultCliUser(): void
    {
        self::bootKernel();
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

        $commandTester->execute([
            'auditId' => (string) $auditLog->id,
        ]);

        self::assertSame(0, $commandTester->getStatusCode());

        $revertLog = $em->getRepository(AuditLog::class)->findOneBy(['action' => 'revert']);
        self::assertNotNull($revertLog);

        self::assertStringStartsWith('cli:', (string) $revertLog->username);
        self::assertStringStartsWith('cli:', (string) $revertLog->userId);
        self::assertSame($this->resolveExpectedCliIpAddress(), $revertLog->ipAddress);
        self::assertStringContainsString('cli-console', (string) $revertLog->userAgent);
        self::assertStringContainsString((string) gethostname(), (string) $revertLog->userAgent);
    }

    private function resolveExpectedCliIpAddress(): ?string
    {
        $hostname = gethostname();
        if ($hostname !== false) {
            $resolved = gethostbyname($hostname);
            if (filter_var($resolved, FILTER_VALIDATE_IP) !== false) {
                return $resolved;
            }
        }

        $environmentHost = getenv('HOSTNAME');
        if (is_string($environmentHost) && filter_var($environmentHost, FILTER_VALIDATE_IP) !== false) {
            return $environmentHost;
        }

        return null;
    }
}
