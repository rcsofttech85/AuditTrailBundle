<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

use function trigger_deprecation;

trigger_deprecation(
    'rcsofttech/audit-trail-bundle',
    '4.3',
    'The "%s" interface is deprecated since rcsofttech/audit-trail-bundle 4.3 and will be removed in 5.0; implement "%s" instead.',
    AuditLogAiProcessorInterface::class,
    AuditLogReadModelAiProcessorInterface::class,
);

/**
 * Optional extension point for AI-oriented processing of audit logs before
 * signing and transport dispatch.
 *
 * This keeps the bundle Symfony AI-ready without coupling the core package to
 * any specific AI library or provider. Implementations should keep AI output:
 * - structured and compact
 * - scoped to returned AI metadata only
 * - optional and non-critical
 * - safe to omit when external AI systems are unavailable
 *
 * @deprecated since rcsofttech/audit-trail-bundle 4.3, will be removed in 5.0;
 *             implement AuditLogReadModelAiProcessorInterface instead.
 */
#[AutoconfigureTag('audit_trail.ai_processor')]
interface AuditLogAiProcessorInterface
{
    /**
     * Returns the namespace used under context["ai"] for this processor.
     */
    public function getNamespace(): string;

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> AI metadata that will be stored under context["ai"][namespace]
     */
    public function process(array $context, ?object $entity = null): array;
}
