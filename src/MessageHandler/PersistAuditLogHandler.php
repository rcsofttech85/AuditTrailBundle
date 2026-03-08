<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Built-in handler for async database persistence.
 *
 * Consumes PersistAuditLogMessage and hydrates it into an AuditLog entity,
 * which is then persisted to the database. This handler is auto-registered
 * by the bundle when database.async is enabled.
 */
#[AsMessageHandler]
final readonly class PersistAuditLogHandler
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    public function __invoke(PersistAuditLogMessage $message): void
    {
        $log = new AuditLog(
            entityClass: $message->entityClass,
            entityId: $message->entityId,
            action: $message->action,
            createdAt: new DateTimeImmutable($message->createdAt),
            oldValues: $message->oldValues,
            newValues: $message->newValues,
            changedFields: $message->changedFields,
            transactionHash: $message->transactionHash,
            userId: $message->userId,
            username: $message->username,
            ipAddress: $message->ipAddress,
            userAgent: $message->userAgent,
            context: $message->context,
            signature: $message->signature,
        );

        $this->em->persist($log);
        $this->em->flush();
    }
}
