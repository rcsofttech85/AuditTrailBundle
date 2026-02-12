<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Override;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Generates a unique transaction ID for the current request/process.
 *
 * This service should be scoped to the request or container lifetime
 * to ensure the same ID is used for all audit logs within a single
 * execution cycle.
 */
class TransactionIdGenerator implements ResetInterface
{
    private ?string $transactionId = null;

    public function getTransactionId(): string
    {
        return $this->transactionId ??= Uuid::v7()->toRfc4122();
    }

    #[Override]
    public function reset(): void
    {
        $this->transactionId = null;
    }
}
