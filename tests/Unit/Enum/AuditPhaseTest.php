<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;

final class AuditPhaseTest extends TestCase
{
    public function testFromContextValueReturnsEnumForKnownString(): void
    {
        self::assertSame(AuditPhase::PostFlush, AuditPhase::fromContextValue('post_flush'));
    }

    public function testFromContextValueReturnsNullForUnknownString(): void
    {
        self::assertNull(AuditPhase::fromContextValue('unknown_phase'));
    }

    public function testFromContextValueReturnsEnumForEnumAndString(): void
    {
        self::assertSame(AuditPhase::PostLoad, AuditPhase::fromContextValue(AuditPhase::PostLoad));
        self::assertSame(AuditPhase::PostLoad, AuditPhase::fromContextValue('post_load'));
        self::assertNull(AuditPhase::fromContextValue(123));
    }

    public function testOnFlushPredicates(): void
    {
        self::assertTrue(AuditPhase::OnFlush->isOnFlush());
        self::assertFalse(AuditPhase::OnFlush->isDeferredPersistencePhase());
        self::assertFalse(AuditPhase::OnFlush->allowsAiProcessing());
        self::assertFalse(AuditPhase::OnFlush->isAsyncDispatchPhase());
    }

    public function testPostFlushPredicates(): void
    {
        self::assertFalse(AuditPhase::PostFlush->isOnFlush());
        self::assertTrue(AuditPhase::PostFlush->isDeferredPersistencePhase());
        self::assertTrue(AuditPhase::PostFlush->allowsAiProcessing());
        self::assertTrue(AuditPhase::PostFlush->isAsyncDispatchPhase());
    }

    public function testManualFlushPredicates(): void
    {
        self::assertFalse(AuditPhase::ManualFlush->isDeferredPersistencePhase());
        self::assertTrue(AuditPhase::ManualFlush->allowsAiProcessing());
        self::assertFalse(AuditPhase::ManualFlush->isAsyncDispatchPhase());
    }
}
