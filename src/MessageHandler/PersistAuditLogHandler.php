<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sprintf;

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
        private ManagerRegistry $registry,
    ) {
    }

    public function __invoke(PersistAuditLogMessage $message): void
    {
        $em = $this->getEntityManager();

        if ($message->deliveryId !== null) {
            $existing = $em->getRepository(AuditLog::class)->findOneBy(['deliveryId' => $message->deliveryId]);
            if ($existing instanceof AuditLog) {
                return;
            }
        }

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
            deliveryId: $message->deliveryId,
        );

        try {
            $em->persist($log);
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // Another worker or retry already stored this delivery; treat as idempotent success.
            if ($em->isOpen()) {
                $em->clear();
            } else {
                $this->registry->resetManager();
            }
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $manager = $this->registry->getManagerForClass(AuditLog::class);

        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('No EntityManager is configured for %s.', AuditLog::class));
        }

        return $manager;
    }
}
