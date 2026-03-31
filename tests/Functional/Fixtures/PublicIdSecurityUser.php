<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Functional\Fixtures;

use Symfony\Component\Security\Core\User\UserInterface;

use function assert;

final class PublicIdSecurityUser implements UserInterface
{
    public function __construct(
        public readonly string $id,
        private readonly string $identifier,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        assert($this->identifier !== '');

        return $this->identifier;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function eraseCredentials(): void
    {
    }
}
