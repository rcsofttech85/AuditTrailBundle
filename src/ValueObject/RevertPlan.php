<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use Rcsofttech\AuditTrailBundle\Enum\AuditAction;

final readonly class RevertPlan
{
    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $previousValues
     * @param array<string, mixed> $fieldValues
     */
    public function __construct(
        public array $changes,
        public array $previousValues = [],
        public array $fieldValues = [],
        public bool $restoreSoftDelete = false,
    ) {
    }

    /**
     * @param array<string, mixed> $changes
     */
    public static function fromChanges(array $changes): self
    {
        return new self($changes);
    }

    /**
     * @param array<string, mixed> $changes
     * @param array<string, mixed> $previousValues
     * @param array<string, mixed> $fieldValues
     */
    public static function forFieldChanges(array $changes, array $previousValues, array $fieldValues): self
    {
        return new self($changes, $previousValues, $fieldValues);
    }

    public function isDeleteAction(): bool
    {
        return ($this->changes['action'] ?? null) === AuditAction::Delete->value;
    }

    public function isEmpty(): bool
    {
        return $this->changes === []
            && $this->previousValues === []
            && $this->fieldValues === []
            && !$this->restoreSoftDelete;
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        $legacy = ['changes' => $this->normalizeActions($this->changes)];

        if ($this->previousValues !== []) {
            $legacy['previousValues'] = $this->previousValues;
        }

        if ($this->fieldValues !== []) {
            $legacy['fieldValues'] = $this->fieldValues;
        }

        if ($this->restoreSoftDelete) {
            $legacy['restoreSoftDelete'] = true;
        }

        return $legacy;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function normalizeActions(array $values): array
    {
        if (($values['action'] ?? null) instanceof AuditAction) {
            $values['action'] = $values['action']->value;
        }

        return $values;
    }
}
