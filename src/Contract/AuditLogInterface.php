<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

use DateTimeImmutable;

interface AuditLogInterface
{
    public const string ACTION_CREATE = 'create';

    public const string ACTION_UPDATE = 'update';

    public const string ACTION_DELETE = 'delete';

    public const string ACTION_SOFT_DELETE = 'soft_delete';

    public const string ACTION_RESTORE = 'restore';

    public const string ACTION_REVERT = 'revert';

    public const string CONTEXT_USER_ID = '_audit_user_id';

    public const string CONTEXT_USERNAME = '_audit_username';

    public function getId(): ?int;

    public function getEntityClass(): string;

    public function setEntityClass(string $entityClass): self;

    public function getEntityId(): string;

    public function setEntityId(string $entityId): self;

    public function getAction(): string;

    public function setAction(string $action): self;

    /**
     * @return array<string, mixed>|null
     */
    public function getOldValues(): ?array;

    /**
     * @param array<string, mixed>|null $oldValues
     */
    public function setOldValues(?array $oldValues): self;

    /**
     * @return array<string, mixed>|null
     */
    public function getNewValues(): ?array;

    /**
     * @param array<string, mixed>|null $newValues
     */
    public function setNewValues(?array $newValues): self;

    /**
     * @return array<int, string>|null
     */
    public function getChangedFields(): ?array;

    /**
     * @param array<int, string>|null $changedFields
     */
    public function setChangedFields(?array $changedFields): self;

    public function getUserId(): ?string;

    public function setUserId(?string $userId): self;

    public function getUsername(): ?string;

    public function setUsername(?string $username): self;

    public function getIpAddress(): ?string;

    public function setIpAddress(?string $ipAddress): self;

    public function getUserAgent(): ?string;

    public function setUserAgent(?string $userAgent): self;

    public function getTransactionHash(): ?string;

    public function setTransactionHash(?string $transactionHash): self;

    public function getCreatedAt(): DateTimeImmutable;

    public function setCreatedAt(DateTimeImmutable $createdAt): self;

    public function getSignature(): ?string;

    public function setSignature(?string $signature): self;

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array;

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self;
}
