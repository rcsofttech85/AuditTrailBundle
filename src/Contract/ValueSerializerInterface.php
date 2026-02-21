<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface ValueSerializerInterface
{
    public function serialize(mixed $value, int $depth = 0): mixed;

    public function serializeAssociation(mixed $value): mixed;
}
