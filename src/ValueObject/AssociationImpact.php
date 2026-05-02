<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\ValueObject;

use function spl_object_id;

final readonly class AssociationImpact
{
    /**
     * @param array<int, int|string> $old
     * @param array<int, int|string> $new
     */
    public function __construct(
        public object $entity,
        public string $field,
        public array $old,
        public array $new,
    ) {
    }

    public function key(): string
    {
        return spl_object_id($this->entity).':'.$this->field;
    }

    /**
     * @return array{entity: object, field: string, old: array<int, int|string>, new: array<int, int|string>}
     */
    public function toArray(): array
    {
        return [
            'entity' => $this->entity,
            'field' => $this->field,
            'old' => $this->old,
            'new' => $this->new,
        ];
    }
}
