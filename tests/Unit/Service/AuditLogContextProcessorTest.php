<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogAiProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogReadModelAiProcessorInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Query\AuditLogReadModel;
use Rcsofttech\AuditTrailBundle\Service\AuditContextNormalizer;
use Rcsofttech\AuditTrailBundle\Service\AuditLogContextProcessor;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use stdClass;

final class AuditLogContextProcessorTest extends TestCase
{
    public function testPassesCompleteReadModelToReadModelAiProcessor(): void
    {
        $audit = $this->createAuditLog();
        $aiProcessor = new class implements AuditLogReadModelAiProcessorInterface {
            public ?AuditLogReadModel $audit = null;

            public function getNamespace(): string
            {
                return 'read_model_ai';
            }

            public function processAuditLog(AuditLogReadModel $audit): array
            {
                $this->audit = $audit;

                return [
                    'summary' => 'Read model processed',
                    'entity_id' => $audit->entityId,
                    'field_value' => $audit->newValues['title'] ?? null,
                ];
            }
        };

        $this->createProcessor([$aiProcessor])->prepare($audit, new stdClass(), AuditPhase::PostFlush);

        self::assertNotNull($aiProcessor->audit);
        self::assertSame('App\Entity\Post', $aiProcessor->audit->entityClass);
        self::assertSame('123', $aiProcessor->audit->entityId);
        self::assertSame('update', $aiProcessor->audit->action->value);
        self::assertSame(['title' => 'Old title'], $aiProcessor->audit->oldValues);
        self::assertSame(['title' => 'New title'], $aiProcessor->audit->newValues);
        self::assertSame(['title'], $aiProcessor->audit->changedFields);
        self::assertSame('tx-123', $aiProcessor->audit->transactionHash);
        self::assertSame('u-1', $aiProcessor->audit->userId);
        self::assertSame('alice', $aiProcessor->audit->username);
        self::assertSame('127.0.0.1', $aiProcessor->audit->ipAddress);
        self::assertSame('UnitAgent', $aiProcessor->audit->userAgent);
        self::assertSame(['app_context' => ['scenario' => 'unit']], $aiProcessor->audit->context);
        self::assertSame('Read model processed', $audit->context['ai']['read_model_ai']['summary'] ?? null);
        self::assertSame('New title', $audit->context['ai']['read_model_ai']['field_value'] ?? null);
    }

    public function testDuplicateProcessorObjectRunsOnlyOnceAndPrefersReadModelContract(): void
    {
        $audit = $this->createAuditLog();
        $aiProcessor = new class implements AuditLogReadModelAiProcessorInterface {
            public int $readModelCalls = 0;

            public function getNamespace(): string
            {
                return 'dual_ai';
            }

            public function processAuditLog(AuditLogReadModel $audit): array
            {
                ++$this->readModelCalls;

                return ['summary' => 'Read model processed'];
            }
        };

        $this->createProcessor([$aiProcessor, $aiProcessor])->prepare($audit, null, AuditPhase::PostFlush);

        self::assertSame(1, $aiProcessor->readModelCalls);
        self::assertSame('Read model processed', $audit->context['ai']['dual_ai']['summary'] ?? null);
    }

    public function testLegacyContextProcessorStillReceivesContextAndEntity(): void
    {
        $audit = $this->createAuditLog();
        $entity = new stdClass();
        $aiProcessor = new class implements AuditLogAiProcessorInterface {
            /** @var array<string, mixed>|null */
            public ?array $context = null;

            public ?object $entity = null;

            public function getNamespace(): string
            {
                return 'legacy_ai';
            }

            public function process(array $context, ?object $entity = null): array
            {
                $this->context = $context;
                $this->entity = $entity;

                return ['summary' => 'Legacy processed'];
            }
        };

        $this->createProcessor([$aiProcessor])->prepare($audit, $entity, AuditPhase::PostFlush);

        self::assertSame(['app_context' => ['scenario' => 'unit']], $aiProcessor->context);
        self::assertSame($entity, $aiProcessor->entity);
        self::assertSame('Legacy processed', $audit->context['ai']['legacy_ai']['summary'] ?? null);
    }

    /**
     * @param iterable<object> $aiProcessors
     */
    private function createProcessor(iterable $aiProcessors): AuditLogContextProcessor
    {
        return new AuditLogContextProcessor(
            new ContextSanitizer(),
            new AuditContextNormalizer(new ContextSanitizer()),
            aiProcessors: $aiProcessors,
        );
    }

    private function createAuditLog(): AuditLog
    {
        return new AuditLog(
            entityClass: 'App\Entity\Post',
            entityId: '123',
            action: 'update',
            createdAt: new DateTimeImmutable('2026-05-29 10:00:00'),
            oldValues: ['title' => 'Old title'],
            newValues: ['title' => 'New title'],
            changedFields: ['title'],
            transactionHash: 'tx-123',
            userId: 'u-1',
            username: 'alice',
            ipAddress: '127.0.0.1',
            userAgent: 'UnitAgent',
            context: ['app_context' => ['scenario' => 'unit']],
        );
    }
}
