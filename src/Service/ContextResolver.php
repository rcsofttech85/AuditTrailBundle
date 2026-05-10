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
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Stringable;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Throwable;

use function is_scalar;

final readonly class ContextResolver implements ContextResolverInterface
{
    /**
     * @param iterable<AuditContextContributorInterface> $contributors
     */
    public function __construct(
        private UserResolverInterface $userResolver,
        private DataMaskerInterface $dataMasker,
        private ValueSerializerInterface $serializer,
        #[AutowireIterator('audit_trail.context_contributor')]
        private iterable $contributors = [],
        private ?LoggerInterface $logger = null,
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
    public function resolve(object $entity, AuditAction $action, array $newValues, array $extraContext): array
    {
        $userId = $this->resolveContextValue(
            $extraContext,
            AuditLogInterface::CONTEXT_USER_ID,
            'user_id',
            $this->userResolver->getUserId(...),
        );
        $username = $this->resolveContextValue(
            $extraContext,
            AuditLogInterface::CONTEXT_USERNAME,
            'username',
            $this->userResolver->getUsername(...),
        );
        $ipAddress = $this->resolveContextValue(
            $extraContext,
            AuditLogInterface::CONTEXT_IP_ADDRESS,
            'ip_address',
            $this->userResolver->getIpAddress(...),
        );
        $userAgent = $this->resolveContextValue(
            $extraContext,
            AuditLogInterface::CONTEXT_USER_AGENT,
            'user_agent',
            $this->userResolver->getUserAgent(...),
        );
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

    /**
     * @param array<string, mixed> $extraContext
     */
    private function resolveContextValue(
        array $extraContext,
        string $contextKey,
        string $field,
        Closure $resolver,
    ): mixed {
        return $extraContext[$contextKey] ?? $this->resolveUserContextValue($field, $resolver);
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
    private function resolveContextPayload(array $extraContext, object $entity, AuditAction $action, array $newValues): array
    {
        $context = $this->buildBaseContext($extraContext);
        $this->appendImpersonationContext($context);
        $this->appendContributorContext($context, $entity, $action, $newValues);

        return $this->serializeContext($context);
    }

    /**
     * @param array<string, mixed> $extraContext
     *
     * @return array<string, mixed>
     */
    private function buildBaseContext(array $extraContext): array
    {
        // Remove internal "transport" keys so they don't pollute the JSON storage
        return array_diff_key($extraContext, [
            AuditLogInterface::CONTEXT_USER_ID => true,
            AuditLogInterface::CONTEXT_USERNAME => true,
            AuditLogInterface::CONTEXT_IP_ADDRESS => true,
            AuditLogInterface::CONTEXT_USER_AGENT => true,
        ]);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function appendImpersonationContext(array &$context): void
    {
        $impersonatorId = $this->userResolver->getImpersonatorId();
        $impersonatorUsername = $this->userResolver->getImpersonatorUsername();
        if ($impersonatorId !== null || $impersonatorUsername !== null) {
            $context['impersonation'] = [
                'impersonator_id' => $impersonatorId,
                'impersonator_username' => $impersonatorUsername,
            ];
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $newValues
     */
    private function appendContributorContext(array &$context, object $entity, AuditAction $action, array $newValues): void
    {
        foreach ($this->contributors as $contributor) {
            try {
                $contribution = $contributor->contribute($entity, $action, $newValues);
            } catch (Throwable $exception) {
                $this->logger?->warning('Failed to build audit context payload.', [
                    'contributor' => $contributor::class,
                    'exception' => $exception,
                ]);

                continue;
            }

            foreach ($contribution as $key => $val) {
                $context[$key] = $val;
            }
        }
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function serializeContext(array $context): array
    {
        foreach ($context as $key => $value) {
            try {
                $context[$key] = $this->serializer->serialize($value);
            } catch (Throwable $exception) {
                $this->logger?->warning('Failed to build audit context payload.', [
                    'key' => $key,
                    'exception' => $exception,
                ]);

                unset($context[$key]);
            }
        }

        return $context;
    }
}
