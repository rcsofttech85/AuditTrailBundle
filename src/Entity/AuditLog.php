<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;

use function in_array;
use function sprintf;

use const FILTER_VALIDATE_IP;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Index(name: 'created_idx', columns: ['created_at'])]
#[ORM\Index(name: 'user_action_date_idx', columns: ['user_id', 'action', 'created_at'])]
#[ORM\Index(name: 'entity_date_idx', columns: ['entity_class', 'entity_id', 'created_at'])]
#[ORM\Index(name: 'transaction_idx', columns: ['transaction_hash'])]
class AuditLog implements AuditLogInterface
{
    private const array VALID_ACTIONS = [
        AuditLogInterface::ACTION_CREATE,
        AuditLogInterface::ACTION_UPDATE,
        AuditLogInterface::ACTION_DELETE,
        AuditLogInterface::ACTION_SOFT_DELETE,
        AuditLogInterface::ACTION_RESTORE,
        AuditLogInterface::ACTION_REVERT,
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public private(set) ?int $id = null;

    #[ORM\Column(length: 255)]
    public private(set) string $entityClass {
        get => $this->entityClass;
        set {
            $trimmed = mb_trim($value);
            if ($trimmed === '') {
                throw new InvalidArgumentException('Entity class cannot be empty');
            }
            $this->entityClass = $trimmed;
        }
    }

    #[ORM\Column(length: 255)]
    public private(set) string $entityId {
        get => $this->entityId;
        set {
            $trimmed = mb_trim($value);
            if ($trimmed === '') {
                throw new InvalidArgumentException('Entity ID cannot be empty');
            }
            $this->entityId = $trimmed;
        }
    }

    #[ORM\Column(length: 50)]
    public private(set) string $action {
        get => $this->action;
        set {
            if (!in_array($value, self::VALID_ACTIONS, true)) {
                throw new InvalidArgumentException(sprintf('Invalid action "%s". Must be one of: %s', $value, implode(', ', self::VALID_ACTIONS)));
            }
            $this->action = $value;
        }
    }

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $oldValues = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $newValues = null;

    /** @var array<int, string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public private(set) ?array $changedFields = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $userId = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $username = null;

    #[ORM\Column(length: 50, nullable: true)]
    public private(set) ?string $ipAddress = null {
        get => $this->ipAddress;
        set {
            if ($value !== null && false === filter_var($value, FILTER_VALIDATE_IP)) {
                throw new InvalidArgumentException(sprintf('Invalid IP address format: "%s"', $value));
            }
            $this->ipAddress = $value;
        }
    }

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $userAgent = null;

    #[ORM\Column(length: 40, nullable: true)]
    public private(set) ?string $transactionHash = null;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    public private(set) array $context = [];

    #[ORM\Column]
    public private(set) DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
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

    public function getUserId(): ?string
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

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    // Setters with Validation

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

    public function setUserId(?string $userId): self
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

    public function setTransactionHash(?string $transactionHash): self
    {
        $this->transactionHash = $transactionHash;

        return $this;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    #[ORM\Column(length: 128, nullable: true)]
    public private(set) ?string $signature = null;

    public function getSignature(): ?string
    {
        return $this->signature;
    }

    public function setSignature(?string $signature): self
    {
        $this->signature = $signature;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setContext(array $context): self
    {
        $this->context = $context;

        return $this;
    }
}
