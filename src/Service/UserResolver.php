<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Stringable;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

use function array_key_exists;
use function explode;
use function filter_var;
use function function_exists;
use function get_object_vars;
use function getenv;
use function is_scalar;
use function is_string;
use function sprintf;
use function trim;

use const FILTER_VALIDATE_IP;
use const PHP_SAPI;

final readonly class UserResolver implements UserResolverInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private bool $trackIpAddress = true,
        private bool $trackUserAgent = true,
    ) {
    }

    #[Override]
    public function getUserId(): ?string
    {
        $user = $this->security->getUser();

        if ($user !== null) {
            $resolvedId = $this->resolveUserIdValue($user);
            if ($resolvedId !== null) {
                return $resolvedId;
            }

            return $user->getUserIdentifier();
        }

        return $this->resolveCliUser();
    }

    #[Override]
    public function getUsername(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? $this->resolveCliUser();
    }

    #[Override]
    public function getIpAddress(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null) {
            $ip = $this->trackIpAddress ? $request->getClientIp() : null;

            return $ip !== null && $this->isValidIpAddress($ip) ? $ip : null;
        }

        if ($this->trackIpAddress && PHP_SAPI === 'cli') {
            return $this->resolveCliIpAddress();
        }

        return null;
    }

    #[Override]
    public function getUserAgent(): ?string
    {
        if (!$this->trackUserAgent) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null) {
            $ua = (string) $request->headers->get('User-Agent');
            if ($ua !== '') {
                return mb_substr($ua, 0, 500);
            }
        }

        if (PHP_SAPI === 'cli') {
            $hostname = gethostname();

            return sprintf('cli-console (%s)', $hostname !== false ? $hostname : 'unknown');
        }

        return null;
    }

    #[Override]
    public function getImpersonatorId(): ?string
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        $originalUser = $token->getOriginalToken()->getUser();
        if ($originalUser === null) {
            return null;
        }

        return $this->resolveUserIdValue($originalUser);
    }

    #[Override]
    public function getImpersonatorUsername(): ?string
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        return $token->getOriginalToken()->getUser()?->getUserIdentifier();
    }

    private function resolveCliUser(): ?string
    {
        if (PHP_SAPI !== 'cli') {
            return null;
        }

        return $this->getPosixUser() ?? $this->getServerUser() ?? 'cli:system';
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
        $user = $this->readCliUserValue('USER') ?? $this->readCliUserValue('USERNAME');

        if (is_string($user) && $user !== '') {
            return 'cli:'.$user;
        }

        return null;
    }

    private function readCliUserValue(string $key): ?string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $serverValue = $_SERVER[$key] ?? null;

        return is_string($serverValue) && $serverValue !== '' ? $serverValue : null;
    }

    private function resolveCliIpAddress(): ?string
    {
        return $this->readCliIpAddressValue('AUDIT_TRAIL_CLI_IP')
            ?? $this->readCliIpAddressValue('SSH_CLIENT', 0)
            ?? $this->readCliIpAddressValue('SSH_CONNECTION', 0)
            ?? $this->readCliIpAddressValue('REMOTE_ADDR')
            ?? $this->readCliIpAddressValue('SERVER_ADDR')
            ?? $this->readCliIpAddressValue('LOCAL_ADDR')
            ?? $this->readCliIpAddressValue('HOSTNAME');
    }

    private function readCliIpAddressValue(string $key, ?int $segment = null): ?string
    {
        return $this->normalizeCliIpAddressValue(
            $this->resolveCliIpAddressCandidate($key, $segment),
        );
    }

    private function isValidIpAddress(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    private function resolveCliIpAddressCandidate(string $key, ?int $segment): ?string
    {
        $value = $this->readCliUserValue($key);
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
        return $value !== null && $this->isValidIpAddress($value) ? $value : null;
    }

    private function resolveUserIdValue(object $user): ?string
    {
        if (method_exists($user, 'getId')) {
            $resolved = $this->stringifyIdentifier($user->getId());
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $properties = get_object_vars($user);
        if (array_key_exists('id', $properties)) {
            // Supports accessible public properties and PHP 8.4 public property hooks.
            return $this->stringifyIdentifier($properties['id']);
        }

        return null;
    }

    private function stringifyIdentifier(mixed $id): ?string
    {
        if (is_scalar($id) || $id instanceof Stringable) {
            return (string) $id;
        }

        return null;
    }
}
