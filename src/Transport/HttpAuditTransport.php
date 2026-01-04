<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Service\PendingIdResolver;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpAuditTransport implements AuditTransportInterface
{
    use PendingIdResolver;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $endpoint,
        private readonly AuditIntegrityServiceInterface $integrityService,
        private readonly array $headers = [],
        private readonly int $timeout = 5,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function send(AuditLogInterface $log, array $context = []): void
    {
        $entityId = $this->resolveEntityId($log, $context) ?? $log->getEntityId();

        $payload = [
            'entity_class' => $log->getEntityClass(),
            'entity_id' => $entityId,
            'action' => $log->getAction(),
            'old_values' => $log->getOldValues(),
            'new_values' => $log->getNewValues(),
            'changed_fields' => $log->getChangedFields(),
            'user_id' => $log->getUserId(),
            'username' => $log->getUsername(),
            'ip_address' => $log->getIpAddress(),
            'user_agent' => $log->getUserAgent(),
            'transaction_hash' => $log->getTransactionHash(),
            'signature' => $log->getSignature(),
            'context' => [...$log->getContext(), ...$context],
            'created_at' => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->headers;

        if ($this->integrityService->isEnabled()) {
            $headers['X-Signature'] = $this->integrityService->signPayload($jsonPayload);
        }

        $this->client->request('POST', $this->endpoint, [
            'headers' => $headers,
            'body' => $jsonPayload,
            'timeout' => $this->timeout,
        ]);
    }

    public function supports(string $phase, array $context = []): bool
    {
        return 'post_flush' === $phase;
    }
}
