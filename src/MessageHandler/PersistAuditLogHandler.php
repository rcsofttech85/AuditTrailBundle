<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Message\PersistAuditLogMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
use Symfony\Component\Uid\Uuid;

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
        private AuditLogWriterInterface $auditLogWriter,
        private ?AuditIntegrityServiceInterface $integrityService = null,
    ) {
    }

    public function __invoke(PersistAuditLogMessage $message): void
    {
        $em = $this->getEntityManager();

        $log = new AuditLog(
            entityClass: $message->entityClass,
            entityId: $message->entityId,
            action: AuditAction::from($message->action),
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
            revertedLogId: $message->revertedLogId,
        );

        if ($message->auditId !== null) {
            $log->initializeIdIfMissing(Uuid::fromString($message->auditId));
        }

        $this->assertIntegrity($log, $message);
        $this->auditLogWriter->insert($log, $em);
    }

    private function assertIntegrity(AuditLog $log, PersistAuditLogMessage $message): void
    {
        if ($this->integrityService?->isEnabled() !== true) {
            return;
        }

        if ($message->signature === null) {
            throw new UnrecoverableMessageHandlingException('Async audit messages must include a signature when integrity verification is enabled.');
        }

        if (!$this->integrityService->verifySignature($log)) {
            throw new UnrecoverableMessageHandlingException('Async audit message signature verification failed.');
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
