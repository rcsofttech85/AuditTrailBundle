<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Fixtures;

class RevertTestUser
{
    public function getId(): int
    {
        return 1;
    }

    public function getDeletedAt(): ?\DateTimeInterface
    {
        return null;
    }

    public function setDeletedAt(?\DateTimeInterface $d): void
    {
    }
}
