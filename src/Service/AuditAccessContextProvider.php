<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;

final readonly class AuditAccessContextProvider
{
    public function __construct(
        private UserResolverInterface $userResolver,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function capture(): array
    {
        $context = [];

        $userId = $this->userResolver->getUserId();
        if ($userId !== null) {
            $context[AuditLogInterface::CONTEXT_USER_ID] = $userId;
        }

        $username = $this->userResolver->getUsername();
        if ($username !== null) {
            $context[AuditLogInterface::CONTEXT_USERNAME] = $username;
        }

        $ipAddress = $this->userResolver->getIpAddress();
        if ($ipAddress !== null) {
            $context[AuditLogInterface::CONTEXT_IP_ADDRESS] = $ipAddress;
        }

        $userAgent = $this->userResolver->getUserAgent();
        if ($userAgent !== null) {
            $context[AuditLogInterface::CONTEXT_USER_AGENT] = $userAgent;
        }

        return $context;
    }
}
