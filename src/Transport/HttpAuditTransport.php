<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpAuditTransport implements AuditTransportInterface
{
    use PendingIdResolver;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $endpoint,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLog $log, array $context = []): void
    {
        $entityId = $this->resolveEntityId($log, $context) ?? $log->getEntityId();

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
                'timeout' => 5,
                'max_duration' => 10,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("Failed to send audit log to endpoint: {$this->endpoint}", [
                'exception' => $e->getMessage(),
                'entity_class' => $log->getEntityClass(),
            ]);
            throw $e;
        }
    }

    public function supports(string $phase): bool
    {
        return 'post_flush' === $phase;
    }
}
