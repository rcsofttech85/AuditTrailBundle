<?php

declare(strict_types=1);

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogInterface;
use Rcsofttech\AuditTrailBundle\Contract\AuditLogWriterInterface;
use Rcsofttech\AuditTrailBundle\Contract\EntityIdResolverInterface;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Enum\AuditPhase;
use Rcsofttech\AuditTrailBundle\Transport\AuditTransportContext;
use Rcsofttech\AuditTrailBundle\Transport\DoctrineAuditTransport;
use stdClass;

#[AllowMockObjectsWithoutExpectations]
final class DoctrineAuditTransportTest extends TestCase
{
    /** @var EntityIdResolverInterface&\PHPUnit\Framework\MockObject\Stub */
    private EntityIdResolverInterface $idResolver;

    /** @var AuditLogWriterInterface&\PHPUnit\Framework\MockObject\MockObject */
    private AuditLogWriterInterface $auditLogWriter;

    private DoctrineAuditTransport $transport;

    protected function setUp(): void
    {
        $this->idResolver = self::createStub(EntityIdResolverInterface::class);
        $this->auditLogWriter = $this->createMock(AuditLogWriterInterface::class);
        $this->transport = new DoctrineAuditTransport($this->idResolver, $this->auditLogWriter);
    }

    public function testSendOnFlushPersistsLog(): void
    {
        $log = new AuditLog(stdClass::class, '123', AuditLogInterface::ACTION_CREATE);
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $meta = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);

        $em->expects($this->once())->method('persist')->with($log);
        $uow->expects($this->once())->method('computeChangeSet')->with($meta, $log);

        $this->transport->send(new AuditTransportContext(AuditPhase::OnFlush, $em, $log, $uow));
    }

    public function testSendPostFlushUpdatesId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $this->idResolver->method('resolve')->willReturn('100');
        $this->auditLogWriter->expects($this->once())->method('insert')->with($log, $em);

        $this->transport->send(new AuditTransportContext(AuditPhase::PostFlush, $em, $log, null, $entity));

        // The new implementation calls setEntityId instead of executeStatement
        self::assertSame('100', $log->entityId);
    }

    public function testSendPostFlushWithIsInsertUpdatesId(): void
    {
        $log = new AuditLog(stdClass::class, 'pending', AuditLogInterface::ACTION_CREATE);

        $entity = new stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $this->idResolver->method('resolve')->willReturn('456');
        $this->auditLogWriter->expects($this->once())->method('insert')->with($log, $em);

        $this->transport->send(new AuditTransportContext(AuditPhase::PostFlush, $em, $log, null, $entity));

        // Verify setEntityId was called with resolved ID
        self::assertSame('456', $log->entityId);
    }

    public function testSupportsOnFlushForResolvedEntityId(): void
    {
        $log = new AuditLog(stdClass::class, '123', AuditLogInterface::ACTION_CREATE);

        self::assertTrue($this->transport->supports(new AuditTransportContext(
            AuditPhase::OnFlush,
            self::createStub(EntityManagerInterface::class),
            $log,
        )));
    }

    public function testDoesNotSupportOnFlushForPendingEntityId(): void
    {
        $log = new AuditLog(stdClass::class, AuditLogInterface::PENDING_ID, AuditLogInterface::ACTION_CREATE);

        self::assertFalse($this->transport->supports(new AuditTransportContext(
            AuditPhase::OnFlush,
            self::createStub(EntityManagerInterface::class),
            $log,
        )));
    }
}
