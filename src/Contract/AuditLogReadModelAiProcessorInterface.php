<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use Rcsofttech\AuditTrailBundle\Query\AuditLogReadModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Optional AI processor interface for processors that need an audit log snapshot.
 */
#[AutoconfigureTag('audit_trail.ai_processor')]
interface AuditLogReadModelAiProcessorInterface
{
    /**
     * Returns the namespace used under context["ai"] for this processor.
     */
    public function getNamespace(): string;

    /**
     * @return array<string, mixed> AI metadata that will be stored under context["ai"][namespace]
     */
    public function processAuditLog(AuditLogReadModel $audit): array;
}
