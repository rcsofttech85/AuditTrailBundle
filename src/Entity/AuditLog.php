<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(
    indexes: [
        new ORM\Index(name: 'entity_idx', columns: ['entity_class', 'entity_id']),
        new ORM\Index(name: 'user_idx', columns: ['user_id']),
        new ORM\Index(name: 'action_idx', columns: ['action']),
        new ORM\Index(name: 'created_idx', columns: ['created_at']),
    ]
)]
final class AuditLog
{
    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_DELETE = 'delete';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255)]
    public private(set) string $entityClass;

    #[ORM\Column(length: 255)]
    public private(set) string $entityId;

    #[ORM\Column(length: 50)]
    public private(set) string $action;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $oldValues = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $newValues = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $changedFields = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $userId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $username = null;

    #[ORM\Column(length: 45, nullable: true)]
    public private(set) ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $userAgent = null;

    #[ORM\Column]
    public private(set) \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function setEntityClass(string $entityClass): self
    {
        $this->entityClass = $entityClass;
        return $this;
    }

    public function setEntityId(string $entityId): self
    {
        $this->entityId = $entityId;
        return $this;
    }

    public function setAction(string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function setOldValues(?array $oldValues): self
    {
        $this->oldValues = $oldValues;
        return $this;
    }

    public function setNewValues(?array $newValues): self
    {
        $this->newValues = $newValues;
        return $this;
    }

    public function setChangedFields(?array $changedFields): self
    {
        $this->changedFields = $changedFields;
        return $this;
    }

    public function setUserId(?int $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function setUsername(?string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function setIpAddress(?string $ipAddress): self
    {
        $this->ipAddress = $ipAddress;
        return $this;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;
        return $this;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }
}
