<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface AuditToggleInterface
{
    public function disable(): void;

    public function enable(): void;

    public function isEnabled(): bool;
}
