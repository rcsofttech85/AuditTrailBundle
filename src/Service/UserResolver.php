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
use function filter_var;
use function get_object_vars;
use function is_scalar;

use const FILTER_VALIDATE_IP;

final readonly class UserResolver implements UserResolverInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private CliExecutionContextResolver $cliContextResolver,
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

        return $this->cliContextResolver->resolveUser();
    }

    #[Override]
    public function getUsername(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? $this->cliContextResolver->resolveUser();
    }

    #[Override]
    public function getIpAddress(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null) {
            $ip = $this->trackIpAddress ? $request->getClientIp() : null;

            return $ip !== null && $this->isValidIpAddress($ip) ? $ip : null;
        }

        if ($this->trackIpAddress) {
            return $this->cliContextResolver->resolveIpAddress();
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

        return $this->cliContextResolver->resolveUserAgent();
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

    private function isValidIpAddress(string $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_IP) !== false;
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
