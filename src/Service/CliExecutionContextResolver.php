<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use function explode;
use function filter_var;
use function function_exists;
use function getenv;
use function gethostname;
use function is_string;
use function sprintf;
use function trim;

use const FILTER_VALIDATE_IP;
use const PHP_SAPI;

final class CliExecutionContextResolver
{
    public function resolveUser(): ?string
    {
        if (PHP_SAPI !== 'cli') {
            return null;
        }

        return $this->getPosixUser() ?? $this->getServerUser() ?? 'cli:system';
    }

    public function resolveIpAddress(): ?string
    {
        if (PHP_SAPI !== 'cli') {
            return null;
        }

        return $this->readCliIpAddressValue('AUDIT_TRAIL_CLI_IP')
            ?? $this->readCliIpAddressValue('SSH_CLIENT', 0)
            ?? $this->readCliIpAddressValue('SSH_CONNECTION', 0)
            ?? $this->readCliIpAddressValue('REMOTE_ADDR')
            ?? $this->readCliIpAddressValue('SERVER_ADDR')
            ?? $this->readCliIpAddressValue('LOCAL_ADDR')
            ?? $this->readCliIpAddressValue('HOSTNAME');
    }

    public function resolveUserAgent(): ?string
    {
        if (PHP_SAPI !== 'cli') {
            return null;
        }

        $hostname = gethostname();

        return sprintf('cli-console (%s)', $hostname !== false ? $hostname : 'unknown');
    }

    private function getPosixUser(): ?string
    {
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            if ($info !== false && $info['name'] !== '') {
                return 'cli:'.$info['name'];
            }
        }

        return null;
    }

    private function getServerUser(): ?string
    {
        $user = $this->readCliValue('USER') ?? $this->readCliValue('USERNAME');

        if (is_string($user) && $user !== '') {
            return 'cli:'.$user;
        }

        return null;
    }

    private function readCliIpAddressValue(string $key, ?int $segment = null): ?string
    {
        return $this->normalizeCliIpAddressValue(
            $this->resolveCliIpAddressCandidate($key, $segment),
        );
    }

    private function readCliValue(string $key): ?string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $serverValue = $_SERVER[$key] ?? null;

        return is_string($serverValue) && $serverValue !== '' ? $serverValue : null;
    }

    private function resolveCliIpAddressCandidate(string $key, ?int $segment): ?string
    {
        $value = $this->readCliValue($key);
        if ($value === null) {
            return null;
        }

        if ($segment === null) {
            return $value;
        }

        return $this->extractCliIpAddressSegment($value, $segment);
    }

    private function extractCliIpAddressSegment(string $value, int $segment): ?string
    {
        $segments = explode(' ', trim($value));

        return $segments[$segment] ?? null;
    }

    private function normalizeCliIpAddressValue(?string $value): ?string
    {
        return $value !== null && filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}
