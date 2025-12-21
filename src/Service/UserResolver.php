<?php

namespace Rcsofttech\AuditTrailBundle\Service;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

class UserResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly bool $trackIpAddress = true,
        private readonly bool $trackUserAgent = true
    ) {
    }

    public function getUserId(): ?int
    {
        $user = $this->security->getUser();
        
        return match (true) {
            $user === null => null,
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