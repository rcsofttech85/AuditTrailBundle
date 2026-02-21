<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditMetadataManagerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditVoterInterface;
use Rcsofttech\AuditTrailBundle\Contract\ContextResolverInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Service\AuditService;
use Rcsofttech\AuditTrailBundle\Service\EntityDataExtractor;
use Rcsofttech\AuditTrailBundle\Service\TransactionIdGenerator;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
class AuditServiceTest extends TestCase
{
    private EntityManagerInterface&MockObject $entityManager;

    private ClockInterface&MockObject $clock;

    private LoggerInterface&MockObject $logger;

    private TransactionIdGenerator&MockObject $transactionIdGenerator;

    private EntityDataExtractor&MockObject $dataExtractor;

    private AuditMetadataManagerInterface&MockObject $metadataManager;

    private ContextResolverInterface&MockObject $contextResolver;

    private EntityIdResolverInterface&MockObject $idResolver;

    private AuditService $service;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->clock = $this->createMock(ClockInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->transactionIdGenerator = $this->createMock(TransactionIdGenerator::class);
        $this->dataExtractor = $this->createMock(EntityDataExtractor::class);
        $this->metadataManager = $this->createMock(AuditMetadataManagerInterface::class);
        $this->contextResolver = $this->createMock(ContextResolverInterface::class);
        $this->idResolver = $this->createMock(EntityIdResolverInterface::class);

        $this->transactionIdGenerator->method('getTransactionId')->willReturn('tx1');
        $this->clock->method('now')->willReturn(new DateTimeImmutable('2023-01-01 12:00:00'));

        $this->service = new AuditService(
            $this->entityManager,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataManager,
            $this->contextResolver,
            $this->idResolver,
            $this->logger,
            'UTC',
            []
        );
    }

    public function testShouldAudit(): void
    {
        $this->metadataManager->method('isEntityIgnored')->willReturn(false);
        self::assertTrue($this->service->shouldAudit(new stdClass()));

        $this->metadataManager = $this->createMock(AuditMetadataManagerInterface::class);
        $this->metadataManager->method('isEntityIgnored')->willReturn(true);
        $service = new AuditService(
            $this->entityManager,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataManager,
            $this->contextResolver,
            $this->idResolver,
            null,
            'UTC',
            []
        );
        self::assertFalse($service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditWithVoters(): void
    {
        $this->metadataManager->method('isEntityIgnored')->willReturn(false);

        $voter1 = $this->createMock(AuditVoterInterface::class);
        $voter1->method('vote')->willReturn(true);

        $voter2 = $this->createMock(AuditVoterInterface::class);
        $voter2->method('vote')->willReturn(false);

        $service = new AuditService(
            $this->entityManager,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataManager,
            $this->contextResolver,
            $this->idResolver,
            null,
            'UTC',
            [$voter1, $voter2]
        );

        self::assertFalse($service->shouldAudit(new stdClass()));
    }

    public function testShouldAuditIgnored(): void
    {
        $this->metadataManager->method('isEntityIgnored')->willReturn(true);
        self::assertFalse($this->service->shouldAudit(new stdClass()));
    }

    public function testGetEntityData(): void
    {
        $entity = new stdClass();
        $this->dataExtractor->expects($this->once())->method('extract')->with($entity, [])->willReturn(['data']);
        self::assertEquals(['data'], $this->service->getEntityData($entity));
    }

    public function testCreateAuditLog(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['foo' => 'bar'],
        ]);

        $log = $this->service->createAuditLog($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 1], ['a' => 2]);

        self::assertEquals('1', $log->entityId);
        self::assertEquals(['a'], $log->changedFields);
        self::assertEquals('1', $log->userId);
        self::assertEquals(['foo' => 'bar'], $log->context);
    }

    public function testCreateAuditLogWithContext(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [
                'foo' => 'bar',
                'impersonation' => [
                    'impersonator_id' => '99',
                    'impersonator_username' => 'admin',
                ],
            ],
        ]);

        $log = $this->service->createAuditLog($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 1], ['a' => 2]);

        $context = $log->context;
        self::assertArrayHasKey('impersonation', $context);
        self::assertEquals('99', $context['impersonation']['impersonator_id']);
        self::assertEquals('admin', $context['impersonation']['impersonator_username']);
    }

    public function testCreateAuditLogWithContributors(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['custom_info' => 'custom_value'],
        ]);

        $log = $this->service->createAuditLog($entity, AuditLogInterface::ACTION_UPDATE, ['a' => 1], ['a' => 2]);

        $context = $log->context;
        self::assertArrayHasKey('custom_info', $context);
        self::assertEquals('custom_value', $context['custom_info']);
    }

    public function testCreateAuditLogWithCustomContext(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => ['manual_info' => 'manual_value'],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_UPDATE,
            ['a' => 1],
            ['a' => 2],
            ['manual_info' => 'manual_value']
        );

        $context = $log->context;
        self::assertArrayHasKey('manual_info', $context);
        self::assertEquals('manual_value', $context['manual_info']);
    }

    public function testCreateAuditLogPendingIdDelete(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn(AuditLogInterface::PENDING_ID);
        $this->idResolver->method('resolveFromValues')->willReturn('123');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_DELETE,
            ['id' => 123, 'data' => 'val'],
            null
        );

        self::assertEquals('123', $log->entityId);
    }

    public function testCreateAuditLogPendingIdDeleteComposite(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn(AuditLogInterface::PENDING_ID);
        $this->idResolver->method('resolveFromValues')->willReturn('["1","2"]');

        $this->contextResolver->method('resolve')->willReturn([
            'userId' => '1',
            'username' => 'user1',
            'ipAddress' => '127.0.0.1',
            'userAgent' => 'Agent',
            'context' => [],
        ]);

        $log = $this->service->createAuditLog(
            $entity,
            AuditLogInterface::ACTION_DELETE,
            ['id1' => 1, 'id2' => 2],
            null
        );

        self::assertEquals('["1","2"]', $log->entityId);
    }

    public function testEnrichUserContextExceptionEmittedWhenResolverFails(): void
    {
        $entity = new stdClass();
        $this->idResolver->method('resolveFromEntity')->willReturn('1');

        $this->contextResolver->method('resolve')->willThrowException(new Exception('User error'));
        // AuditService should catch and log errors from context resolver
        $this->logger->expects($this->once())->method('warning');

        $this->service->createAuditLog($entity, 'create');
    }

    public function testGetSensitiveFields(): void
    {
        $this->metadataManager->method('getSensitiveFields')->willReturn(['field' => 'mask']);
        self::assertEquals(['field' => 'mask'], $this->service->getSensitiveFields(new stdClass()));
    }

    public function testPassesVotersWithNoVoters(): void
    {
        self::assertTrue($this->service->passesVoters(new stdClass(), AuditLogInterface::ACTION_ACCESS));
    }

    public function testPassesVotersWithApprovingVoter(): void
    {
        $voter = $this->createMock(AuditVoterInterface::class);
        $voter->expects($this->once())->method('vote')
            ->with(self::isInstanceOf(stdClass::class), AuditLogInterface::ACTION_ACCESS, [])
            ->willReturn(true);

        $service = new AuditService(
            $this->entityManager,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataManager,
            $this->contextResolver,
            $this->idResolver,
            null,
            'UTC',
            [$voter]
        );

        self::assertTrue($service->passesVoters(new stdClass(), AuditLogInterface::ACTION_ACCESS));
    }

    public function testPassesVotersWithVetoingVoter(): void
    {
        $voter = $this->createMock(AuditVoterInterface::class);
        $voter->method('vote')->willReturn(false);

        $service = new AuditService(
            $this->entityManager,
            $this->clock,
            $this->transactionIdGenerator,
            $this->dataExtractor,
            $this->metadataManager,
            $this->contextResolver,
            $this->idResolver,
            null,
            'UTC',
            [$voter]
        );

        self::assertFalse($service->passesVoters(new stdClass(), AuditLogInterface::ACTION_ACCESS));
    }
}
