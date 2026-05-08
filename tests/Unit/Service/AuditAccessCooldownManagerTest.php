<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditAccessCooldownManager;

use function preg_match;
use function strlen;

final class AuditAccessCooldownManagerTest extends TestCase
{
    public function testShouldSkipUsesPsr6SafeBoundedCacheKey(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = self::createStub(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);

        $cache->expects($this->once())
            ->method('getItem')
            ->with(self::callback(static function (string $key): bool {
                self::assertLessThanOrEqual(64, strlen($key));
                self::assertSame(0, preg_match('/[{}()\\/@:]/', $key));

                return true;
            }))
            ->willReturn($item);

        $manager = new AuditAccessCooldownManager($cache);

        self::assertFalse($manager->shouldSkip(
            'Rcsofttech\\AuditTrailBundle\\Tests\\Functional\\Entity\\CooldownPost:123e4567-e89b-12d3-a456-426614174000',
            'Rcsofttech\\AuditTrailBundle\\Tests\\Functional\\Entity\\CooldownPost',
            '123e4567-e89b-12d3-a456-426614174000',
            'user-with-a-very-long-identifier',
            60,
        ));
    }

    public function testPersistForRequestUsesPsr6SafeBoundedCacheKey(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $item = $this->createMock(CacheItemInterface::class);

        $cache->expects($this->once())
            ->method('getItem')
            ->with(self::callback(static function (string $key): bool {
                self::assertLessThanOrEqual(64, strlen($key));
                self::assertSame(0, preg_match('/[{}()\\/@:]/', $key));

                return true;
            }))
            ->willReturn($item);
        $item->expects($this->once())->method('set')->with(true)->willReturnSelf();
        $item->expects($this->once())->method('expiresAfter')->with(120)->willReturnSelf();
        $cache->expects($this->once())->method('save')->with($item)->willReturn(true);

        $manager = new AuditAccessCooldownManager($cache);
        $manager->persistForRequest(
            'Rcsofttech\\AuditTrailBundle\\Tests\\Functional\\Entity\\CooldownPost:123e4567-e89b-12d3-a456-426614174000',
            [AuditLogInterface::CONTEXT_USER_ID => 'user-with-a-very-long-identifier'],
            120,
        );
    }

    public function testShouldSkipGracefullyDegradesWhenCacheReadFails(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $logger = $this->createMock(LoggerInterface::class);

        $cache->expects($this->once())
            ->method('getItem')
            ->willThrowException(new InvalidArgumentException('Cache key is invalid.'));
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to read audit cooldown from cache',
                self::callback(static function (array $context): bool {
                    return isset($context['key'], $context['exception']);
                }),
            );

        $manager = new AuditAccessCooldownManager($cache, $logger);

        self::assertFalse($manager->shouldSkip(
            'entity:1',
            'Entity',
            '1',
            'user',
            60,
        ));
        self::assertTrue($manager->shouldSkip(
            'entity:1',
            'Entity',
            '1',
            'user',
            60,
        ));
    }

    public function testClearForRequestUsesPsr6SafeBoundedCacheKey(): void
    {
        $cache = $this->createMock(CacheItemPoolInterface::class);

        $cache->expects($this->once())
            ->method('deleteItem')
            ->with(self::callback(static function (string $key): bool {
                self::assertLessThanOrEqual(64, strlen($key));
                self::assertSame(0, preg_match('/[{}()\\/@:]/', $key));

                return true;
            }))
            ->willReturn(true);

        $manager = new AuditAccessCooldownManager($cache);
        $manager->clearForRequest(
            'Rcsofttech\\AuditTrailBundle\\Tests\\Functional\\Entity\\CooldownPost:123e4567-e89b-12d3-a456-426614174000',
            [AuditLogInterface::CONTEXT_USER_ID => 'user-with-a-very-long-identifier'],
        );
    }
}
