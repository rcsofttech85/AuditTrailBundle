<?php

namespace Rcsofttech\AuditTrailBundle\Tests\Unit\Transport;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;
use Rcsofttech\AuditTrailBundle\Entity\AuditLog;
use Rcsofttech\AuditTrailBundle\Transport\DoctrineAuditTransport;

class DoctrineAuditTransportTest extends TestCase
{
    private DoctrineAuditTransport $transport;

    protected function setUp(): void
    {
        $this->transport = new DoctrineAuditTransport();
    }

    public function testSendOnFlushPersistsLog(): void
    {
        $log = new AuditLog();
        $em = $this->createMock(EntityManagerInterface::class);
        $uow = $this->createMock(UnitOfWork::class);
        $meta = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);

        $em->expects($this->once())->method('persist')->with($log);
        $uow->expects($this->once())->method('computeChangeSet')->with($meta, $log);

        $this->transport->send($log, [
            'phase' => 'on_flush',
            'em' => $em,
            'uow' => $uow,
        ]);
    }

    public function testSendPostFlushUpdatesId(): void
    {
        $log = new AuditLog();
        $log->setEntityId('pending');

        $entity = new \stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('contains')->willReturn(false);
        $meta->method('getIdentifierValues')->willReturn(['id' => 100]);

        $this->transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
        ]);

        // The new implementation calls setEntityId instead of executeStatement
        self::assertEquals('100', $log->getEntityId());
    }

    public function testSendPostFlushWithIsInsertUpdatesId(): void
    {
        $log = new AuditLog();
        $log->setEntityId('pending');

        $entity = new \stdClass();
        $em = self::createStub(EntityManagerInterface::class);
        $meta = self::createStub(ClassMetadata::class);

        $em->method('getClassMetadata')->willReturn($meta);
        $em->method('contains')->willReturn(true); // Already managed
        $meta->method('getIdentifierValues')->willReturn(['id' => 456]);

        $this->transport->send($log, [
            'phase' => 'post_flush',
            'em' => $em,
            'entity' => $entity,
            'is_insert' => true,
        ]);

        // Verify setEntityId was called with resolved ID
        self::assertEquals('456', $log->getEntityId());
    }
}
