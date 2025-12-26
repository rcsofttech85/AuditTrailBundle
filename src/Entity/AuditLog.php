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
        new ORM\Index(name: 'user_action_date_idx', columns: ['user_id', 'action', 'created_at']),
        new ORM\Index(name: 'entity_date_idx', columns: ['entity_class', 'entity_id', 'created_at']),
        new ORM\Index(name: 'transaction_idx', columns: ['transaction_hash']),
    ]
)]
class AuditLog
{
    public const string ACTION_CREATE = 'create';
    public const string ACTION_UPDATE = 'update';
    public const string ACTION_DELETE = 'delete';
    public const string ACTION_SOFT_DELETE = 'soft_delete';
    public const string ACTION_RESTORE = 'restore';

    private const array VALID_ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
        self::ACTION_DELETE,
        self::ACTION_SOFT_DELETE,
        self::ACTION_RESTORE,
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $entityClass;

    #[ORM\Column(length: 255)]
    private string $entityId;

    #[ORM\Column(length: 50)]
    private string $action;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $oldValues = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $newValues = null;

    /** @var array<int, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $changedFields = null;

    #[ORM\Column(nullable: true)]
    private ?int $userId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 50, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $transactionHash = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    // Getters

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEntityClass(): string
    {
        return $this->entityClass;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOldValues(): ?array
    {
        return $this->oldValues;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getNewValues(): ?array
    {
        return $this->newValues;
    }

    /**
     * @return array<int, string>|null
     */
    public function getChangedFields(): ?array
    {
        return $this->changedFields;
    }

    public function getUserId(): ?int
    {
        return $this->userId;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getTransactionHash(): ?string
    {
        return $this->transactionHash;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Setters with Validation

    public function setEntityClass(string $entityClass): self
    {
        $trimmed = trim($entityClass);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Entity class cannot be empty');
        }

        $this->entityClass = $trimmed;

        return $this;
    }

    public function setEntityId(string $entityId): self
    {
        $trimmed = trim($entityId);
        if ('' === $trimmed) {
            throw new \InvalidArgumentException('Entity ID cannot be empty');
        }

        $this->entityId = $trimmed;

        return $this;
    }

    public function setAction(string $action): self
    {
        if (!\in_array($action, self::VALID_ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid action "%s". Must be one of: %s', $action, implode(', ', self::VALID_ACTIONS)));
        }

        $this->action = $action;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $oldValues
     */
    public function setOldValues(?array $oldValues): self
    {
        $this->oldValues = $oldValues;

        return $this;
    }

    /**
     * @param array<string, mixed>|null $newValues
     */
    public function setNewValues(?array $newValues): self
    {
        $this->newValues = $newValues;

        return $this;
    }

    /**
     * @param array<int, string>|null $changedFields
     */
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
        if (null !== $ipAddress && !filter_var($ipAddress, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException(sprintf('Invalid IP address format: "%s"', $ipAddress));
        }

        $this->ipAddress = $ipAddress;

        return $this;
    }

    public function setUserAgent(?string $userAgent): self
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function setTransactionHash(?string $transactionHash): self
    {
        $this->transactionHash = $transactionHash;

        return $this;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }
}
