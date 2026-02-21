<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Repository\AuditLogRepository;
use Symfony\Bridge\Doctrine\IdGenerator\UuidGenerator;
use Symfony\Component\Uid\Uuid;

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
    private const array VALID_ACTIONS = AuditLogInterface::ALL_ACTIONS;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UuidGenerator::class)]
    public private(set) ?Uuid $id = null;

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     * @param array<int, string>|null   $changedFields
     * @param array<string, mixed>      $context
     */
    public function __construct(
        #[ORM\Column(length: 255)]
        public private(set) string $entityClass {
            set {
                $trimmed = mb_trim($value);
                if ($trimmed === '') {
                    throw new InvalidArgumentException('Entity class cannot be empty');
                }
                $this->entityClass = $trimmed;
            }
        },
        #[ORM\Column(length: 255)]
        public string $entityId {
            get => $this->entityId;
            set {
                $this->checkSealed();
                $trimmed = mb_trim($value);
                if ($trimmed === '') {
                    throw new InvalidArgumentException('Entity ID cannot be empty');
                }
                $this->entityId = $trimmed;
            }
        },
        #[ORM\Column(length: 50)]
        public private(set) string $action {
            set {
                if (!in_array($value, self::VALID_ACTIONS, true)) {
                    throw new InvalidArgumentException(sprintf('Invalid action "%s". Must be one of: %s', $value, implode(', ', self::VALID_ACTIONS)));
                }
                $this->action = $value;
            }
        },
        #[ORM\Column]
        public private(set) DateTimeImmutable $createdAt = new DateTimeImmutable(),
        #[ORM\Column(type: Types::JSON, nullable: true)]
        public private(set) ?array $oldValues = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        public private(set) ?array $newValues = null,
        #[ORM\Column(type: Types::JSON, nullable: true)]
        public private(set) ?array $changedFields = null,
        #[ORM\Column(length: 40, nullable: true)]
        public private(set) ?string $transactionHash = null,
        #[ORM\Column(length: 255, nullable: true)]
        public private(set) ?string $userId = null,
        #[ORM\Column(length: 255, nullable: true)]
        public private(set) ?string $username = null,
        #[ORM\Column(length: 50, nullable: true)]
        public private(set) ?string $ipAddress = null {
            set {
                if ($value !== null && false === filter_var($value, FILTER_VALIDATE_IP)) {
                    throw new InvalidArgumentException(sprintf('Invalid IP address format: "%s"', $value));
                }
                $this->ipAddress = $value;
            }
        },
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        public private(set) ?string $userAgent = null,
        #[ORM\Column(type: Types::JSON)]
        public array $context = [] {
            get => $this->context;
            set {
                $this->checkSealed();
                $this->context = $value;
            }
        },
        #[ORM\Column(length: 128, nullable: true)]
        public ?string $signature = null {
            get => $this->signature;
            set {
                $this->checkSealed();
                $this->signature = $value;
            }
        },
    ) {
    }

    private bool $isSealed = false;

    public function seal(): void
    {
        $this->isSealed = true;
    }

    private function checkSealed(): void
    {
        if ($this->isSealed) {
            throw new LogicException('Cannot modify a sealed audit log.');
        }
    }
}
