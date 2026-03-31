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
use function function_exists;
use function get_object_vars;
use function is_scalar;
use function is_string;
use function sprintf;

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
            return $this->trackIpAddress ? $request->getClientIp() : null;
        }

        if ($this->trackIpAddress && PHP_SAPI === 'cli') {
            $hostname = gethostname();
            if ($hostname !== false) {
                return gethostbyname($hostname);
            }
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
        $user = $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? null;
        if (is_string($user) && $user !== '') {
            return 'cli:'.$user;
        }

        return null;
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
