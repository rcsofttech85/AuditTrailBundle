<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

class StubUserWithId implements UserInterface
{
    public function getId(): int
    {
        return 123;
    }

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
