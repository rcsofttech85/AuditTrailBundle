<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface AuditLogInterface
{
    public const string PENDING_ID = 'pending';

    public const string CONTEXT_USER_ID = '_audit_user_id';

    public const string CONTEXT_USERNAME = '_audit_username';

    public const string CONTEXT_IP_ADDRESS = '_audit_ip_address';

    public const string CONTEXT_USER_AGENT = '_audit_user_agent';

    public string $entityId { get; set; }

    /** @var array<string, mixed> */
    public array $context { get; set; }

    public ?string $signature { get; set; }
}
