<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Contract;

interface DataMaskerInterface
{
    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function redact(array $data): array;

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $sensitiveFields [field => mask]
     *
     * @return array<string, mixed>
     */
    public function mask(array $data, array $sensitiveFields): array;
}
