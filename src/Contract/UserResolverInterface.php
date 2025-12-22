<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface UserResolverInterface
{
    public function getUserId(): ?int;

    public function getUsername(): ?string;

    public function getIpAddress(): ?string;

    public function getUserAgent(): ?string;
}
