<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use DateTimeInterface;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function sprintf;

use const JSON_THROW_ON_ERROR;

final class HttpAuditTransport implements AuditTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $endpoint,
        private readonly AuditIntegrityServiceInterface $integrityService,
        private readonly EntityIdResolverInterface $idResolver,
        private readonly ?LoggerInterface $logger = null,
        private readonly array $headers = [],
        private readonly int $timeout = 5,
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    #[Override]
    public function send(AuditLog $log, array $context = []): void
    {
        $entityId = $this->idResolver->resolve($log, $context) ?? $log->entityId;

        $payload = [
            'entity_class' => $log->entityClass,
            'entity_id' => $entityId,
            'action' => $log->action,
            'old_values' => $log->oldValues,
            'new_values' => $log->newValues,
            'changed_fields' => $log->changedFields,
            'user_id' => $log->userId,
            'username' => $log->username,
            'ip_address' => $log->ipAddress,
            'user_agent' => $log->userAgent,
            'transaction_hash' => $log->transactionHash,
            'signature' => $log->signature,
            'context' => [...$log->context, ...$context],
            'created_at' => $log->createdAt->format(DateTimeInterface::ATOM),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->headers;

        if ($this->integrityService->isEnabled()) {
            $headers['X-Signature'] = $this->integrityService->signPayload($jsonPayload);
        }

        $response = $this->client->request('POST', $this->endpoint, [
            'headers' => $headers,
            'body' => $jsonPayload,
            'timeout' => $this->timeout,
        ]);

        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            $this->logger?->error(sprintf(
                'HTTP audit transport failed for %s#%s with status code %d: %s',
                $log->entityClass,
                $entityId,
                $response->getStatusCode(),
                $response->getContent(false)
            ));
        }
    }

    #[Override]
    public function supports(string $phase, array $context = []): bool
    {
        return $phase === 'post_flush' || $phase === 'post_load';
    }
}
