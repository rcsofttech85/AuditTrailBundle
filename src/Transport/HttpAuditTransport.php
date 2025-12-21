<?php

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

    public function send(AuditLog $log, array $context = []): void
    {




        if (($context['phase'] ?? '') !== 'post_flush') {
            return;
        }

        $entityId = $log->getEntityId();
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
            $this->client->request('POST', $this->endpoint, [
                'json' => [
                    'entity_class' => $log->getEntityClass(),
                    'entity_id' => $entityId,
                    'action' => $log->getAction(),
                    'old_values' => $log->getOldValues(),
                    'new_values' => $log->getNewValues(),
                    'user_id' => $log->getUserId(),
                    'username' => $log->getUsername(),
                    'ip_address' => $log->getIpAddress(),
                    'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
                ],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send audit log to endpoint: {$this->endpoint}", [
                'exception' => $e,
            ]);
        }
    }
}
