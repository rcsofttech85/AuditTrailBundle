<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface UserResolverInterface
{
    public function getUserId(): ?string;

    public function getUsername(): ?string;

    public function getIpAddress(): ?string;

    public function getUserAgent(): ?string;

    public function getImpersonatorId(): ?string;

    public function getImpersonatorUsername(): ?string;
}
