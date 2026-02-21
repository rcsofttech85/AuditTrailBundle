<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface AuditLogInterface
{
    public const string PENDING_ID = 'pending';

    public const string ACTION_CREATE = 'create';

    public const string ACTION_UPDATE = 'update';

    public const string ACTION_DELETE = 'delete';

    public const string ACTION_SOFT_DELETE = 'soft_delete';

    public const string ACTION_RESTORE = 'restore';

    public const string ACTION_REVERT = 'revert';

    public const string ACTION_ACCESS = 'access';

    public const array ALL_ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
        self::ACTION_DELETE,
        self::ACTION_SOFT_DELETE,
        self::ACTION_RESTORE,
        self::ACTION_REVERT,
        self::ACTION_ACCESS,
    ];

    public const string CONTEXT_USER_ID = '_audit_user_id';

    public const string CONTEXT_USERNAME = '_audit_username';

    public string $entityId { get; set; }

    /** @var array<string, mixed> */
    public array $context { get; set; }

    public ?string $signature { get; set; }
}
