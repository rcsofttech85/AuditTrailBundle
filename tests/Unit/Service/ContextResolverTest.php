<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use ArrayIterator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditContextContributorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Contract\UserResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\ValueSerializerInterface;
use Rcsofttech\AuditTrailBundle\Service\ContextResolver;
use RuntimeException;
use stdClass;

final class ContextResolverTest extends TestCase
{
    public function testResolveWithContext(): void
    {
        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUserId')->willReturn('u1');
        $userResolver->method('getUsername')->willReturn('admin');
        $userResolver->method('getIpAddress')->willReturn('127.0.0.1');
        $userResolver->method('getUserAgent')->willReturn('TestAgent');
        $userResolver->method('getImpersonatorId')->willReturn('u2');
        $userResolver->method('getImpersonatorUsername')->willReturn('superadmin');

        $dataMasker = self::createStub(DataMaskerInterface::class);
        $dataMasker->method('redact')->willReturnArgument(0);

        $contributor = self::createStub(AuditContextContributorInterface::class);
        $contributor->method('contribute')->willReturn(['custom' => 'data']);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $logger = self::createStub(LoggerInterface::class);

        $resolver = new ContextResolver(
            $userResolver,
            $dataMasker,
            $serializer,
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
        self::assertSame('val', $context['other'] ?? null);
        self::assertIsArray($context['impersonation'] ?? null);
        self::assertSame('u2', $context['impersonation']['impersonator_id'] ?? null);
        self::assertSame('superadmin', $context['impersonation']['impersonator_username'] ?? null);
        self::assertSame('data', $context['custom'] ?? null);
    }

    public function testResolvePrefersCapturedIpAndUserAgentFromContext(): void
    {
        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUserId')->willReturn('live_u1');
        $userResolver->method('getUsername')->willReturn('live_admin');
        $userResolver->method('getIpAddress')->willReturn(null);
        $userResolver->method('getUserAgent')->willReturn(null);
        $userResolver->method('getImpersonatorId')->willReturn(null);
        $userResolver->method('getImpersonatorUsername')->willReturn(null);

        $dataMasker = self::createStub(DataMaskerInterface::class);
        $dataMasker->method('redact')->willReturnArgument(0);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $resolver = new ContextResolver(
            $userResolver,
            $dataMasker,
            $serializer,
            new ArrayIterator([])
        );

        $entity = new stdClass();
        $result = $resolver->resolve($entity, 'access', [], [
            AuditLogInterface::CONTEXT_USER_ID => 'captured_u1',
            AuditLogInterface::CONTEXT_USERNAME => 'captured_admin',
            AuditLogInterface::CONTEXT_IP_ADDRESS => '127.0.0.10',
            AuditLogInterface::CONTEXT_USER_AGENT => 'CapturedAgent',
        ]);

        self::assertSame('captured_u1', $result['userId']);
        self::assertSame('captured_admin', $result['username']);
        self::assertSame('127.0.0.10', $result['ipAddress']);
        self::assertSame('CapturedAgent', $result['userAgent']);
        self::assertSame([], $result['context']);
    }

    public function testResolveLogsUserResolutionFailureWithoutBlankingWholePayload(): void
    {
        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUserId')->willThrowException(new RuntimeException('fail'));
        $userResolver->method('getUsername')->willReturn('admin');
        $userResolver->method('getIpAddress')->willReturn('127.0.0.1');
        $userResolver->method('getUserAgent')->willReturn('TestAgent');
        $userResolver->method('getImpersonatorId')->willReturn(null);
        $userResolver->method('getImpersonatorUsername')->willReturn(null);

        $dataMasker = self::createStub(DataMaskerInterface::class);
        $dataMasker->method('redact')->willReturnArgument(0);

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to resolve audit user context field.',
                self::callback(static function (array $context): bool {
                    return ($context['field'] ?? null) === 'user_id'
                        && isset($context['exception']);
                }),
            );

        $resolver = new ContextResolver(
            $userResolver,
            $dataMasker,
            $serializer,
            new ArrayIterator([]),
            $logger
        );

        $entity = new stdClass();
        $result = $resolver->resolve($entity, 'INSERT', [], []);

        self::assertNull($result['userId']);
        self::assertSame('admin', $result['username']);
        self::assertSame('127.0.0.1', $result['ipAddress']);
        self::assertSame('TestAgent', $result['userAgent']);
        self::assertEmpty($result['context']);
    }

    public function testResolveLogsContextBuildFailureAndReturnsEmptyContext(): void
    {
        $userResolver = self::createStub(UserResolverInterface::class);
        $userResolver->method('getUserId')->willReturn('u1');
        $userResolver->method('getUsername')->willReturn('admin');
        $userResolver->method('getIpAddress')->willReturn('127.0.0.1');
        $userResolver->method('getUserAgent')->willReturn('TestAgent');
        $userResolver->method('getImpersonatorId')->willReturn(null);
        $userResolver->method('getImpersonatorUsername')->willReturn(null);

        $dataMasker = self::createStub(DataMaskerInterface::class);
        $dataMasker->method('redact')->willReturnArgument(0);

        $contributor = self::createStub(AuditContextContributorInterface::class);
        $contributor->method('contribute')->willThrowException(new RuntimeException('boom'));

        $serializer = self::createStub(ValueSerializerInterface::class);
        $serializer->method('serialize')->willReturnArgument(0);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                'Failed to build audit context payload.',
                self::callback(static fn (array $context): bool => isset($context['exception'])),
            );

        $resolver = new ContextResolver(
            $userResolver,
            $dataMasker,
            $serializer,
            new ArrayIterator([$contributor]),
            $logger,
        );

        $result = $resolver->resolve(new stdClass(), 'INSERT', [], []);

        self::assertSame('u1', $result['userId']);
        self::assertSame('admin', $result['username']);
        self::assertSame('127.0.0.1', $result['ipAddress']);
        self::assertSame('TestAgent', $result['userAgent']);
        self::assertSame([], $result['context']);
    }
}
