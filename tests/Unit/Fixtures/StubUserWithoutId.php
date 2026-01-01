<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

class StubUserWithoutId implements UserInterface
{
    public function getRoles(): array
    {
        return [];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'user';
    }
}
