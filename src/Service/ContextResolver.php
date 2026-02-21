<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Service;

use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
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
    public function resolve(object $entity, string $action, array $newValues, array $extraContext): array
    {
        $userId = null;
        $username = null;
        $ipAddress = null;
        $userAgent = null;
        $context = [];

        try {
            $userId = $extraContext[AuditLogInterface::CONTEXT_USER_ID] ?? $this->userResolver->getUserId();
            $username = $extraContext[AuditLogInterface::CONTEXT_USERNAME] ?? $this->userResolver->getUsername();
            $ipAddress = $this->userResolver->getIpAddress();
            $userAgent = $this->userResolver->getUserAgent();

            $context = $this->buildContext($extraContext, $entity, $action, $newValues);
        } catch (Throwable $e) {
            $this->logger?->error('Failed to resolve audit context', ['exception' => $e->getMessage()]);
        }

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
        ]);

        $impersonatorId = $this->userResolver->getImpersonatorId();
        if ($impersonatorId !== null) {
            $context['impersonation'] = [
                'impersonator_id' => $impersonatorId,
                'impersonator_username' => $this->userResolver->getImpersonatorUsername(),
            ];
        }

        // Add custom context from contributors
        foreach ($this->contributors as $contributor) {
            $context = [
                ...$context,
                ...$contributor->contribute($entity, $action, $newValues),
            ];
        }

        return $context;
    }
}
