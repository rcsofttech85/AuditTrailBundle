<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

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
 */
#[AutoconfigureTag('audit_trail.ai_processor')]
interface AuditLogAiProcessorInterface
{
    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed> AI metadata that will be stored under context["ai"]
     */
    public function process(array $context, ?object $entity = null): array;
}
