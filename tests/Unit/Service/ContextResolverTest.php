<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use ArrayIterator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\ContextResolver;
use RuntimeException;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
final class ContextResolverTest extends TestCase
{
    public function testResolveWithContext(): void
    {
        $userResolver = $this->createMock(UserResolverInterface::class);
        $userResolver->method('getUserId')->willReturn('u1');
        $userResolver->method('getUsername')->willReturn('admin');
        $userResolver->method('getIpAddress')->willReturn('127.0.0.1');
        $userResolver->method('getUserAgent')->willReturn('TestAgent');
        $userResolver->method('getImpersonatorId')->willReturn('u2');
        $userResolver->method('getImpersonatorUsername')->willReturn('superadmin');

        $dataMasker = $this->createMock(DataMaskerInterface::class);
        $dataMasker->method('redact')->willReturnArgument(0);

        $contributor = $this->createMock(AuditContextContributorInterface::class);
        $contributor->method('contribute')->willReturn(['custom' => 'data']);

        $logger = $this->createMock(LoggerInterface::class);

        $resolver = new ContextResolver(
            $userResolver,
            $dataMasker,
            new ArrayIterator([$contributor]),
            $logger
        );

        $entity = new stdClass();
        $result = $resolver->resolve($entity, 'INSERT', [], [
            AuditLogInterface::CONTEXT_USER_ID => 'ctx_u1',
            AuditLogInterface::CONTEXT_USERNAME => 'ctx_admin',
            'other' => 'val',
        ]);

        self::assertSame('ctx_u1', $result['userId']);
        self::assertSame('ctx_admin', $result['username']);
        self::assertSame('127.0.0.1', $result['ipAddress']);
        self::assertSame('TestAgent', $result['userAgent']);

        $context = $result['context'];
        self::assertSame('val', $context['other']);
        self::assertSame('u2', $context['impersonation']['impersonator_id']);
        self::assertSame('superadmin', $context['impersonation']['impersonator_username']);
        self::assertSame('data', $context['custom']);
    }

    public function testResolveCatchesExceptionAndLogs(): void
    {
        $userResolver = $this->createMock(UserResolverInterface::class);
        $userResolver->method('getUserId')->willThrowException(new RuntimeException('fail'));

        $dataMasker = $this->createMock(DataMaskerInterface::class);
        $dataMasker->method('redact')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $resolver = new ContextResolver(
            $userResolver,
            $dataMasker,
            new ArrayIterator([]),
            $logger
        );

        $entity = new stdClass();
        $result = $resolver->resolve($entity, 'INSERT', [], []);

        self::assertNull($result['userId']);
        self::assertNull($result['username']);
        self::assertNull($result['ipAddress']);
        self::assertNull($result['userAgent']);
        self::assertEmpty($result['context']);
    }
}
