<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Exception;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogAiProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\AuditLogContextProcessor;
use Rcsofttech\AuditTrailBundle\Service\ContextSanitizer;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use stdClass;
use Stringable;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function fclose;
use function str_repeat;
use function tmpfile;

#[AllowMockObjectsWithoutExpectations]
final class AuditDispatcherTest extends TestCase
{
    /** @var (AuditTransportInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditTransportInterface&MockObject) */
    private AuditTransportInterface $transport;

    /** @var DataMaskerInterface&\PHPUnit\Framework\MockObject\Stub */
    private DataMaskerInterface $dataMasker;

    /** @var (EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(EntityManagerInterface&MockObject) */
    private EntityManagerInterface $em;

    private AuditLog $audit;

    protected function setUp(): void
    {
        $this->transport = self::createStub(AuditTransportInterface::class);
        $this->dataMasker = self::createStub(DataMaskerInterface::class);
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->audit = new AuditLog('App\Entity\Post', '123', 'update');
        $this->dataMasker->method('redact')->willReturnCallback(static fn (array $context): array => $context);
    }

    /** @return AuditTransportInterface&MockObject */
    private function useTransportMock(): AuditTransportInterface
    {
        $transport = self::createMock(AuditTransportInterface::class);
        $this->transport = $transport;

        return $transport;
    }

    /** @return EventDispatcherInterface&MockObject */
    private function useEventDispatcherMock(): EventDispatcherInterface
    {
        return self::createMock(EventDispatcherInterface::class);
    }

    /** @return AuditIntegrityServiceInterface&MockObject */
    private function useIntegrityServiceMock(): AuditIntegrityServiceInterface
    {
        return self::createMock(AuditIntegrityServiceInterface::class);
    }

    /** @return LoggerInterface&MockObject */
    private function useLoggerMock(): LoggerInterface
    {
        return self::createMock(LoggerInterface::class);
    }

    /** @return EntityManagerInterface&MockObject */
    private function useEntityManagerMock(): EntityManagerInterface
    {
        $em = self::createMock(EntityManagerInterface::class);
        $this->em = $em;

        return $em;
    }

    /** @return AuditLogAiProcessorInterface&MockObject */
    private function useAiProcessorMock(): AuditLogAiProcessorInterface
    {
        return self::createMock(AuditLogAiProcessorInterface::class);
    }

    /**
     * @param iterable<AuditLogAiProcessorInterface>|null $aiProcessors
     */
    private function createDispatcher(
        ?AuditTransportInterface $transport = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?AuditIntegrityServiceInterface $integrityService = null,
        ?DataMaskerInterface $dataMasker = null,
        ?LoggerInterface $logger = null,
        bool $failOnTransportError = false,
        bool $fallbackToDatabase = true,
        ?iterable $aiProcessors = null,
        ?AuditLogWriterInterface $auditLogWriter = null,
    ): AuditDispatcher {
        $contextProcessor = new AuditLogContextProcessor(
            new ContextSanitizer(),
            $dataMasker ?? $this->dataMasker,
            $logger,
            $aiProcessors ?? [],
        );

        return new AuditDispatcher(
            $transport ?? $this->transport,
            $contextProcessor,
            $auditLogWriter ?? self::createStub(AuditLogWriterInterface::class),
            $eventDispatcher,
            $integrityService,
            $logger,
            $failOnTransportError,
            $fallbackToDatabase,
        );
    }

    public function testDispatchSuccess(): void
    {
        $transport = $this->useTransportMock();
        $eventDispatcher = $this->useEventDispatcherMock();
        $dispatcher = $this->createDispatcher($transport, $eventDispatcher);
        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (AuditLogCreatedEvent $event) {
                return $event;
            });

        $dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush);
    }

    public function testDispatchPassesEntityToCreatedEvent(): void
    {
        $transport = $this->useTransportMock();
        $eventDispatcher = $this->useEventDispatcherMock();
        $dispatcher = $this->createDispatcher($transport, $eventDispatcher);
        $entity = new stdClass();

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(static fn (AuditLogCreatedEvent $event): bool => $event->entity === $entity))
            ->willReturnCallback(static fn (AuditLogCreatedEvent $event): AuditLogCreatedEvent => $event);

        $dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush, null, $entity);
    }

    public function testDispatchRedactsEventMutationsBeforeSigningAndSending(): void
    {
        $transport = $this->useTransportMock();
        $eventDispatcher = $this->useEventDispatcherMock();
        $integrityService = $this->useIntegrityServiceMock();
        $dispatcher = $this->createDispatcher($transport, $eventDispatcher, $integrityService, new DataMasker());

        $transport->method('supports')->willReturn(true);
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (AuditLogCreatedEvent $event): AuditLogCreatedEvent {
                $event->auditLog->context = ['event_secret' => 'raw-token'];

                return $event;
            });
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())
            ->method('generateSignature')
            ->with(self::callback(static fn (AuditLog $audit): bool => ($audit->context['event_secret'] ?? null) === '********'))
            ->willReturn('sig');
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static fn (AuditTransportContext $context): bool => ($context->audit->context['event_secret'] ?? null) === '********')
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
        self::assertSame('********', $this->audit->context['event_secret'] ?? null);
        self::assertSame('sig', $this->audit->signature);
    }

    public function testDispatchPassesContextToTransportSupportCheck(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = $this->createDispatcher($transport);
        $entity = new stdClass();
        $uow = self::createStub(UnitOfWork::class);
        $em = $this->em;
        $audit = $this->audit;

        $transport->expects($this->once())
            ->method('supports')
            ->with(self::callback(static function (AuditTransportContext $context) use ($audit, $em, $entity, $uow): bool {
                return $context->phase === AuditPhase::OnFlush
                    && $context->entityManager === $em
                    && $context->unitOfWork === $uow
                    && $context->entity === $entity
                    && $context->audit === $audit;
            }))
            ->willReturn(false);
        $transport->expects($this->never())->method('send');

        self::assertFalse($dispatcher->dispatch($this->audit, $this->em, AuditPhase::OnFlush, $uow, $entity));
    }

    public function testDispatchSignsLogWhenIntegrityEnabled(): void
    {
        $transport = $this->useTransportMock();
        $integrityService = $this->useIntegrityServiceMock();
        $dispatcher = $this->createDispatcher($transport, null, $integrityService);

        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())
            ->method('generateSignature')
            ->with($this->audit)
            ->willReturn('test_signature');

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');

        $dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush);
        self::assertSame('test_signature', $this->audit->signature);
    }

    public function testDispatchAppliesAiProcessorsBeforeSigning(): void
    {
        $transport = $this->useTransportMock();
        $integrityService = $this->useIntegrityServiceMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = $this->createDispatcher($transport, null, $integrityService, null, null, false, true, [$aiProcessor]);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $aiProcessor->expects($this->once())
            ->method('process')
            ->with($this->audit->context, null)
            ->willReturn(['summary' => 'Potential risk']);
        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())
            ->method('generateSignature')
            ->with(self::callback(static fn (AuditLog $audit): bool => ($audit->context['ai']['summary'] ?? null) === 'Potential risk'))
            ->willReturn('sig');

        $dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush);

        self::assertSame('Potential risk', $this->audit->context['ai']['summary'] ?? null);
    }

    public function testDispatchIgnoresAiProcessorFailures(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, $logger, false, true, [$aiProcessor]);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $aiProcessor->expects($this->once())
            ->method('process')
            ->willThrowException(new Exception('AI unavailable'));
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit AI processor'));

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
    }

    public function testDispatchSkipsAiProcessorsDuringOnFlush(): void
    {
        $transport = $this->useTransportMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, null, false, true, [$aiProcessor]);
        $uow = self::createStub(UnitOfWork::class);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $aiProcessor->expects($this->never())->method('process');

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::OnFlush, $uow));
    }

    public function testDispatchRedactsAiContextBeforeSending(): void
    {
        $transport = $this->useTransportMock();
        $aiProcessor = self::createStub(AuditLogAiProcessorInterface::class);
        $dispatcher = $this->createDispatcher($transport, null, null, new DataMasker(), null, false, true, [$aiProcessor]);

        $transport->method('supports')->willReturn(true);
        $aiProcessor->method('process')
            ->willReturn(['ai_secret' => 'raw-token']);
        $transport->expects($this->once())->method('send');

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
        self::assertSame('********', $this->audit->context['ai']['ai_secret'] ?? null);
    }

    public function testDispatchSanitizesNonJsonSafeEventContextBeforeSigningAndSending(): void
    {
        $resource = tmpfile();
        self::assertIsResource($resource);

        try {
            $transport = $this->useTransportMock();
            $eventDispatcher = $this->useEventDispatcherMock();
            $integrityService = $this->useIntegrityServiceMock();
            $dispatcher = $this->createDispatcher($transport, $eventDispatcher, $integrityService);

            $transport->method('supports')->willReturn(true);
            $eventDispatcher->expects($this->once())
                ->method('dispatch')
                ->willReturnCallback(static function (AuditLogCreatedEvent $event) use ($resource): AuditLogCreatedEvent {
                    $event->auditLog->context = ['stream' => $resource];

                    return $event;
                });
            $integrityService->method('isEnabled')->willReturn(true);
            $integrityService->expects($this->once())
                ->method('generateSignature')
                ->with(self::callback(static fn (AuditLog $audit): bool => ($audit->context['stream'] ?? null) === '[resource:stream]'))
                ->willReturn('sig');
            $transport->expects($this->once())
                ->method('send')
                ->with(
                    self::callback(static fn (AuditTransportContext $context): bool => ($context->audit->context['stream'] ?? null) === '[resource:stream]'),
                );

            self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
            self::assertSame('[resource:stream]', $this->audit->context['stream'] ?? null);
        } finally {
            fclose($resource);
        }
    }

    public function testDispatchTruncatesOnlyAiContextWhenPayloadExceedsLimit(): void
    {
        $this->audit->context = ['request_id' => 'req-123'];

        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, $logger, false, true, [$aiProcessor]);

        $transport->method('supports')->willReturn(true);
        $aiProcessor->expects($this->once())
            ->method('process')
            ->willReturn(['summary' => str_repeat('x', 70_000)]);
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('AI metadata'));
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static fn (AuditTransportContext $context): bool => ($context->audit->context['request_id'] ?? null) === 'req-123'
                    && ($context->audit->context['_ai_truncated'] ?? null) === true
                    && !isset($context->audit->context['ai'])),
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
        self::assertSame('req-123', $this->audit->context['request_id'] ?? null);
        self::assertTrue($this->audit->context['_ai_truncated'] ?? false);
        self::assertArrayNotHasKey('ai', $this->audit->context);
    }

    public function testDispatchTransportFailureLogsError(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, $logger);
        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));

        $logger->expects($this->once())
            ->method('error')
            ->with(self::stringContains('Audit transport failed'));

        $dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush);
    }

    public function testDispatchTransportFailureWithException(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, null, true);
        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transport error');

        $dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush);
    }

    public function testDispatchTransportFailureDuringOnFlushUsesUnitOfWorkFallbackWithoutNestedFlush(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $dispatcher = $this->createDispatcher($transport);
        $uow = self::createMock(UnitOfWork::class);
        $metadata = new ClassMetadata(AuditLog::class);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $em->expects($this->never())->method('flush');
        $em->expects($this->once())->method('getClassMetadata')->with(AuditLog::class)->willReturn($metadata);
        $uow->expects($this->once())->method('computeChangeSet')->with($metadata, $this->audit);

        self::assertTrue($dispatcher->dispatch($this->audit, $em, AuditPhase::OnFlush, $uow));
    }

    public function testDispatchTransportFailureDuringPostFlushDefersFlushFallback(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $auditLogWriter = self::createMock(AuditLogWriterInterface::class);
        $dispatcher = $this->createDispatcher($transport, null, null, null, null, false, true, [], $auditLogWriter);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->expects($this->never())->method('flush');
        $auditLogWriter->expects($this->once())->method('insert')->with($this->audit, $em);

        self::assertTrue($dispatcher->dispatch($this->audit, $em, AuditPhase::PostFlush));
    }

    public function testDispatchTransportFailureDuringPostLoadDefersFlushFallback(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $auditLogWriter = self::createMock(AuditLogWriterInterface::class);
        $dispatcher = $this->createDispatcher($transport, null, null, null, null, false, true, [], $auditLogWriter);
        $entity = new stdClass();

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->expects($this->never())->method('flush');
        $auditLogWriter->expects($this->once())->method('insert')->with($this->audit, $em);

        self::assertTrue($dispatcher->dispatch($this->audit, $em, AuditPhase::PostLoad, null, $entity));
    }

    public function testDispatchManualPhaseFallbackDoesNotImplicitlyFlush(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $logger = $this->useLoggerMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, $logger);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $em->expects($this->never())->method('flush');
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('without an implicit flush'));

        self::assertTrue($dispatcher->dispatch($this->audit, $em, AuditPhase::ManualFlush));
    }

    public function testDispatchTransportFailureWithoutDatabaseFallbackReturnsFalse(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, null, false, false);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));

        self::assertFalse($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
    }

    public function testDispatchFallsBackToSafeContextWhenMaskingFails(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $dataMasker = $this->createMock(DataMaskerInterface::class);
        $dispatcher = $this->createDispatcher($transport, null, null, $dataMasker, $logger);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static fn (AuditTransportContext $context): bool => ($context->audit->context['_context_safety_error'] ?? false) === true),
            );
        $dataMasker->expects($this->once())->method('redact')->willThrowException(new Exception('Masker failed'));
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit context safety failed'));

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
        self::assertTrue($this->audit->context['_context_safety_error'] ?? false);
    }

    public function testDispatchSanitizesDeepInvalidUtf8AndStringableContextValues(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = $this->createDispatcher($transport);
        $this->audit->context = [
            'invalid' => "\xB1\x31",
            'stringable' => new class implements Stringable {
                public function __toString(): string
                {
                    return 'stringified';
                }
            },
            'deep' => ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => 'value']]]]]],
        ];

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static function (AuditTransportContext $context): bool {
                    return ($context->audit->context['invalid'] ?? null) === '[invalid utf-8]'
                        && ($context->audit->context['stringable'] ?? null) === 'stringified'
                        && (($context->audit->context['deep']['a']['b']['c']['d'] ?? null) === ['_max_depth_reached' => true]);
                }),
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
    }

    public function testDispatchTruncatesOversizedNonAiContext(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, $logger);
        $this->audit->context = ['payload' => str_repeat('x', 70_000)];

        $transport->method('supports')->willReturn(true);
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit context for'));
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static fn (AuditTransportContext $context): bool => ($context->audit->context['_truncated'] ?? false) === true),
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, AuditPhase::PostFlush));
    }

    public function testDispatchOnFlushFallbackWithoutUnitOfWorkReturnsFalse(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $logger = $this->useLoggerMock();
        $dispatcher = $this->createDispatcher($transport, null, null, null, $logger);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $logger->expects($this->once())
            ->method('critical')
            ->with(self::stringContains('AUDIT LOSS'));

        self::assertFalse($dispatcher->dispatch($this->audit, $em, AuditPhase::OnFlush));
    }
}
