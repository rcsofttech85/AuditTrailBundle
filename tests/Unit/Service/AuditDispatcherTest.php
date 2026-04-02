<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Service;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Exception;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditIntegrityServiceInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogAiProcessorInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditTransportInterface;
use Rcsofttech\AuditTrailBundle\Contract\DataMaskerInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Event\AuditLogCreatedEvent;
use Rcsofttech\AuditTrailBundle\Service\AuditDispatcher;
use Rcsofttech\AuditTrailBundle\Service\DataMasker;
use stdClass;
use Stringable;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function fclose;
use function str_repeat;
use function tmpfile;

final class AuditDispatcherTest extends TestCase
{
    /** @var (AuditTransportInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditTransportInterface&MockObject) */
    private AuditTransportInterface $transport;

    /** @var (EventDispatcherInterface&\PHPUnit\Framework\MockObject\Stub)|(EventDispatcherInterface&MockObject) */
    private EventDispatcherInterface $eventDispatcher;

    /** @var (AuditIntegrityServiceInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditIntegrityServiceInterface&MockObject) */
    private AuditIntegrityServiceInterface $integrityService;

    /** @var DataMaskerInterface&\PHPUnit\Framework\MockObject\Stub */
    private DataMaskerInterface $dataMasker;

    /** @var (LoggerInterface&\PHPUnit\Framework\MockObject\Stub)|(LoggerInterface&MockObject) */
    private LoggerInterface $logger;

    /** @var (EntityManagerInterface&\PHPUnit\Framework\MockObject\Stub)|(EntityManagerInterface&MockObject) */
    private EntityManagerInterface $em;

    /** @var (AuditLogAiProcessorInterface&\PHPUnit\Framework\MockObject\Stub)|(AuditLogAiProcessorInterface&MockObject) */
    private AuditLogAiProcessorInterface $aiProcessor;

    private AuditLog $audit;

    protected function setUp(): void
    {
        $this->transport = self::createStub(AuditTransportInterface::class);
        $this->eventDispatcher = self::createStub(EventDispatcherInterface::class);
        $this->integrityService = self::createStub(AuditIntegrityServiceInterface::class);
        $this->dataMasker = self::createStub(DataMaskerInterface::class);
        $this->logger = self::createStub(LoggerInterface::class);
        $this->em = self::createStub(EntityManagerInterface::class);
        $this->aiProcessor = self::createStub(AuditLogAiProcessorInterface::class);
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
        $eventDispatcher = self::createMock(EventDispatcherInterface::class);
        $this->eventDispatcher = $eventDispatcher;

        return $eventDispatcher;
    }

    /** @return AuditIntegrityServiceInterface&MockObject */
    private function useIntegrityServiceMock(): AuditIntegrityServiceInterface
    {
        $integrityService = self::createMock(AuditIntegrityServiceInterface::class);
        $this->integrityService = $integrityService;

        return $integrityService;
    }

    /** @return LoggerInterface&MockObject */
    private function useLoggerMock(): LoggerInterface
    {
        $logger = self::createMock(LoggerInterface::class);
        $this->logger = $logger;

        return $logger;
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
        $aiProcessor = self::createMock(AuditLogAiProcessorInterface::class);
        $this->aiProcessor = $aiProcessor;

        return $aiProcessor;
    }

    public function testDispatchSuccess(): void
    {
        $dispatcher = new AuditDispatcher($this->transport, $this->eventDispatcher, null, $this->dataMasker);
        $transport = $this->useTransportMock();
        $eventDispatcher = $this->useEventDispatcherMock();
        $dispatcher = new AuditDispatcher($transport, $eventDispatcher, null, $this->dataMasker);
        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->willReturnCallback(static function (AuditLogCreatedEvent $event) {
                return $event;
            });

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchPassesEntityToCreatedEvent(): void
    {
        $transport = $this->useTransportMock();
        $eventDispatcher = $this->useEventDispatcherMock();
        $dispatcher = new AuditDispatcher($transport, $eventDispatcher, null, $this->dataMasker);
        $entity = new stdClass();

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $eventDispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::callback(static fn (AuditLogCreatedEvent $event): bool => $event->entity === $entity))
            ->willReturnCallback(static fn (AuditLogCreatedEvent $event): AuditLogCreatedEvent => $event);

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush', null, $entity);
    }

    public function testDispatchRedactsEventMutationsBeforeSigningAndSending(): void
    {
        $dispatcher = new AuditDispatcher(
            $this->transport,
            $this->eventDispatcher,
            $this->integrityService,
            new DataMasker(),
        );
        $transport = $this->useTransportMock();
        $eventDispatcher = $this->useEventDispatcherMock();
        $integrityService = $this->useIntegrityServiceMock();
        $dispatcher = new AuditDispatcher(
            $transport,
            $eventDispatcher,
            $integrityService,
            new DataMasker(),
        );

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
                self::callback(static fn (AuditLog $audit): bool => ($audit->context['event_secret'] ?? null) === '********'),
                self::callback(static fn (array $context): bool => (($context['audit'] ?? null) instanceof AuditLog)
                    && (($context['audit']->context['event_secret'] ?? null) === '********'))
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
        self::assertSame('********', $this->audit->context['event_secret'] ?? null);
        self::assertSame('sig', $this->audit->signature);
    }

    public function testDispatchPassesContextToTransportSupportCheck(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker);
        $entity = new stdClass();
        $uow = self::createStub(UnitOfWork::class);

        $transport->expects($this->once())
            ->method('supports')
            ->with('on_flush', self::callback(static function (array $context) use ($entity, $uow): bool {
                return ($context['phase'] ?? null) === 'on_flush'
                    && ($context['em'] ?? null) instanceof EntityManagerInterface
                    && ($context['uow'] ?? null) === $uow
                    && ($context['entity'] ?? null) === $entity
                    && ($context['audit'] ?? null) instanceof AuditLog;
            }))
            ->willReturn(false);
        $transport->expects($this->never())->method('send');

        self::assertFalse($dispatcher->dispatch($this->audit, $this->em, 'on_flush', $uow, $entity));
    }

    public function testDispatchSignsLogWhenIntegrityEnabled(): void
    {
        $transport = $this->useTransportMock();
        $integrityService = $this->useIntegrityServiceMock();
        $dispatcher = new AuditDispatcher($transport, null, $integrityService, $this->dataMasker);

        $integrityService->method('isEnabled')->willReturn(true);
        $integrityService->expects($this->once())
            ->method('generateSignature')
            ->with($this->audit)
            ->willReturn('test_signature');

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
        self::assertSame('test_signature', $this->audit->signature);
    }

    public function testDispatchAppliesAiProcessorsBeforeSigning(): void
    {
        $dispatcher = new AuditDispatcher(
            $this->transport,
            null,
            $this->integrityService,
            $this->dataMasker,
            null,
            false,
            true,
            [$this->aiProcessor],
        );
        $transport = $this->useTransportMock();
        $integrityService = $this->useIntegrityServiceMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = new AuditDispatcher(
            $transport,
            null,
            $integrityService,
            $this->dataMasker,
            null,
            false,
            true,
            [$aiProcessor],
        );

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

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');

        self::assertSame('Potential risk', $this->audit->context['ai']['summary'] ?? null);
    }

    public function testDispatchIgnoresAiProcessorFailures(): void
    {
        $dispatcher = new AuditDispatcher(
            $this->transport,
            null,
            null,
            $this->dataMasker,
            $this->logger,
            false,
            true,
            [$this->aiProcessor],
        );
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = new AuditDispatcher(
            $transport,
            null,
            null,
            $this->dataMasker,
            $logger,
            false,
            true,
            [$aiProcessor],
        );

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $aiProcessor->expects($this->once())
            ->method('process')
            ->willThrowException(new Exception('AI unavailable'));
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit AI processor'));

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testDispatchSkipsAiProcessorsDuringOnFlush(): void
    {
        $dispatcher = new AuditDispatcher(
            $this->transport,
            null,
            null,
            $this->dataMasker,
            null,
            false,
            true,
            [$this->aiProcessor],
        );
        $transport = $this->useTransportMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = new AuditDispatcher(
            $transport,
            null,
            null,
            $this->dataMasker,
            null,
            false,
            true,
            [$aiProcessor],
        );
        $uow = self::createStub(UnitOfWork::class);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send');
        $aiProcessor->expects($this->never())->method('process');

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'on_flush', $uow));
    }

    public function testDispatchRedactsAiContextBeforeSending(): void
    {
        $dispatcher = new AuditDispatcher(
            $this->transport,
            null,
            null,
            new DataMasker(),
            null,
            false,
            true,
            [$this->aiProcessor],
        );
        $transport = $this->useTransportMock();
        $aiProcessor = self::createStub(AuditLogAiProcessorInterface::class);
        $this->aiProcessor = $aiProcessor;
        $dispatcher = new AuditDispatcher(
            $transport,
            null,
            null,
            new DataMasker(),
            null,
            false,
            true,
            [$aiProcessor],
        );

        $transport->method('supports')->willReturn(true);
        $aiProcessor->method('process')
            ->willReturn(['ai_secret' => 'raw-token']);
        $transport->expects($this->once())->method('send');

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
        self::assertSame('********', $this->audit->context['ai']['ai_secret'] ?? null);
    }

    public function testDispatchSanitizesNonJsonSafeEventContextBeforeSigningAndSending(): void
    {
        $resource = tmpfile();
        self::assertIsResource($resource);

        try {
            $dispatcher = new AuditDispatcher(
                $this->transport,
                $this->eventDispatcher,
                $this->integrityService,
                $this->dataMasker,
            );
            $transport = $this->useTransportMock();
            $eventDispatcher = $this->useEventDispatcherMock();
            $integrityService = $this->useIntegrityServiceMock();
            $dispatcher = new AuditDispatcher(
                $transport,
                $eventDispatcher,
                $integrityService,
                $this->dataMasker,
            );

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
                    self::callback(static fn (AuditLog $audit): bool => ($audit->context['stream'] ?? null) === '[resource:stream]'),
                    self::anything(),
                );

            self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
            self::assertSame('[resource:stream]', $this->audit->context['stream'] ?? null);
        } finally {
            fclose($resource);
        }
    }

    public function testDispatchTruncatesOnlyAiContextWhenPayloadExceedsLimit(): void
    {
        $this->audit->context = ['request_id' => 'req-123'];

        $dispatcher = new AuditDispatcher(
            $this->transport,
            null,
            null,
            $this->dataMasker,
            $this->logger,
            false,
            true,
            [$this->aiProcessor],
        );
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $aiProcessor = $this->useAiProcessorMock();
        $dispatcher = new AuditDispatcher(
            $transport,
            null,
            null,
            $this->dataMasker,
            $logger,
            false,
            true,
            [$aiProcessor],
        );

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
                self::callback(static fn (AuditLog $audit): bool => ($audit->context['request_id'] ?? null) === 'req-123'
                    && ($audit->context['_ai_truncated'] ?? null) === true
                    && !isset($audit->context['ai'])),
                self::anything(),
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
        self::assertSame('req-123', $this->audit->context['request_id'] ?? null);
        self::assertTrue($this->audit->context['_ai_truncated'] ?? false);
        self::assertArrayNotHasKey('ai', $this->audit->context);
    }

    public function testDispatchTransportFailureLogsError(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, $logger, false);
        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));

        $logger->expects($this->once())
            ->method('error')
            ->with(self::stringContains('Audit transport failed'));

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchTransportFailureWithException(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, null, true);
        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Transport error');

        $dispatcher->dispatch($this->audit, $this->em, 'post_flush');
    }

    public function testDispatchTransportFailureDuringOnFlushUsesUnitOfWorkFallbackWithoutNestedFlush(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, null, false, true);
        $uow = self::createMock(UnitOfWork::class);
        $metadata = new ClassMetadata(AuditLog::class);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $em->expects($this->never())->method('flush');
        $em->expects($this->once())->method('getClassMetadata')->with(AuditLog::class)->willReturn($metadata);
        $uow->expects($this->once())->method('computeChangeSet')->with($metadata, $this->audit);

        self::assertTrue($dispatcher->dispatch($this->audit, $em, 'on_flush', $uow));
    }

    public function testDispatchTransportFailureDuringPostFlushDefersFlushFallback(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, null, false, true);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $em->expects($this->never())->method('flush');

        self::assertTrue($dispatcher->dispatch($this->audit, $em, 'post_flush'));
    }

    public function testDispatchTransportFailureDuringPostLoadDefersFlushFallback(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, null, false, true);
        $entity = new stdClass();

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $em->expects($this->never())->method('flush');

        self::assertTrue($dispatcher->dispatch($this->audit, $em, 'post_load', null, $entity));
    }

    public function testDispatchManualPhaseFallbackDoesNotImplicitlyFlush(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $logger = $this->useLoggerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, $logger, false, true);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $em->expects($this->never())->method('flush');
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('without an implicit flush'));

        self::assertTrue($dispatcher->dispatch($this->audit, $em, 'manual_flush'));
    }

    public function testDispatchTransportFailureWithoutDatabaseFallbackReturnsFalse(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, null, false, false);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));

        self::assertFalse($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testDispatchFallsBackToSafeContextWhenMaskingFails(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $dataMasker = $this->createMock(DataMaskerInterface::class);
        $dispatcher = new AuditDispatcher($transport, null, null, $dataMasker, $logger);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static fn (AuditLog $audit): bool => ($audit->context['_context_safety_error'] ?? false) === true),
                self::anything(),
            );
        $dataMasker->expects($this->once())->method('redact')->willThrowException(new Exception('Masker failed'));
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit context safety failed'));

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
        self::assertTrue($this->audit->context['_context_safety_error'] ?? false);
    }

    public function testDispatchSanitizesDeepInvalidUtf8AndStringableContextValues(): void
    {
        $transport = $this->useTransportMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker);
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
                self::callback(static function (AuditLog $audit): bool {
                    return ($audit->context['invalid'] ?? null) === '[invalid utf-8]'
                        && ($audit->context['stringable'] ?? null) === 'stringified'
                        && (($audit->context['deep']['a']['b']['c']['d'] ?? null) === ['_max_depth_reached' => true]);
                }),
                self::anything(),
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testDispatchTruncatesOversizedNonAiContext(): void
    {
        $transport = $this->useTransportMock();
        $logger = $this->useLoggerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, $logger);
        $this->audit->context = ['payload' => str_repeat('x', 70_000)];

        $transport->method('supports')->willReturn(true);
        $logger->expects($this->once())
            ->method('warning')
            ->with(self::stringContains('Audit context for'));
        $transport->expects($this->once())
            ->method('send')
            ->with(
                self::callback(static fn (AuditLog $audit): bool => ($audit->context['_truncated'] ?? false) === true),
                self::anything(),
            );

        self::assertTrue($dispatcher->dispatch($this->audit, $this->em, 'post_flush'));
    }

    public function testDispatchOnFlushFallbackWithoutUnitOfWorkReturnsFalse(): void
    {
        $transport = $this->useTransportMock();
        $em = $this->useEntityManagerMock();
        $logger = $this->useLoggerMock();
        $dispatcher = new AuditDispatcher($transport, null, null, $this->dataMasker, $logger);

        $transport->method('supports')->willReturn(true);
        $transport->expects($this->once())->method('send')->willThrowException(new Exception('Transport error'));
        $em->method('contains')->with($this->audit)->willReturn(false);
        $em->expects($this->once())->method('persist')->with($this->audit);
        $logger->expects($this->once())
            ->method('critical')
            ->with(self::stringContains('AUDIT LOSS'));

        self::assertFalse($dispatcher->dispatch($this->audit, $em, 'on_flush'));
    }
}
