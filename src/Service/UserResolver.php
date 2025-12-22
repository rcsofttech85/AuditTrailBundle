<?php

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class UserResolver implements UserResolverInterface
{
    public function __construct(
        private Security $security,
        private RequestStack $requestStack,
        private bool $trackIpAddress = true,
        private bool $trackUserAgent = true,
    ) {
    }

    public function getUserId(): ?int
    {
        $user = $this->security->getUser();

        return match (true) {
            null === $user => null,
            method_exists($user, 'getId') => $user->getId(),
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
        $request = $this->requestStack->getCurrentRequest();

        return $this->trackUserAgent ? $request?->headers->get('User-Agent') : null;
    }
}
