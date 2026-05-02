<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Service\CliExecutionContextResolver;

use function array_key_exists;

final class CliExecutionContextResolverTest extends TestCase
{
    private CliExecutionContextResolver $resolver;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    /** @var array<string, mixed> */
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->resolver = new CliExecutionContextResolver();
        $this->backupEnv('USER');
        $this->backupServer('USER');
        $this->backupEnv('USERNAME');
        $this->backupServer('USERNAME');
        $this->backupEnv('AUDIT_TRAIL_CLI_IP');
        $this->backupServer('AUDIT_TRAIL_CLI_IP');
        $this->backupEnv('SSH_CLIENT');
        $this->backupServer('SSH_CLIENT');
        $this->backupEnv('REMOTE_ADDR');
        $this->backupServer('REMOTE_ADDR');
        $this->backupEnv('HOSTNAME');
        $this->backupServer('HOSTNAME');
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('USER');
        $this->restoreServer('USER');
        $this->restoreEnv('USERNAME');
        $this->restoreServer('USERNAME');
        $this->restoreEnv('AUDIT_TRAIL_CLI_IP');
        $this->restoreServer('AUDIT_TRAIL_CLI_IP');
        $this->restoreEnv('SSH_CLIENT');
        $this->restoreServer('SSH_CLIENT');
        $this->restoreEnv('REMOTE_ADDR');
        $this->restoreServer('REMOTE_ADDR');
        $this->restoreEnv('HOSTNAME');
        $this->restoreServer('HOSTNAME');
    }

    public function testResolveUserReturnsCliIdentity(): void
    {
        $user = $this->resolver->resolveUser();

        self::assertNotNull($user);
        self::assertStringStartsWith('cli:', $user);
    }

    public function testResolveIpAddressPrefersExplicitCliEnv(): void
    {
        putenv('AUDIT_TRAIL_CLI_IP=198.51.100.10');
        unset($_SERVER['AUDIT_TRAIL_CLI_IP']);

        self::assertSame('198.51.100.10', $this->resolver->resolveIpAddress());
    }

    public function testResolveIpAddressFallsBackToSshClientFirstSegment(): void
    {
        putenv('AUDIT_TRAIL_CLI_IP');
        unset($_SERVER['AUDIT_TRAIL_CLI_IP']);
        putenv('SSH_CLIENT');
        $_SERVER['SSH_CLIENT'] = '203.0.113.5 5555 22';

        self::assertSame('203.0.113.5', $this->resolver->resolveIpAddress());
    }

    public function testResolveIpAddressFallsBackToRemoteAddrFromServer(): void
    {
        putenv('AUDIT_TRAIL_CLI_IP');
        putenv('SSH_CLIENT');
        unset($_SERVER['AUDIT_TRAIL_CLI_IP'], $_SERVER['SSH_CLIENT']);
        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';

        self::assertSame('198.51.100.20', $this->resolver->resolveIpAddress());
    }

    public function testResolveIpAddressRejectsInvalidFallbackValues(): void
    {
        putenv('AUDIT_TRAIL_CLI_IP');
        putenv('SSH_CLIENT');
        putenv('REMOTE_ADDR');
        $_SERVER['HOSTNAME'] = 'not-an-ip';

        self::assertNull($this->resolver->resolveIpAddress());
    }

    public function testResolveUserAgentIncludesConsoleMarker(): void
    {
        $userAgent = $this->resolver->resolveUserAgent();

        self::assertNotNull($userAgent);
        self::assertStringStartsWith('cli-console (', $userAgent);
        self::assertStringEndsWith(')', $userAgent);
    }

    private function backupEnv(string $key): void
    {
        $this->envBackup[$key] = getenv($key);
    }

    private function restoreEnv(string $key): void
    {
        $value = $this->envBackup[$key] ?? false;
        if ($value === false) {
            putenv($key);

            return;
        }

        putenv($key.'='.$value);
    }

    private function backupServer(string $key): void
    {
        $this->serverBackup[$key] = $_SERVER[$key] ?? null;
    }

    private function restoreServer(string $key): void
    {
        if (!array_key_exists($key, $this->serverBackup) || $this->serverBackup[$key] === null) {
            unset($_SERVER[$key]);

            return;
        }

        $_SERVER[$key] = $this->serverBackup[$key];
    }
}
