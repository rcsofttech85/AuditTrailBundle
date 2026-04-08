<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface AuditAccessHandlerInterface
{
    /**
     * @param \Doctrine\Persistence\ObjectManager $om
     */
    public function handleAccess(object $entity, $om): void;

    public function markAsAudited(string $requestKey): void;

    public function flushPendingAccesses(): void;

    public function hasPendingAccesses(): bool;

    public function reset(): void;
}
