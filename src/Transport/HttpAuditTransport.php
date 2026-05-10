<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Transport;

use DateTimeInterface;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function sprintf;

use const JSON_THROW_ON_ERROR;

final readonly class HttpAuditTransport implements AuditTransportInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private HttpClientInterface $client,
        private string $endpoint,
        private AuditIntegrityServiceInterface $integrityService,
        private EntityIdResolverInterface $idResolver,
        private ?LoggerInterface $logger = null,
        private array $headers = [],
        private int $timeout = 5,
    ) {
    }

    #[Override]
    public function send(AuditTransportContext $context): AuditDeliveryResult
    {
        $log = $context->audit;
        $entityId = $this->resolveEntityId($context);
        if ($entityId === null) {
            throw new RuntimeException('Cannot send an HTTP audit payload before the entity ID has been resolved.');
        }

        $payload = [
            'entity_class' => $log->entityClass,
            'entity_id' => $entityId,
            'action' => $log->action->value,
            'old_values' => $log->oldValues,
            'new_values' => $log->newValues,
            'changed_fields' => $log->changedFields,
            'user_id' => $log->userId,
            'username' => $log->username,
            'ip_address' => $log->ipAddress,
            'user_agent' => $log->userAgent,
            'transaction_hash' => $log->transactionHash,
            'signature' => $log->signature,
            'context' => $log->context,
            'created_at' => $log->createdAt->format(DateTimeInterface::ATOM),
        ];

        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = $this->headers;
        $headers['Content-Type'] ??= 'application/json';

        if ($this->integrityService->isEnabled()) {
            $headers['X-Signature'] = $this->integrityService->signPayload($jsonPayload);
        }

        $response = $this->client->request('POST', $this->endpoint, [
            'headers' => $headers,
            'body' => $jsonPayload,
            'timeout' => $this->timeout,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode < 200 || $statusCode >= 300) {
            $message = sprintf(
                'HTTP audit transport failed for %s#%s with status code %d.',
                $log->entityClass,
                $entityId,
                $statusCode,
            );
            $this->logger?->error($message);

            throw new RuntimeException($message);
        }

        return AuditDeliveryResult::delivered();
    }

    #[Override]
    public function supports(AuditTransportContext $context): bool
    {
        return $context->phase->isAsyncDispatchPhase() && $this->resolveEntityId($context) !== null;
    }

    private function resolveEntityId(AuditTransportContext $context): ?string
    {
        return $this->idResolver->resolve($context->audit, $context) ?? $context->audit->entityId;
    }
}
