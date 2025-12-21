<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Message\AuditLogMessage;
use Symfony\Component\Messenger\MessageBusInterface;

final class QueueAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(AuditLog $log, array $context = []): void
    {

        if (($context['phase'] ?? '') !== 'post_flush') {
            return;
        }

        $entityId = $log->entityId;
        if ($entityId === 'pending' && isset($context['entity'], $context['em'])) {
            $entity = $context['entity'];
            $em = $context['em'];
            $meta = $em->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);
            if (!empty($ids)) {
                $entityId = implode('-', $ids);
            }
        }

        try {
            $message = new AuditLogMessage(
                $log->entityClass,
                $entityId,
                $log->action,
                $log->oldValues,
                $log->newValues,
                $log->userId,
                $log->username,
                $log->ipAddress,
                $log->createdAt
            );

            $this->bus->dispatch($message);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to dispatch audit log message', [
                'exception' => $e,
                'entity_class' => $log->entityClass,
            ]);
        }
    }
}
