<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;

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

        return match (true) {
            null === $user => null,
            method_exists($user, 'getId') => (string) $user->getId(),
            default => null,
        };
    }

    public function getUsername(): ?string
    {
        return $this->security->getUser()?->getUserIdentifier();
    }

    public function getIpAddress(): ?string
    {
        $request = $this->requestStack->getCurrentRequest();

        return $this->trackIpAddress ? $request?->getClientIp() : null;
    }

    public function getUserAgent(): ?string
    {
        if (!$this->trackUserAgent) {
            return null;
        }

        $request = $this->requestStack->getCurrentRequest();
        $ua = $request?->headers->get('User-Agent');

        if (null === $ua || '' === $ua) {
            return null;
        }

        // Return full UA for better security forensics, truncated to 500 chars
        return mb_substr($ua, 0, 500);
    }

    public function getImpersonatorId(): ?string
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        $originalUser = $token->getOriginalToken()->getUser();

        return match (true) {
            null === $originalUser => null,
            method_exists($originalUser, 'getId') => (string) $originalUser->getId(),
            default => null,
        };
    }

    public function getImpersonatorUsername(): ?string
    {
        $token = $this->security->getToken();
        if (!$token instanceof SwitchUserToken) {
            return null;
        }

        return $token->getOriginalToken()->getUser()?->getUserIdentifier();
    }
}
