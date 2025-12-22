<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpAuditTransport implements AuditTransportInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private readonly string $endpoint,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLog $log, array $context = []): void
    {




        if (($context['phase'] ?? '') !== 'post_flush') {
            return;
        }

        $entityId = $log->entityId;
        if ('pending' === $entityId && isset($context['entity'], $context['em'])) {
            $entity = $context['entity'];
            $em = $context['em'];
            $meta = $em->getClassMetadata($entity::class);
            $ids = $meta->getIdentifierValues($entity);
            if (!empty($ids)) {
                $entityId = implode('-', $ids);
            }
        }

        try {
            $this->client->request('POST', $this->endpoint, [
                'json' => [
                    'entity_class' => $log->entityClass,
                    'entity_id' => $entityId,
                    'action' => $log->action,
                    'old_values' => $log->oldValues,
                    'new_values' => $log->newValues,
                    'user_id' => $log->userId,
                    'username' => $log->username,
                    'ip_address' => $log->ipAddress,
                    'created_at' => $log->createdAt->format(\DateTimeInterface::ATOM),
                ],
            ]);
        } catch (\Throwable $e) {

            $this->logger->error("Failed to send audit log to endpoint: {$this->endpoint}", [
                'exception' => $e,
            ]);
        }
    }
}
