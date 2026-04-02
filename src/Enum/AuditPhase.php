<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Enum;

use function is_string;

enum AuditPhase: string
{
    case OnFlush = 'on_flush';
    case PostFlush = 'post_flush';
    case PostLoad = 'post_load';
    case BatchFlush = 'batch_flush';
    case ManualFlush = 'manual_flush';

    public function isOnFlush(): bool
    {
        return $this === self::OnFlush;
    }

    public function isDeferredPersistencePhase(): bool
    {
        return match ($this) {
            self::PostFlush, self::PostLoad, self::BatchFlush => true,
            default => false,
        };
    }

    public function allowsAiProcessing(): bool
    {
        return match ($this) {
            self::PostFlush, self::BatchFlush, self::ManualFlush => true,
            default => false,
        };
    }

    public function isAsyncDispatchPhase(): bool
    {
        return match ($this) {
            self::PostFlush, self::PostLoad => true,
            default => false,
        };
    }

    public static function fromContextValue(mixed $phase): ?self
    {
        return match (true) {
            $phase instanceof self => $phase,
            is_string($phase) => self::tryFrom($phase),
            default => null,
        };
    }
}
