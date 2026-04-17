<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Closure;
use Override;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function is_scalar;

final class ContextResolver implements ContextResolverInterface
{
    /**
     * @param iterable<AuditContextContributorInterface> $contributors
     */
    public function __construct(
        private readonly UserResolverInterface $userResolver,
        private readonly DataMaskerInterface $dataMasker,
        private readonly ValueSerializerInterface $serializer,
        #[AutowireIterator('audit_trail.context_contributor')]
        private readonly iterable $contributors = [],
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, mixed> $newValues
     * @param array<string, mixed> $extraContext
     *
     * @return array{
     *     userId: ?string,
     *     username: ?string,
     *     ipAddress: ?string,
     *     userAgent: ?string,
     *     context: array<string, mixed>
     * }
     */
    #[Override]
    public function resolve(object $entity, string $action, array $newValues, array $extraContext): array
    {
        $userId = $extraContext[AuditLogInterface::CONTEXT_USER_ID]
            ?? $this->resolveUserContextValue('user_id', $this->userResolver->getUserId(...));
        $username = $extraContext[AuditLogInterface::CONTEXT_USERNAME]
            ?? $this->resolveUserContextValue('username', $this->userResolver->getUsername(...));
        $ipAddress = $extraContext[AuditLogInterface::CONTEXT_IP_ADDRESS]
            ?? $this->resolveUserContextValue('ip_address', $this->userResolver->getIpAddress(...));
        $userAgent = $extraContext[AuditLogInterface::CONTEXT_USER_AGENT]
            ?? $this->resolveUserContextValue('user_agent', $this->userResolver->getUserAgent(...));
        $context = $this->resolveContextPayload($extraContext, $entity, $action, $newValues);

        return [
            'userId' => $this->stringify($userId),
            'username' => $this->stringify($username),
            'ipAddress' => $ipAddress,
            'userAgent' => $userAgent,
            'context' => $this->dataMasker->redact($context),
        ];
    }

    private function stringify(mixed $value): ?string
    {
        if (is_scalar($value) || $value instanceof Stringable) {
            return (string) $value;
        }

        return null;
    }

    private function resolveUserContextValue(string $field, Closure $resolver): mixed
    {
        try {
            return $resolver();
        } catch (Throwable $exception) {
            $this->logger?->warning('Failed to resolve audit user context field.', [
                'field' => $field,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * @param array<string, mixed> $extraContext
     * @param array<string, mixed> $newValues
     *
     * @return array<string, mixed>
     */
    private function resolveContextPayload(array $extraContext, object $entity, string $action, array $newValues): array
    {
        try {
            return $this->buildContext($extraContext, $entity, $action, $newValues);
        } catch (Throwable $exception) {
            $this->logger?->warning('Failed to build audit context payload.', [
                'exception' => $exception,
            ]);

            return [];
        }
    }

    /**
     * @param array<string, mixed> $extraContext
     * @param array<string, mixed> $newValues
     *
     * @return array<string, mixed>
     */
    private function buildContext(array $extraContext, object $entity, string $action, array $newValues): array
    {
        // Remove internal "transport" keys so they don't pollute the JSON storage
        $context = array_diff_key($extraContext, [
            AuditLogInterface::CONTEXT_USER_ID => true,
            AuditLogInterface::CONTEXT_USERNAME => true,
            AuditLogInterface::CONTEXT_IP_ADDRESS => true,
            AuditLogInterface::CONTEXT_USER_AGENT => true,
        ]);

        $impersonatorId = $this->userResolver->getImpersonatorId();
        $impersonatorUsername = $this->userResolver->getImpersonatorUsername();
        if ($impersonatorId !== null || $impersonatorUsername !== null) {
            $context['impersonation'] = [
                'impersonator_id' => $impersonatorId,
                'impersonator_username' => $impersonatorUsername,
            ];
        }

        // Add custom context from contributors
        foreach ($this->contributors as $contributor) {
            $contribution = $contributor->contribute($entity, $action, $newValues);

            foreach ($contribution as $key => $val) {
                $context[$key] = $val;
            }
        }

        // This ensures extraContext and contributor data are both safe.
        foreach ($context as $key => $value) {
            $context[$key] = $this->serializer->serialize($value);
        }

        return $context;
    }
}
