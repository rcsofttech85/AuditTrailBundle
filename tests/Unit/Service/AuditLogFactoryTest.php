<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Enum\AuditAction;
use Rcsofttech\AuditTrailBundle\Service\AuditLogFactory;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use RuntimeException;
use stdClass;

final class AuditLogFactoryTest extends TestCase
{
    /** @var ClockInterface&Stub */
    private ClockInterface $clock;

    /** @var (ContextResolverInterface&Stub)|(ContextResolverInterface&MockObject) */
    private ContextResolverInterface $contextResolver;

    /** @var (EntityIdResolverInterface&Stub)|(EntityIdResolverInterface&MockObject) */
    private EntityIdResolverInterface $idResolver;

    /** @var (LoggerInterface&Stub)|(LoggerInterface&MockObject) */
    private LoggerInterface $logger;

    /** @var EntityManagerInterface&Stub */
    private EntityManagerInterface $entityManager;

    private AuditLogFactory $factory;

    protected function setUp(): void
    {
        $this->clock = self::createStub(ClockInterface::class);
        $this->clock->method('now')->willReturn(new DateTimeImmutable('2026-05-02 12:34:56+00:00'));

        $this->contextResolver = self::createStub(ContextResolverInterface::class);
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->entityManager = self::createStub(EntityManagerInterface::class);

        $this->factory = $this->createFactory();
    }

    public function testCreateUsesResolvedDeleteIdWhenEntityIdIsPending(): void
    {
        $entity = new stdClass();

        $idResolver = $this->useIdResolverMock();
        $idResolver->expects($this->once())
            ->method('resolveFromEntity')
            ->with($entity, $this->entityManager)
            ->willReturn(AuditLogInterface::PENDING_ID);
        $idResolver->expects($this->once())
            ->method('resolveFromValues')
            ->with($entity, ['id' => 42], $this->entityManager)
            ->willReturn('42');

        $this->contextResolver->method('resolve')->willReturn($this->resolvedContext());

        $log = $this->factory->create(
            $entity,
            AuditAction::Delete,
            ['id' => 42],
            null,
            [],
            $this->entityManager,
        );

        self::assertSame('42', $log->entityId);
    }

    public function testCreateNullifiesInvalidIpAndLogsWarning(): void
    {
        $entity = new stdClass();
        $logger = $this->useLoggerMock();
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Invalid IP address format detected during audit'));

        $this->idResolver->method('resolveFromEntity')->willReturn('7');
        $this->contextResolver->method('resolve')->willReturn($this->resolvedContext(ipAddress: 'not-an-ip'));
        $this->factory = $this->createFactory($logger);

        $log = $this->factory->create($entity, AuditAction::Update, ['name' => 'old'], ['name' => 'new'], [], $this->entityManager);

        self::assertNull($log->ipAddress);
    }

    public function testCreateFallsBackWhenContextResolutionFails(): void
    {
        $entity = new stdClass();
        $logger = $this->useLoggerMock();
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringStartsWith('Failed to resolve audit context: resolver failed'));

        $contextResolver = $this->createMock(ContextResolverInterface::class);
        $contextResolver->expects($this->once())
            ->method('resolve')
            ->willThrowException(new RuntimeException('resolver failed'));

        $this->idResolver->method('resolveFromEntity')->willReturn('11');
        $this->factory = $this->createFactory($logger, $contextResolver);

        $log = $this->factory->create($entity, AuditAction::Create, null, ['name' => 'new'], [], $this->entityManager);

        self::assertSame(['_error' => 'Context resolution failed'], $log->context);
        self::assertNull($log->userId);
        self::assertNull($log->username);
    }

    public function testCreateTruncatesOversizedContextAndLogsWarning(): void
    {
        $entity = new stdClass();
        $logger = $this->useLoggerMock();
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit context for stdClass#15 truncated'));

        $this->idResolver->method('resolveFromEntity')->willReturn('15');
        $oversizedContext = [];
        for ($i = 0; $i < 16; ++$i) {
            $oversizedContext['payload_'.$i] = str_repeat('a', ContextSanitizer::MAX_STRING_BYTES);
        }

        $this->contextResolver->method('resolve')->willReturn($this->resolvedContext(
            context: $oversizedContext,
        ));
        $this->factory = $this->createFactory($logger);

        $log = $this->factory->create($entity, AuditAction::Create, null, ['name' => 'new'], [], $this->entityManager);

        self::assertTrue($log->context['_truncated'] ?? false);
        self::assertArrayHasKey('_original_size', $log->context);
    }

    public function testCreateOmitsChangedFieldsForNonDiffableActions(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('3');
        $this->contextResolver->method('resolve')->willReturn($this->resolvedContext());

        $log = $this->factory->create($entity, AuditAction::Create, null, ['name' => 'new'], [], $this->entityManager);

        self::assertNull($log->changedFields);
        self::assertTrue($log->isContextNormalized());
    }

    private function createFactory(
        ?LoggerInterface $logger = null,
        ?ContextResolverInterface $contextResolver = null,
    ): AuditLogFactory {
        return new AuditLogFactory(
            $this->clock,
            new TransactionIdGenerator(),
            $contextResolver ?? $this->contextResolver,
            $this->idResolver,
            new ContextSanitizer(),
            $logger ?? $this->logger,
        );
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{userId: string, username: string, ipAddress: ?string, userAgent: string, context: array<string, mixed>}
     */
    private function resolvedContext(
        ?string $ipAddress = '127.0.0.1',
        array $context = ['source' => 'test'],
    ): array {
        return [
            'userId' => '1',
            'username' => 'tester',
            'ipAddress' => $ipAddress,
            'userAgent' => 'phpunit',
            'context' => $context,
        ];
    }

    /**
     * @return EntityIdResolverInterface&MockObject
     */
    private function useIdResolverMock(): EntityIdResolverInterface
    {
        $resolver = $this->createMock(EntityIdResolverInterface::class);
        $this->idResolver = $resolver;
        $this->factory = $this->createFactory();

        return $resolver;
    }

    /**
     * @return LoggerInterface&MockObject
     */
    private function useLoggerMock(): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $this->logger = $logger;

        return $logger;
    }
}
