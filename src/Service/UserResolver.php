<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

use function function_exists;
use function is_object;
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

    public function getUserId(): ?string
    {
        $user = $this->security->getUser();

        if ($user !== null) {
            if (method_exists($user, 'getId')) {
                $id = $user->getId();
                if (is_scalar($id) || (is_object($id) && method_exists($id, '__toString'))) {
                    return (string) $id;
                }
            }

            return $user->getUserIdentifier();
        }

        return $this->resolveCliUser();
    }

    public function getUsername(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? $this->resolveCliUser();
    }

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

    public function getImpersonatorId(): ?string
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        $originalUser = $token->getOriginalToken()->getUser();

        if ($originalUser !== null && method_exists($originalUser, 'getId')) {
            $id = $originalUser->getId();
            if (is_scalar($id) || (is_object($id) && method_exists($id, '__toString'))) {
                return (string) $id;
            }
        }

        return null;
    }

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

        // on Unix/Linux
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            if ($info !== false && $info['name'] !== '') {
                return 'cli:'.$info['name'];
            }
        }

        // Windows / CI
        $user = $_SERVER['USER'] ?? $_SERVER['USERNAME'] ?? null;
        if (is_string($user) && $user !== '') {
            return 'cli:'.$user;
        }

        return 'cli:system';
    }
}
